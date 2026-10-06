<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Continuité & plafonds</h1>
        <p>Mode dégradé par canal (un canal coupé refuse les nouvelles opérations avec votre message) et plafonds clients par palier KYC.</p>
      </div>
    </div>

    <section class="container mb">
      <div class="container-head">
        <div><h3>Canaux de paiement</h3><p>{{ offCount ? `${offCount} canal(aux) en mode dégradé` : 'Tous les canaux sont actifs' }}</p></div>
        <span class="status" :class="offCount ? 'warn' : 'ok'">{{ offCount ? 'Mode dégradé' : 'Normal' }}</span>
      </div>
      <div class="container-body flush"><table>
        <thead><tr><th>Canal</th><th style="width:220px">État</th><th>Message affiché aux utilisateurs</th></tr></thead>
        <tbody><tr v-for="(c, k) in channels" :key="k" :class="{ 'st-off': !c.enabled }">
          <td><strong>{{ c.label }}</strong><br /><small class="mono muted">{{ k }}</small></td>
          <td>
            <label class="st-switch">
              <button type="button" class="st-toggle" :class="{ on: c.enabled }" @click="c.enabled = !c.enabled"><span></span></button>
              <span :class="c.enabled ? 'ok' : 'err'">{{ c.enabled ? 'Actif' : 'Coupé (mode dégradé)' }}</span>
            </label>
          </td>
          <td style="min-width:320px"><input v-model="c.message" style="width:100%" :placeholder="c.enabled ? 'Message affiché si le canal est coupé' : 'Message par défaut si vide'" /></td>
        </tr></tbody>
      </table></div>
      <div class="container-foot st-foot"><span class="muted">Les changements s'appliquent immédiatement à l'application.</span><button class="btn" :disabled="saving === 'ch'" @click="saveChannels">{{ saving === 'ch' ? 'Application…' : 'Appliquer' }}</button></div>
    </section>

    <section class="container mb">
      <div class="container-head"><div><h3>Plafonds clients par palier KYC</h3><p>Montants en XAF ; appliqués aux nouvelles opérations dès l'enregistrement.</p></div></div>
      <div class="container-body flush"><table>
        <thead><tr><th>Palier</th><th>KYC requis</th><th class="num">Par opération</th><th class="num">Par jour</th><th class="num">Par mois</th><th class="num">Solde max</th><th>International</th></tr></thead>
        <tbody><tr v-for="(t, i) in limits" :key="i">
          <td><span class="role-chip" :class="{ red: i % 2 }">{{ t.label }}</span></td><td>{{ t.kyc }}</td>
          <td class="num"><input v-model.number="t.per_operation" type="number" min="0" class="st-num" /></td>
          <td class="num"><input v-model.number="t.daily" type="number" min="0" class="st-num" /></td>
          <td class="num"><input v-model.number="t.monthly" type="number" min="0" class="st-num" /></td>
          <td class="num"><input v-model.number="t.max_balance" type="number" min="0" class="st-num" /></td>
          <td><span class="status" :class="t.international ? 'ok' : 'muted'">{{ t.international ? 'Oui' : 'Non' }}</span></td>
        </tr></tbody>
      </table></div>
      <div class="container-foot st-foot"><span class="muted">Un plafond à 0 bloque l'opération pour ce palier.</span><button class="btn" :disabled="saving === 'lim'" @click="saveLimits">{{ saving === 'lim' ? 'Enregistrement…' : 'Enregistrer les plafonds' }}</button></div>
    </section>

    <section class="container" v-if="security">
      <div class="container-head"><div><h3>Paramètres de sécurité</h3><p>Lus dans la configuration du serveur (fichier .env) ; non modifiables ici.</p></div></div>
      <div class="container-body">
        <dl class="st-sec">
          <div v-for="(v, k) in security" :key="k" class="st-item">
            <dt>{{ SEC[k] || k }}</dt>
            <dd><span v-if="typeof v === 'boolean'" class="status" :class="v ? 'ok' : 'muted'">{{ v ? 'Oui' : 'Non' }}</span><b v-else>{{ v }}</b></dd>
          </div>
        </dl>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import { errMsg } from '../utils/format'
import { toast } from '../utils/ui'

const SEC = { require_otp_on_register: 'OTP à l\'inscription', pin_mandatory: 'PIN obligatoire', device_policy: 'Politique multi-appareils', otp_on_new_device: 'OTP sur nouvel appareil', cash_in_client_confirmation: 'Confirmation client des dépôts agent', refund_window_days: 'Délai de remboursement boutique (jours)', dynamic_qr_ttl: 'Validité QR dynamique (s)', rto_minutes: 'RTO (min)', rpo_minutes: 'RPO (min)' }
const channels = ref({})
const limits = ref([])
const security = ref(null)
const saving = ref('')
const offCount = computed(() => Object.values(channels.value).filter((c) => !c.enabled).length)

async function load() {
  const { data } = await api.get('/admin/settings')
  channels.value = data.channels
  const over = data.limits.overrides?.client || {}
  limits.value = Object.entries(data.limits.config.client).map(([k, t]) => ({ ...t, ...(over[k] || {}) }))
  security.value = data.security
}
async function saveChannels() {
  saving.value = 'ch'
  try {
    await api.post('/admin/settings/channels', { channels: channels.value })
    toast(offCount.value ? `Mode dégradé actif sur ${offCount.value} canal(aux).` : 'Tous les canaux sont actifs.', offCount.value ? 'warn' : 'ok')
  } catch (e) { toast(errMsg(e), 'err') } finally { saving.value = '' }
}
async function saveLimits() {
  const client = {}
  limits.value.forEach((t, i) => { client[i] = { per_operation: t.per_operation, daily: t.daily, monthly: t.monthly, max_balance: t.max_balance } })
  saving.value = 'lim'
  try {
    await api.post('/admin/settings/limits', { limits: { client } })
    toast('Plafonds enregistrés (appliqués immédiatement).')
  } catch (e) { toast(errMsg(e), 'err') } finally { saving.value = '' }
}
onMounted(load)
</script>

<style scoped>
.muted { color: var(--text-3); font-size: 12px; }
.st-switch { display: inline-flex; align-items: center; gap: 10px; font-weight: 600; font-size: 13px; }
.st-switch .ok { color: var(--ok); } .st-switch .err { color: var(--err); }
.st-toggle { width: 40px; height: 22px; border-radius: 11px; border: 0; background: #cbd5e1; position: relative; cursor: pointer; padding: 0; flex: none; }
.st-toggle span { position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: left .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.st-toggle.on { background: var(--ok); } .st-toggle.on span { left: 21px; }
tr.st-off td { background: #fff7f7; }
.st-num { width: 130px; text-align: right; }
.st-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; text-align: left; }
.st-sec { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 0 24px; margin: 0; }
.st-item { padding: 10px 0; border-bottom: 1px solid var(--border); }
.st-sec dt { color: var(--text-2); font-size: 12.5px; }
.st-sec dd { margin: 3px 0 0; }
</style>
