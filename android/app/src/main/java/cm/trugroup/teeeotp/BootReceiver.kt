package cm.trugroup.teeeotp

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Relance la passerelle apres un redemarrage du telephone ou une mise a jour de l'appli. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val prefs = Prefs(context)
        if (prefs.enabled && prefs.isConfigured) {
            GatewayService.start(context)
        }
    }
}
