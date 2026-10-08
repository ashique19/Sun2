package com.sundoritoma.posotg.data

import android.net.Uri
import okhttp3.OkHttpClient
import okhttp3.Request
import org.json.JSONObject
import java.util.concurrent.TimeUnit

class SlipRepository(
    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build(),
) {
    fun fetchJob(slipsUrl: String): PrintJob {
        val request = Request.Builder()
            .url(slipsUrl)
            .header("Accept", "application/json")
            .get()
            .build()

        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) {
                throw IllegalStateException("Slips request failed: HTTP ${response.code}")
            }
            val body = response.body?.string().orEmpty()
            return parseJob(body)
        }
    }

    fun slipsUrlFromDeepLink(uri: Uri?): String? {
        if (uri == null) {
            return null
        }
        if (uri.scheme.equals("sundoritoma", ignoreCase = true) &&
            uri.host.equals("print", ignoreCase = true)
        ) {
            return uri.getQueryParameter("slips_url")
        }
        return null
    }

    private fun parseJob(json: String): PrintJob {
        val root = JSONObject(json)
        val array = root.getJSONArray("slips")
        val slips = buildList {
            for (i in 0 until array.length()) {
                val o = array.getJSONObject(i)
                add(
                    PrintSlip(
                        id = o.getInt("id"),
                        orderNumber = o.optString("order_number"),
                        parcelId = o.optString("parcel_id").ifBlank { null },
                        brand = o.optString("brand", "Sundoritoma.com"),
                        helpline = o.optString("helpline"),
                        name = o.optString("name"),
                        phone = o.optString("phone"),
                        address = o.optString("address"),
                        dueTk = o.optInt("due_tk", 0),
                    ),
                )
            }
        }
        return PrintJob(
            slips = slips,
            cutAfterEach = root.optBoolean("cut_after_each", true),
        )
    }
}
