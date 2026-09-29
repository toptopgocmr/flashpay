<template>
  <div>
    <h2>PEEX — {{ overview?.sandbox ? 'Sandbox' : 'Production' }}</h2>

    <!-- Comptes PEEX -->
    <div class="grid grid-4" style="grid-template-columns: repeat(3, 1fr); margin-bottom:24px;">
      <div class="card" v-for="(acc, key) in overview?.accounts || {}" :key="key">
        <div class="stat-label">{{ labels[key] }}</div>
        <template v-if="acc.ok">
          <div class="stat-value">{{ formatXaf(acc.data.collect_solde ?? acc.data.disbursement_solde ?? acc.data.solde) }}</div>
          <div class="stat-label">{{ acc.data.name }} · {{ acc.data.is_activated ? 'activé' : 'inactif' }}</div>
          <div class="stat-label">callback : {{ acc.data.callback_url || 'non défini' }}</div>
        </template>
        <div v-else style="color:#b91c1c; font-size:13px;">{{ acc.error }}</div>
      </div>
    </div>
    <p v-if="loadingOverview" class="stat-label">Connexion à PEEX…</p>

    <!-- Lancer un test -->
    <div class="card" style="margin-bottom:24px;">
      <h3>Lancer un test</h3>
      <form @submit.prevent="runTest" class="grid" style="grid-template-columns: repeat(3, 1fr); gap:12px;">
        <select v-model="form.action">
          <option value="transfer">Transfert mobile → mobile (collecte puis décaissement)</option>
          <option value="collect">Collecte mobile → wallet admin</option>
          <option value="payout">Décaissement wallet admin → mobile</option>
        </select>
        <input v-if="form.action !== 'payout'" v-model="form.source_phone" placeholder="Numéro payeur (ex: 065123456 MTN CG)" />
        <input v-if="form.action !== 'collect'" v-model="form.destination_phone" placeholder="Numéro bénéficiaire (ex: 055123456 Airtel CG)" />
        <input v-model.number="form.amount" type="number" min="1" placeholder="Montant XAF" />
        <input v-if="form.action !== 'collect'" v-model="form.beneficiary_name" placeholder="Nom du bénéficiaire" />
        <button class="btn" type="submit" :disabled="running" style="grid-column: span 3;">
          {{ running ? 'Envoi à PEEX…' : 'Envoyer' }}
        </button>
      </form>

      <div style="margin-top:12px; font-size:13px; color:#6b7280;">
        Préréglages :
        <a href="#" v-for="p in presets" :key="p.label" @click.prevent="applyPreset(p)" style="margin-right:12px;">{{ p.label }}</a>
      </div>
      <div v-if="overview?.sandbox_test_numbers" style="margin-top:8px; font-size:12px; color:#6b7280;">
        Numéros de test PEEX (Cameroun) —
        paid : {{ overview.sandbox_test_numbers.paid.join(', ') }} ·
        pending : {{ overview.sandbox_test_numbers.pending.join(', ') }} ·
        failed : {{ overview.sandbox_test_numbers.failed.join(', ') }} ·
        rejected : {{ overview.sandbox_test_numbers.rejected.join(', ') }}.
        {{ overview.sandbox_test_numbers.note }}
      </div>

      <div v-if="error" style="margin-top:12px; color:#b91c1c;">{{ error }}</div>
      <div v-if="lastTx" style="margin-top:12px;">
        <strong>{{ lastTx.reference }}</strong> —
        <span class="badge" :class="badge(lastTx.status)">{{ lastTx.status }}</span>
        <span v-if="lastTx.stage"> ({{ lastTx.stage }})</span>
        <span v-if="lastTx.failure_reason"> — {{ lastTx.failure_reason }}</span>
      </div>
    </div>

    <!-- Demandes PEEX -->
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3>Demandes PEEX</h3>
        <div>
          <label style="font-size:13px; margin-right:12px;"><input type="checkbox" v-model="autoRefresh" /> Auto-actualiser (10 s)</label>
          <button class="btn" @click="syncAll" :disabled="syncing">{{ syncing ? 'Synchronisation…' : 'Synchroniser les statuts' }}</button>
        </div>
      </div>
      <table>
        <thead>
          <tr><th>Date</th><th>Service</th><th>track_id</th><th>Corridor</th><th>Numéro</th><th>Montant</th><th>PEEX</th><th>Transaction</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in requests" :key="r.id">
            <td>{{ new Date(r.created_at).toLocaleString('fr-FR') }}</td>
            <td>{{ r.service }}</td>
            <td style="font-family:monospace; font-size:12px;">{{ r.track_id }}</td>
            <td>{{ r.country }} · {{ r.corridor || '?' }}</td>
            <td>{{ $phone(r.phone) }}</td>
            <td>{{ formatXaf(r.amount) }}</td>
            <td>
              <span class="badge" :class="badge(r.status)">{{ r.status }}</span>
              <div v-if="r.payment_proof || r.message" style="font-size:11px; color:#6b7280; max-width:220px;">{{ r.payment_proof || r.message }}</div>
            </td>
            <td>
              <router-link v-if="r.transaction" :to="'/transactions/' + r.transaction.id">{{ r.transaction.reference }}</router-link>
              <div v-if="r.transaction"><span class="badge" :class="badge(r.transaction.status)">{{ r.transaction.status }}</span></div>
            </td>
            <td style="white-space:nowrap;">
              <template v-if="!r.finalized_at">
                <a href="#" @click.prevent="refresh(r)">Statut</a>
                <template v-if="overview?.sandbox">
                  · <a href="#" @click.prevent="simulate(r, 'paid')">Simuler payé</a>
                  · <a href="#" @click.prevent="simulate(r, 'failed')">Simuler échec</a>
                </template>
              </template>
            </td>
          </tr>
          <tr v-if="!requests.length"><td colspan="9" class="stat-label">Aucune demande pour l'instant.</td></tr>
        </tbody>
      </table>
    </div>

    <div class="card" style="margin-top:24px; font-size:13px;" v-if="overview">
      <h3>Callbacks à déclarer chez PEEX</h3>
      <div v-for="(url, k) in overview.callback_urls" :key="k"><strong>{{ k }}</strong> : <code>{{ url }}</code></div>
      <div style="margin-top:8px"><strong>IP sortante du serveur</strong> (à faire autoriser par PEEX) : <code>{{ overview.server_ip || 'inconnue' }}</code></div>
      <p class="stat-label">En local, PEEX ne peut pas joindre localhost : les statuts sont récupérés par polling
        (fenêtre « Planificateur » / bouton Synchroniser) ou via un tunnel HTTPS (ngrok, cloudflared).</p>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount, watch } from 'vue'
