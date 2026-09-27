<template>
  <div>
    <div class="page-header">
      <div><h1>Continuité & plafonds</h1><p>Mode dégradé par canal en cas d'incident partenaire (§14) — un canal désactivé refuse les nouvelles opérations avec un message explicite, affiché aussi dans l'app — et plafonds par palier KYC (§12, montants indicatifs à valider avec la conformité COBAC).</p></div>
    </div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>
    <section class="container mb">
      <div class="container-head"><h3>Canaux de paiement</h3></div>
      <div class="container-body flush"><table>
        <thead><tr><th>Canal</th><th>État</th><th>Message affiché aux utilisateurs</th></tr></thead>
        <tbody><tr v-for="(c, k) in channels" :key="k">
          <td><strong>{{ c.label }}</strong><br /><small class="mono">{{ k }}</small></td>
          <td><label><input type="checkbox" v-model="c.enabled" /> {{ c.enabled ? 'Actif' : 'Désactivé (mode dégradé)' }}</label></td>
          <td style="min-width:320px"><input v-model="c.message" style="width:100%" placeholder="Message par défaut si vide" :disabled="c.enabled" /></td>
        </tr></tbody>
      </table></div>
      <div class="container-foot"><button class="btn" @click="saveChannels">Appliquer</button></div>
    </section>
    <section class="container mb">
      <div class="container-head"><h3>Plafonds clients par palier KYC</h3></div>
      <div class="container-body flush"><table>
        <thead><tr><th>Palier</th><th>KYC requis</th><th class="num">Par opération</th><th class="num">Par jour</th><th class="num">Par mois</th><th class="num">Solde max</th><th>International</th></tr></thead>
        <tbody><tr v-for="(t, i) in limits" :key="i">
          <td><strong>{{ t.label }}</strong></td><td>{{ t.kyc }}</td>
          <td class="num"><input v-model.number="t.per_operation" type="number" style="width:120px" /></td>
          <td class="num"><input v-model.number="t.daily" type="number" style="width:120px" /></td>
          <td class="num"><input v-model.number="t.monthly" type="number" style="width:130px" /></td>
          <td class="num"><input v-model.number="t.max_balance" type="number" style="width:130px" /></td>
          <td>{{ t.international ? 'Oui' : 'Non' }}</td>
        </tr></tbody>
      </table></div>
      <div class="container-foot"><button class="btn" @click="saveLimits">Enregistrer les plafonds</button></div>
    </section>
    <section class="container" v-if="security">
      <div class="container-head"><h3>Paramètres de sécurité (fichier .env)</h3></div>
      <div class="container-body"><div class="kv" v-for="(v, k) in security" :key="k"><span>{{ SEC[k] || k }}</span><b>{{ typeof v === 'boolean' ? (v ? 'Oui' : 'Non') : v }}</b></div></div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../services/api'
import { errMsg } from '../utils/format'

const SEC = { require_otp_on_register: 'OTP à l\'inscription', pin_mandatory: 'PIN obligatoire', device_policy: 'Politique multi-appareils', otp_on_new_device: 'OTP sur nouvel appareil', cash_in_client_confirmation: 'Confirmation client des dépôts agent', refund_window_days: 'Délai de remboursement boutique (jours)', dynamic_qr_ttl: 'Validité QR dynamique (s)', rto_minutes: 'RTO (min)', rpo_minutes: 'RPO (min)' }
const channels = ref({})
const limits = ref([])
const security = ref(null)
const msg = ref('')
async function load() {
  const { data } = await api.get('/admin/settings')
  channels.value = data.channels
  const over = data.limits.overrides?.client || {}
  limits.value = Object.entries(data.limits.config.client).map(([k, t]) => ({ ...t, ...(over[k] || {}) }))
  security.value = data.security
}
async function saveChannels() {
  try {
    await api.post('/admin/settings/channels', { channels: channels.value })
    msg.value = 'Canaux mis à jour.'
  } catch (e) { msg.value = errMsg(e) }
}
async function saveLimits() {
  const client = {}
  limits.value.forEach((t, i) => { client[i] = { per_operation: t.per_operation, daily: t.daily, monthly: t.monthly, max_balance: t.max_balance } })
  try {
    await api.post('/admin/settings/limits', { limits: { client } })
    msg.value = 'Plafonds enregistrés (appliqués immédiatement).'
  } catch (e) { msg.value = errMsg(e) }
}
onMounted(load)
</script>
