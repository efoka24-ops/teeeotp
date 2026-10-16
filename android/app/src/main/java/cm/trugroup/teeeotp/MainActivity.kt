package cm.trugroup.teeeotp

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.PowerManager
import android.provider.Settings
import android.text.format.DateUtils
import android.widget.Button
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast

/** Ecran unique : reglages, demarrage/arret, et etat de la passerelle. */
class MainActivity : Activity() {

    private lateinit var prefs: Prefs
    private lateinit var serverUrl: EditText
    private lateinit var token: EditText
    private lateinit var status: TextView
    private lateinit var toggle: Button
    private val handler = Handler(Looper.getMainLooper())

    private val refresh = object : Runnable {
        override fun run() {
            renderStatus()
            handler.postDelayed(this, 2000)
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)
        prefs = Prefs(this)

        serverUrl = findViewById(R.id.serverUrl)
        token = findViewById(R.id.token)
        status = findViewById(R.id.status)
        toggle = findViewById(R.id.toggle)

        serverUrl.setText(prefs.serverUrl)
        token.setText(prefs.deviceToken)

        toggle.setOnClickListener { onToggle() }
        findViewById<Button>(R.id.battery).setOnClickListener { askBatteryExemption() }
    }

    override fun onResume() {
        super.onResume()
        handler.post(refresh)
    }

    override fun onPause() {
        handler.removeCallbacks(refresh)
        super.onPause()
    }

    private fun onToggle() {
        if (GatewayService.running || prefs.enabled) {
            prefs.enabled = false
            startService(Intent(this, GatewayService::class.java).setAction(GatewayService.ACTION_STOP))
            renderStatus()
            return
        }

        prefs.serverUrl = serverUrl.text.toString()
        prefs.deviceToken = token.text.toString()
        if (!prefs.isConfigured) {
            toast("L'URL doit commencer par https:// et le jeton par dv_")
            return
        }

        val missing = requiredPermissions().filter {
            checkSelfPermission(it) != PackageManager.PERMISSION_GRANTED
        }
        if (missing.isNotEmpty()) {
            requestPermissions(missing.toTypedArray(), 1)
            return
        }

        prefs.enabled = true
        GatewayService.start(this)
        renderStatus()
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, results: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, results)
        if (checkSelfPermission(Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED) {
            onToggle()
        } else {
            toast("Sans la permission SMS, la passerelle ne peut rien envoyer.")
        }
    }

    private fun requiredPermissions(): List<String> = buildList {
        add(Manifest.permission.SEND_SMS)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) add(Manifest.permission.POST_NOTIFICATIONS)
    }

    /** Sans cette exemption, certaines surcouches (Tecno, Infinix, Xiaomi) tuent le service. */
    @SuppressLint("BatteryLife")
    private fun askBatteryExemption() {
        val pm = getSystemService(PowerManager::class.java)
        if (pm.isIgnoringBatteryOptimizations(packageName)) {
            toast("Deja exemptee de l'optimisation de batterie.")
            return
        }
        startActivity(
            Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:$packageName"))
        )
    }

    private fun renderStatus() {
        val actif = GatewayService.running
        toggle.text = if (actif || prefs.enabled) "Arreter la passerelle" else "Demarrer la passerelle"

        val poll = GatewayService.lastPoll
        val vu = if (poll == 0L) "jamais" else DateUtils.getRelativeTimeSpanString(poll).toString()
        val batterie = if (getSystemService(PowerManager::class.java).isIgnoringBatteryOptimizations(packageName))
            "exemptee" else "NON exemptee (a corriger)"

        status.text = buildString {
            appendLine("Etat : ${if (actif) "ACTIVE" else "arretee"}")
            appendLine("Dernier contact serveur : $vu")
            appendLine("Envoyes depuis le demarrage : ${GatewayService.sentCount.get()}")
            appendLine("Echecs : ${GatewayService.failedCount.get()}")
            appendLine("Optimisation batterie : $batterie")
            GatewayService.lastError?.let { appendLine("Derniere erreur : $it") }
        }
    }

    private fun toast(text: String) = Toast.makeText(this, text, Toast.LENGTH_LONG).show()
}