import api from '../services/api'

const labels = { collect: 'Compte Collecte', disbursement: 'Compte Décaissement', remittance: 'Compte Remittance' }
const overview = ref(null)
const loadingOverview = ref(false)
const requests = ref([])
const running = ref(false)
const syncing = ref(false)
const error = ref('')
const lastTx = ref(null)
const autoRefresh = ref(true)
let timer = null

const form = reactive({ action: 'transfer', source_phone: '065123456', destination_phone: '055123456', amount: 100, beneficiary_name: 'Test Airtel' })

const presets = [
  { label: 'MTN CG → Airtel CG', action: 'transfer', source_phone: '065123456', destination_phone: '055123456' },
  { label: 'Airtel CG → MTN CG', action: 'transfer', source_phone: '055123456', destination_phone: '065123456' },
  { label: 'Congo → Cameroun', action: 'transfer', source_phone: '065123456', destination_phone: '+237677000001' },
  { label: 'Congo → Gabon', action: 'transfer', source_phone: '065123456', destination_phone: '+241076566326' },
  { label: 'Test CM payé (collecte)', action: 'collect', source_phone: '+237677000001' },
  { label: 'Test CM échec (collecte)', action: 'collect', source_phone: '+237677100001' },
  { label: 'Test CM payé (décaissement)', action: 'payout', destination_phone: '+237677000002' },
]

function applyPreset(p) {
  Object.assign(form, { source_phone: '', destination_phone: '' }, p)
  delete form.label
}

async function loadOverview() {
  loadingOverview.value = true
  try { overview.value = (await api.get('/admin/peex/overview')).data } finally { loadingOverview.value = false }
}

async function loadRequests() {
  requests.value = (await api.get('/admin/peex/requests')).data.data
}

async function runTest() {
  running.value = true
  error.value = ''
  try {
    const payload = { action: form.action, amount: form.amount, beneficiary_name: form.beneficiary_name }
    if (form.action !== 'payout') payload.source_phone = form.source_phone
    if (form.action !== 'collect') payload.destination_phone = form.destination_phone
    lastTx.value = (await api.post('/admin/peex/test', payload)).data
    await loadRequests()
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    running.value = false
  }
}

async function refresh(r) {
  await api.post(`/admin/peex/requests/${r.id}/refresh`)
  await loadRequests()
}

async function simulate(r, status) {
  await api.post('/admin/peex/simulate-callback', { track_id: r.track_id, status })
  await loadRequests()
}

async function syncAll() {
  syncing.value = true
  try { await api.post('/admin/peex/sync'); await loadRequests() } finally { syncing.value = false }
}

function badge(s) {
  if (['paid', 'successful'].includes(s)) return 'badge-success'
  if (['new', 'pending', 'processing'].includes(s)) return 'badge-pending'
  return 'badge-failed'
}

function formatXaf(n) {
  return n === undefined || n === null ? '—' : new Intl.NumberFormat('fr-FR').format(n) + ' XAF'
}

function startTimer() {
  stopTimer()
  if (autoRefresh.value) timer = setInterval(loadRequests, 10000)
}
function stopTimer() { if (timer) clearInterval(timer); timer = null }
watch(autoRefresh, startTimer)

onMounted(() => { loadOverview(); loadRequests(); startTimer() })
onBeforeUnmount(stopTimer)
</script>
