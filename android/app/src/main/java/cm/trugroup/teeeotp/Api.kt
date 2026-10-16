package cm.trugroup.teeeotp

import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL

data class OutgoingSms(val id: String, val phone: String, val body: String)

data class ClaimResult(val messages: List<OutgoingSms>, val pollAfterMs: Long, val sendDelayMs: Long)

data class Report(val id: String, val status: String, val error: String? = null)

class ApiException(val httpStatus: Int, message: String) : IOException(message)

/** Client HTTP du serveur teeeotp. Appels bloquants : a utiliser hors du thread principal. */
class Api(private val baseUrl: String, private val token: String) {

    fun claim(limit: Int, battery: Int?, charging: Boolean?): ClaimResult {
        val body = JSONObject()
            .put("limit", limit)
            .put("appVersion", BuildConfig.VERSION_NAME)
        if (battery != null) body.put("battery", battery)
        if (charging != null) body.put("charging", charging)

        val json = post("/v1/gateway/claim", body)
        val arr = json.optJSONArray("messages") ?: JSONArray()
        val messages = (0 until arr.length()).map {
            val m = arr.getJSONObject(it)
            OutgoingSms(m.getString("id"), m.getString("phone"), m.getString("body"))
        }
        return ClaimResult(
            messages,
            json.optLong("pollAfterMs", 3000L).coerceIn(1000L, 60_000L),
            json.optLong("sendDelayMs", 1500L).coerceIn(0L, 30_000L),
        )
    }

    fun report(reports: List<Report>) {
        val arr = JSONArray()
        reports.forEach { r ->
            val o = JSONObject().put("id", r.id).put("status", r.status)
            if (r.error != null) o.put("error", r.error)
            arr.put(o)
        }
        post("/v1/gateway/report", JSONObject().put("reports", arr))
    }

    private fun post(path: String, body: JSONObject): JSONObject {
        val conn = URL(baseUrl + path).openConnection() as HttpURLConnection
        try {
            conn.requestMethod = "POST"
            conn.connectTimeout = 10_000
            conn.readTimeout = 15_000
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "application/json")
            conn.setRequestProperty("Accept", "application/json")
            conn.setRequestProperty("Authorization", "Bearer $token")
            conn.outputStream.use { it.write(body.toString().toByteArray(Charsets.UTF_8)) }

            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            if (code !in 200..299) {
                val message = runCatching {
                    JSONObject(text).getJSONObject("error").getString("message")
                }.getOrDefault("HTTP $code")
                throw ApiException(code, message)
            }
            return if (text.isBlank()) JSONObject() else JSONObject(text)
        } finally {
            conn.disconnect()
        }
    }
}
