package cm.trugroup.teeeotp

import android.app.Activity
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.ServiceInfo
import android.os.BatteryManager
import android.os.Build
import android.os.IBinder
import android.os.PowerManager
import android.telephony.SmsManager
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.ConcurrentLinkedQueue
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger

/**
 * Le demon de la passerelle.
 *
 * Boucle : reserver des SMS aupres du serveur, les envoyer avec la SIM,
 * attendre le verdict du reseau, rendre compte. Les accuses de reception
 * arrivent plus tard et sont transmis au tour suivant.
 *
 * Un seul fil d'execution, volontairement : les SMS partent un par un, espaces
 * de `sendDelayMs`, ce qui evite que l'operateur prenne la SIM pour un
 * spammeur.
 */
class GatewayService : Service() {

    companion object {
        const val ACTION_STOP = "cm.trugroup.teeeotp.STOP"
        private const val ACTION_SENT = "cm.trugroup.teeeotp.SMS_SENT"
        private const val ACTION_DELIVERED = "cm.trugroup.teeeotp.SMS_DELIVERED"
        private const val CHANNEL = "gateway"
        private const val NOTIF_ID = 1
        private const val SENT_TIMEOUT_S = 60L

        /** Etat expose a l'ecran principal. */
        @Volatile var running = false
        @Volatile var lastPoll: Long = 0
        @Volatile var lastError: String? = null
        val sentCount = AtomicInteger(0)
        val failedCount = AtomicInteger(0)

        fun start(context: Context) {
            val intent = Intent(context, GatewayService::class.java)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) context.startForegroundService(intent)
            else context.startService(intent)
        }
    }

    private var worker: Thread? = null
    private var wakeLock: PowerManager.WakeLock? = null
    private val requestCodes = AtomicInteger(1)

    /** Comptes rendus en attente d'envoi au serveur ; conserves si le reseau tombe. */
    private val pendingReports = ConcurrentLinkedQueue<Report>()

    /** SMS en cours : id -> parties restantes et premier echec eventuel. */
    private class Inflight(parts: Int) {
        val latch = CountDownLatch(parts)
        @Volatile var error: String? = null
    }
    private val inflight = ConcurrentHashMap<String, Inflight>()

    private val smsReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val id = intent.getStringExtra("id") ?: return
            when (intent.action) {
                ACTION_SENT -> {
                    val sms = inflight[id] ?: return
                    if (resultCode != Activity.RESULT_OK && sms.error == null) {
                        sms.error = describeSendError(resultCode)
                    }
                    sms.latch.countDown()
                }
                ACTION_DELIVERED -> pendingReports.add(Report(id, "delivered"))
            }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        val filter = IntentFilter().apply {
            addAction(ACTION_SENT)
            addAction(ACTION_DELIVERED)
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(smsReceiver, filter, Context.RECEIVER_NOT_EXPORTED)
        } else {
            @Suppress("UnspecifiedRegisterReceiverFlag")
            registerReceiver(smsReceiver, filter)
        }
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            Prefs(this).enabled = false
            stopSelf()
            return START_NOT_STICKY
        }

        val prefs = Prefs(this)
        startInForeground("Demarrage…")

        if (!prefs.isConfigured) {
            lastError = "Configuration incomplete (URL ou jeton)."
            stopSelf()
            return START_NOT_STICKY
        }

        if (worker?.isAlive != true) {
            // Le telephone est dedie et branche : garder le CPU eveille est
            // le prix d'un envoi immediat, ecran eteint.
            wakeLock = (getSystemService(POWER_SERVICE) as PowerManager)
                .newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "teeeotp:gateway")
                .apply { acquire() }

            val api = Api(prefs.serverUrl, prefs.deviceToken)
            worker = Thread({ loop(api) }, "teeeotp-gateway").apply { start() }
        }
        return START_STICKY
    }

    override fun onDestroy() {
        running = false
        worker?.interrupt()
        worker = null
        runCatching { unregisterReceiver(smsReceiver) }
        wakeLock?.takeIf { it.isHeld }?.release()
        super.onDestroy()
    }

    private fun loop(api: Api) {
        running = true
        var backoffMs = 3000L

        while (!Thread.currentThread().isInterrupted) {
            var pause: Long
            try {
                flushReports(api)

                val (battery, charging) = batteryState()
                val claim = api.claim(limit = 5, battery = battery, charging = charging)
                lastPoll = System.currentTimeMillis()
                lastError = null
                backoffMs = 3000L

                for (sms in claim.messages) {
                    if (Thread.currentThread().isInterrupted) break
                    pendingReports.add(send(sms))
                    flushReports(api)
                    Thread.sleep(claim.sendDelayMs)
                }

                updateNotification()
                // File videe : on attend le rythme impose par le serveur.
                // File encore pleine : on enchaine aussitot.
                pause = if (claim.messages.isEmpty()) claim.pollAfterMs else 0L
            } catch (e: InterruptedException) {
                break
            } catch (e: ApiException) {
                lastError = e.message
                if (e.httpStatus == 401) {
                    // Jeton revoque ou invalide : inutile d'insister.
                    updateNotification("Jeton refuse par le serveur")
                    Prefs(this).enabled = false
                    stopSelf()
                    break
                }
                pause = backoffMs
                backoffMs = (backoffMs * 2).coerceAtMost(60_000L)
            } catch (e: Exception) {
                // Reseau coupe, serveur injoignable : on reessaie en
                // espacant, sans jamais abandonner.
                lastError = e.message ?: e.javaClass.simpleName
                updateNotification("Hors ligne : ${lastError}")
                pause = backoffMs
                backoffMs = (backoffMs * 2).coerceAtMost(60_000L)
            }

            try {
                if (pause > 0) Thread.sleep(pause)
            } catch (e: InterruptedException) {
                break
            }
        }
        running = false
    }

    /** Envoie un SMS et attend le verdict du reseau. */
    private fun send(sms: OutgoingSms): Report {
        val manager: SmsManager = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            getSystemService(SmsManager::class.java)
        } else {
            @Suppress("DEPRECATION")
            SmsManager.getDefault()
        }

        val parts = manager.divideMessage(sms.body)
        val state = Inflight(parts.size)
        inflight[sms.id] = state

        return try {
            val sentIntents = ArrayList(parts.map { pending(ACTION_SENT, sms.id) })
            // Un seul accuse de reception suffit : celui de la derniere partie.
            val deliveryIntents = ArrayList(parts.indices.map { i ->
                if (i == parts.lastIndex) pending(ACTION_DELIVERED, sms.id) else null
            })

            if (parts.size == 1) {
                manager.sendTextMessage(sms.phone, null, sms.body, sentIntents[0], deliveryIntents[0])
            } else {
                manager.sendMultipartTextMessage(sms.phone, null, parts, sentIntents, deliveryIntents)
            }

            if (!state.latch.await(SENT_TIMEOUT_S, TimeUnit.SECONDS)) {
                failedCount.incrementAndGet()
                Report(sms.id, "failed", "pas de reponse du reseau en ${SENT_TIMEOUT_S}s")
            } else if (state.error != null) {
                failedCount.incrementAndGet()
                Report(sms.id, "failed", state.error)
            } else {
                sentCount.incrementAndGet()
                Report(sms.id, "sent")
            }
        } catch (e: InterruptedException) {
            throw e
        } catch (e: Exception) {
            failedCount.incrementAndGet()
            Report(sms.id, "failed", e.message ?: e.javaClass.simpleName)
        } finally {
            inflight.remove(sms.id)
        }
    }

    private fun pending(action: String, id: String): PendingIntent =
        PendingIntent.getBroadcast(
            this,
            requestCodes.getAndIncrement(),
            Intent(action).setPackage(packageName).putExtra("id", id),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_ONE_SHOT,
        )

    /** Envoie les comptes rendus accumules ; ceux qui echouent restent en file. */
    private fun flushReports(api: Api) {
        if (pendingReports.isEmpty()) return
        val batch = generateSequence { pendingReports.poll() }.take(50).toList()
        try {
            api.report(batch)
        } catch (e: Exception) {
            pendingReports.addAll(batch)
            throw e
        }
    }

    private fun batteryState(): Pair<Int?, Boolean?> {
        val status = registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED)) ?: return null to null
        val level = status.getIntExtra(BatteryManager.EXTRA_LEVEL, -1)
        val scale = status.getIntExtra(BatteryManager.EXTRA_SCALE, -1)
        val plugged = status.getIntExtra(BatteryManager.EXTRA_PLUGGED, 0)
        val pct = if (level >= 0 && scale > 0) level * 100 / scale else null
        return pct to (plugged != 0)
    }

    private fun describeSendError(code: Int): String = when (code) {
        SmsManager.RESULT_ERROR_GENERIC_FAILURE -> "echec generique (credit ou forfait epuise ?)"
        SmsManager.RESULT_ERROR_NO_SERVICE -> "pas de reseau"
        SmsManager.RESULT_ERROR_RADIO_OFF -> "mode avion ou radio coupee"
        SmsManager.RESULT_ERROR_NULL_PDU -> "message invalide"
        SmsManager.RESULT_ERROR_LIMIT_EXCEEDED -> "limite d'envoi Android atteinte"
        SmsManager.RESULT_ERROR_SHORT_CODE_NOT_ALLOWED -> "numero court refuse"
        else -> "erreur radio $code"
    }

    // ─── Notification permanente ────────────────────────────────────────────

    private fun startInForeground(text: String) {
        val nm = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            nm.createNotificationChannel(
                NotificationChannel(CHANNEL, "Passerelle SMS", NotificationManager.IMPORTANCE_LOW)
            )
        }
        val notification = buildNotification(text)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(NOTIF_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        } else {
            startForeground(NOTIF_ID, notification)
        }
    }

    private fun updateNotification(text: String? = null) {
        val line = text ?: "Active — ${sentCount.get()} envoyes, ${failedCount.get()} echecs"
        getSystemService(NotificationManager::class.java).notify(NOTIF_ID, buildNotification(line))
    }

    private fun buildNotification(text: String): Notification {
        val open = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE
        )
        val builder = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Notification.Builder(this, CHANNEL)
        } else {
            @Suppress("DEPRECATION")
            Notification.Builder(this)
        }
        return builder
            .setSmallIcon(android.R.drawable.sym_action_chat)
            .setContentTitle("teeeotp — passerelle SMS")
            .setContentText(text)
            .setContentIntent(open)
            .setOngoing(true)
            .build()
    }
}
