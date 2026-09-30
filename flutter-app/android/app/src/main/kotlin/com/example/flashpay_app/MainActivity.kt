package com.example.flashpay_app

import android.content.ComponentName
import android.content.pm.PackageManager
import android.nfc.NfcAdapter
import android.nfc.cardemulation.CardEmulation
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        // Canal « flashpay/hce » : publication du code FlashPay par NFC (voir FlashPayHceService)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "flashpay/hce").setMethodCallHandler { call, result ->
            when (call.method) {
                "isSupported" -> result.success(
                    packageManager.hasSystemFeature(PackageManager.FEATURE_NFC_HOST_CARD_EMULATION) &&
                        NfcAdapter.getDefaultAdapter(this)?.isEnabled == true
                )
                "setPayload" -> {
                    val payload = call.argument<String>("payload")
                    val ttl = call.argument<Int>("ttlSeconds") ?: 120
                    FlashPayHceService.setPayload(this, payload, ttl)
                    result.success(!payload.isNullOrEmpty())
                }
                "clear" -> {
                    FlashPayHceService.setPayload(this, null, 0)
                    result.success(true)
                }
                else -> result.notImplemented()
            }
        }
    }

    /** App au premier plan : FlashPay est le service HCE prioritaire pour son AID. */
    override fun onResume() {
        super.onResume()
        try {
            val adapter = NfcAdapter.getDefaultAdapter(this) ?: return
            CardEmulation.getInstance(adapter).setPreferredService(this, ComponentName(this, FlashPayHceService::class.java))
        } catch (e: Exception) {
        }
    }

    override fun onPause() {
        try {
            val adapter = NfcAdapter.getDefaultAdapter(this)
            if (adapter != null) CardEmulation.getInstance(adapter).unsetPreferredService(this)
        } catch (e: Exception) {
        }
        super.onPause()
    }
}
