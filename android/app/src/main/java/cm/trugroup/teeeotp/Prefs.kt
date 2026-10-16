package cm.trugroup.teeeotp

import android.content.Context

/** Reglages du telephone : URL du serveur, jeton, et etat voulu du service. */
class Prefs(context: Context) {
    private val sp = context.getSharedPreferences("teeeotp", Context.MODE_PRIVATE)

    var serverUrl: String
        get() = sp.getString("serverUrl", BuildConfig.DEFAULT_SERVER_URL)!!
        set(v) = sp.edit().putString("serverUrl", v.trim().trimEnd('/')).apply()

    var deviceToken: String
        get() = sp.getString("deviceToken", "")!!
        set(v) = sp.edit().putString("deviceToken", v.trim()).apply()

    /** Vrai si l'utilisateur a demarre la passerelle : sert au redemarrage apres reboot. */
    var enabled: Boolean
        get() = sp.getBoolean("enabled", false)
        set(v) = sp.edit().putBoolean("enabled", v).apply()

    val isConfigured: Boolean
        get() = serverUrl.startsWith("https://") && deviceToken.startsWith("dv_")
}
