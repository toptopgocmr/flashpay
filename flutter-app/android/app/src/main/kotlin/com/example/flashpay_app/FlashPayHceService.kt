package com.example.flashpay_app

import android.content.Context
import android.nfc.cardemulation.HostApduService
import android.os.Bundle

/**
 * NFC téléphone ↔ téléphone (CDC §4.4) : émulation de carte (HCE).
 *
 * Quand un écran FlashPay affiche un code (mon QR, code de paiement / dépôt,
 * QR marchand, QR agent, QR dynamique), l'app enregistre le même lien
 * `flashpay://…` ici. Un autre téléphone FlashPay qui sélectionne l'AID
 * F0 46 4C 41 53 48 50 (« FLASHP ») reçoit ce lien + 90 00.
 *
 * Sécurité : le lien expire (2 min par défaut) et est effacé dès que l'écran
 * se ferme ; le service exige un téléphone déverrouillé (apduservice.xml).
 * Le paiement lui-même reste confirmé par PIN côté payeur.
 */
class FlashPayHceService : HostApduService() {

    companion object {
        const val PREFS = "flashpay_hce"
        const val KEY_PAYLOAD = "payload"
        const val KEY_EXPIRES = "expires_at"

        val AID = byteArrayOf(0xF0.toByte(), 0x46, 0x4C, 0x41, 0x53, 0x48, 0x50)
        private val SW_OK = byteArrayOf(0x90.toByte(), 0x00)
        private val SW_NOT_FOUND = byteArrayOf(0x6A.toByte(), 0x82.toByte())
        private val SW_NOT_READY = byteArrayOf(0x69.toByte(), 0x85.toByte())
        private val SW_WRONG = byteArrayOf(0x6D.toByte(), 0x00)

        fun setPayload(context: Context, payload: String?, ttlSeconds: Int) {
            val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            if (payload.isNullOrEmpty()) {
                prefs.clear()
            } else {
                prefs.putString(KEY_PAYLOAD, payload)
                prefs.putLong(KEY_EXPIRES, System.currentTimeMillis() + ttlSeconds * 1000L)
            }
            prefs.apply()
        }
    }

    override fun processCommandApdu(commandApdu: ByteArray?, extras: Bundle?): ByteArray {
        val apdu = commandApdu ?: return SW_WRONG
        if (apdu.size < 5) return SW_WRONG
        val isSelectByName = apdu[0] == 0x00.toByte() && apdu[1] == 0xA4.toByte() && apdu[2] == 0x04.toByte()
        if (!isSelectByName) return SW_WRONG
        val lc = apdu[4].toInt() and 0xFF
        if (apdu.size < 5 + lc) return SW_WRONG
        if (!apdu.copyOfRange(5, 5 + lc).contentEquals(AID)) return SW_NOT_FOUND

        val prefs = getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val payload = prefs.getString(KEY_PAYLOAD, null)
        val expires = prefs.getLong(KEY_EXPIRES, 0L)
        if (payload.isNullOrEmpty() || System.currentTimeMillis() > expires) return SW_NOT_READY

        val body = payload.toByteArray(Charsets.UTF_8)
        if (body.size > 250) return SW_NOT_READY
        return body + SW_OK
    }

    override fun onDeactivated(reason: Int) {
        // Rien à faire : le lien reste disponible jusqu'à expiration / fermeture de l'écran.
    }
}
