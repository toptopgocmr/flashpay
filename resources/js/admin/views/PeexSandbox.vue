<template>
  <div class="gw-page">
    <div class="gw-top">
      <div class="gw-top-left">
        <h1>Passerelle PEEX</h1>
        <div class="gw-pills" v-if="overview">
          <span class="gw-pill" :class="overview.sandbox ? 'info' : 'accent'">{{ overview.sandbox ? 'Sandbox' : 'Production' }}</span>
        </div>
      </div>
      <div class="actions"><button class="btn-normal" :disabled="loadingOverview" @click="loadOverview(); loadRequests()">{{ loadingOverview ? 'Connexion…' : 'Actualiser' }}</button></div>
    </div>

    <!-- Comptes PEEX -->
    <section class="gw-section">
      <GwHead icon="wallet" title="Comptes PEEX" />
      <div class="gw-acc">
        <div class="gw-card" v-for="(acc, key) in overview?.accounts || {}" :key="key">
          <div class="gw-card-h">
            <div class="t"><h3>{{ labels[key] }}</h3><p v-if="acc.ok">{{ acc.data.name }}</p></div>
            <div class="side"><span class="gw-pill" :class="acc.ok ? (acc.data.is_activated ? 'ok' : 'warn') : 'err'">{{ acc.ok ? (acc.data.is_activated ? 'Activé' : 'Inactif') : 'Erreur' }}</span></div>
          </div>
          <div class="gw-card-b">
            <template v-if="acc.ok">
              <div class="acc-bal" v-go="'#peex-requests'">{{ formatXaf(acc.data.collect_solde ?? acc.data.disbursement_solde ?? acc.data.solde) }}</div>
              <GwField :value="acc.data.callback_url" placeholder="Callback non défini" />
            </template>
            <div v-else class="gw-note err">{{ acc.error }}</div>
          </div>
        </div>
        <div v-if="!overview && loadingOverview" class="gw-card"><div class="gw-empty">Connexion à PEEX…</div></div>
      </div>
    </section>

    <div class="gw-cols">
      <!-- IP & callbacks -->
      <section class="gw-section" v-if="overview">
        <GwHead icon="shield" title="IP & callbacks" />
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>IP du serveur FlashPay</h3><p>À faire autoriser par PEEX</p></div></div>
          <div class="gw-card-b"><GwField :value="overview.server_ip" placeholder="Inconnue" /></div>
        </div>
        <div class="gw-card">
          <div class="gw-card-h"><div class="t"><h3>Callbacks</h3><p>URL de notification par service</p></div></div>
          <div class="gw-card-b">
            <div v-for="(url, k) in overview.callback_urls" :key="k" class="cb-row"><span class="cb-k">{{ k }}</span><GwField :value="url" /></div>
          </div>
        </div>
      </section>

      <!-- Diagnostic PEEX (erreurs 503, 403, injoignable…) -->
      <section class="gw-section">
      <GwHead icon="pulse" title="Diagnostic" />
    <div class="gw-card"><div class="gw-card-b">
      <button class="gw-btn block" :disabled="diagLoading" @click="runDiagnostic">{{ diagLoading ? 'Test en cours…' : 'Lancer le diagnostic' }}</button>
      <div v-if="diagError" style="margin-top:10px; color:#b91c1c;">{{ diagError }}</div>
      <template v-if="diag">
        <div class="flash" :class="diag.probes && Object.values(diag.probes).every((p) => p.ok) && !diag.last24h.errors_5xx ? 'info' : 'err'" style="margin:12px 0 8px;"><div><strong>{{ diag.verdict }}</strong></div></div>
        <div class="stat-label" style="margin-bottom:8px;">{{ diag.base_url }} · {{ diag.sandbox ? 'sandbox' : 'production' }} · IP du serveur : <span class="mono">{{ diag.server_ip || '?' }}</span>
          · 24 h : {{ diag.last24h.total }} demande(s), {{ diag.last24h.errors_5xx }} erreur(s) 5xx, {{ diag.last24h.unknown }} en statut inconnu</div>
        <div class="req-wrap">
          <table class="req-table">
            <thead><tr><th>Test</th><th>HTTP</th><th>Durée</th><th>Réponse</th></tr></thead>
            <tbody>
              <tr v-for="(p, label) in diag.probes" :key="label">
                <td><strong>{{ label }}</strong><br /><small class="mono muted">{{ p.path }}</small></td>
                <td><span class="status" :class="p.ok ? 'ok' : 'err'">{{ p.http || '—' }}</span></td>
                <td class="nowrap">{{ p.ms }} ms</td>
                <td style="max-width:520px;"><small>{{ p.message || '' }}</small><br v-if="p.message && p.snippet" /><small class="mono muted" style="word-break:break-all;">{{ p.snippet }}</small></td>
              </tr>
            </tbody>
          </table>
        </div>
        <details style="margin-top:12px;">
          <summary><strong>Message pour le support PEEX</strong> (à envoyer à support@peexit.com)</summary>
          <textarea readonly :value="diag.support_message" rows="12" style="width:100%; margin-top:8px; font-family:inherit;"></textarea>
          <button class="btn-normal" style="margin-top:6px;" @click="copySupport">{{ copied ? 'Copié ✓' : 'Copier le message' }}</button>
        </details>
      </template>
    </div></div>
      </section>
    </div>

    <!-- Lancer un test -->
    <section class="gw-section">
    <GwHead icon="flask" title="Tester" />
    <div class="gw-card"><div class="gw-card-b">
      <form @submit.prevent="runTest" class="grid grid-3" style="gap:12px;">
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
    </div></div>
    </section>

    <!-- Remboursement PEEX -->
    <section class="gw-section">
    <GwHead icon="refund" title="Remboursement" />
    <div class="gw-card"><div class="gw-card-b">
      <form @submit.prevent="searchRefund" style="display:flex; gap:12px;">
        <input v-model.trim="refundQ" placeholder="Ex. FP-CNSW0VIZPMGO, FP-…-C1 ou 057563644" style="flex:1;" />
        <button class="btn" type="submit" :disabled="refundSearching || refundQ.length < 3">{{ refundSearching ? 'Recherche…' : 'Rechercher' }}</button>
      </form>
      <div v-if="refundError" style="margin-top:10px; color:#b91c1c;">{{ refundError }}</div>
      <div v-if="refundResults" class="req-wrap" style="margin-top:12px;">
        <table class="req-table">
          <thead><tr><th>Date</th><th>Référence</th><th>Opération</th><th>Client</th><th>Montant</th><th>Statut</th><th>Remboursable</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in refundResults" :key="t.id">
              <td class="nowrap">{{ new Date(t.created_at).toLocaleDateString('fr-FR') }}</td>
              <td class="mono small"><router-link :to="'/transactions/' + t.id">{{ t.reference }}</router-link></td>
              <td>{{ t.type }}</td>
              <td>{{ t.sender || '—' }}<br /><small class="muted">{{ t.sender_phone ? $phone(t.sender_phone) : '' }}</small></td>
              <td class="nowrap">{{ formatXaf(t.amount) }}<br /><small class="muted">frais {{ formatXaf(t.fee) }}</small></td>
              <td><span class="badge" :class="badge(t.status)">{{ t.status_label }}</span><br /><small class="muted">{{ t.failure_reason }}</small></td>
              <td class="nowrap"><strong>{{ formatXaf(t.refundable) }}</strong><br /><small class="muted" v-if="t.refunds">{{ t.refunds }} remboursement(s) déjà demandé(s)</small></td>
              <td><button class="btn" :disabled="!t.refundable" @click="refundTx = t.id">Rembourser</button></td>
            </tr>
            <tr v-if="!refundResults.length"><td colspan="8" class="stat-label">Aucune opération trouvée.</td></tr>
          </tbody>
        </table>
      </div>
    </div></div>
    </section>
    <PeexRefund v-if="refundTx" :transaction-id="refundTx" @close="refundTx = null" @done="searchRefund(); loadRequests()" />

    <!-- Demandes PEEX -->
    <section id="peex-requests" class="gw-section">
    <GwHead icon="swap" title="Demandes PEEX" :count="requests.length">
      <label style="font-size:13px;"><input type="checkbox" v-model="autoRefresh" /> Auto (10 s)</label>
      <button class="btn-normal" @click="syncAll" :disabled="syncing">{{ syncing ? 'Synchronisation…' : 'Synchroniser les statuts' }}</button>
    </GwHead>
    <div class="gw-card">
      <div class="req-wrap">
      <table class="req-table peex-req">
        <thead>
          <tr><th>Date</th><th>Service</th><th>track_id</th><th>Corridor</th><th>Numéro</th><th>Montant</th><th>PEEX</th><th>Transaction</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <tr v-for="r in requests" :key="r.id">
            <td class="nowrap">{{ new Date(r.created_at).toLocaleDateString('fr-FR') }}<br /><small class="muted">{{ new Date(r.created_at).toLocaleTimeString('fr-FR') }}</small></td>
            <td>{{ SERVICE[r.service] || r.service }}</td>
            <td class="mono track" :title="r.track_id">{{ r.track_id }}</td>
            <td class="nowrap">{{ r.country }} · {{ r.corridor || '?' }}</td>
            <td class="nowrap">{{ $phone(r.phone) }}</td>
            <td class="nowrap">{{ formatXaf(r.amount) }}</td>
            <td class="peex-cell">
              <span class="badge" :class="badge(r.status)">{{ STATUS[r.status] || r.status }}</span>
              <template v-for="p in [proof(r)]" :key="'p' + r.id">
                <div v-if="p.lines.length" class="proof">
                  <div v-for="l in p.lines" :key="l[0]"><span class="muted">{{ l[0] }} :</span> <span :class="{ mono: l[0] !== 'Motif' && l[0] !== 'Message' }">{{ l[1] }}</span></div>
                </div>
                <details v-if="p.raw" class="raw">
                  <summary>Réponse brute PEEX</summary>
                  <pre>{{ p.raw }}</pre>
                </details>
              </template>
            </td>
            <td class="tx-cell">
              <template v-if="r.transaction">
                <router-link class="mono small" :to="'/transactions/' + r.transaction.id">{{ r.transaction.reference }}</router-link>
                <div><span class="badge" :class="badge(r.transaction.status)">{{ STATUS[r.transaction.status] || r.transaction.status }}</span></div>
              </template>
              <span v-else class="muted">—</span>
            </td>
            <td class="act-cell">
              <a v-if="r.transaction" href="#" @click.prevent="refundTx = r.transaction.id">Rembourser</a>
              <template v-if="!r.finalized_at">
                <a href="#" @click.prevent="refresh(r)">Statut</a>
                <template v-if="overview?.sandbox">
                  <a href="#" @click.prevent="simulate(r, 'paid')">Simuler payé</a>
                  <a href="#" @click.prevent="simulate(r, 'failed')">Simuler échec</a>
                </template>
              </template>
            </td>
          </tr>
          <tr v-if="!requests.length"><td colspan="9" class="stat-label">Aucune demande pour l'instant.</td></tr>
        </tbody>
      </table>
      </div>
    </div>
    </section>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onBeforeUnmount, watch } from 'vue'
import api from '../services/api'
import PeexRefund from '../components/PeexRefund.vue'
import GwHead from '../components/GwHead.vue'
import GwField from '../components/GwField.vue'

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

// Diagnostic PEEX
const diag = ref(null)
const diagLoading = ref(false)
const diagError = ref('')
const copied = ref(false)
async function runDiagnostic() {
  diagLoading.value = true
  diagError.value = ''
  copied.value = false
  try {
    diag.value = (await api.get('/admin/peex/diagnostic')).data
  } catch (e) {
    diagError.value = e.response?.data?.message || e.message
  } finally {
    diagLoading.value = false
  }
}
async function copySupport() {
  try { await navigator.clipboard.writeText(diag.value.support_message); copied.value = true } catch (_) {}
}

// Remboursement PEEX (formulaire)
const refundQ = ref('')
const refundResults = ref(null)
const refundSearching = ref(false)
const refundError = ref('')
const refundTx = ref(null)
async function searchRefund() {
  if (refundQ.value.length < 3) return
  refundSearching.value = true
  refundError.value = ''
  try {
    refundResults.value = (await api.get('/admin/peex/refund-search', { params: { q: refundQ.value } })).data.data
  } catch (e) {
    refundError.value = e.response?.data?.message || e.message
  } finally {
    refundSearching.value = false
  }
}

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

const SERVICE = { collect: 'Collecte', disbursement: 'Décaissement', remittance: 'Remittance' }
const STATUS = {
  new: 'Nouvelle', pending: 'En attente', processing: 'En cours', paid: 'Payée', successful: 'Réussie',
  failed: 'Échouée', error: 'Erreur', reversed: 'Rejetée', canceled: 'Annulée', expired: 'Expirée',
}
// Résumé lisible de la preuve / du message PEEX (JSON opérateur ou texte d'erreur)
function proof(r) {
  const txt = r.payment_proof || r.message || ''
  if (!txt) return { lines: [], raw: '' }
  let j = null
  const start = txt.indexOf('{')
  if (start >= 0) { try { j = JSON.parse(txt.slice(start)) } catch { j = null } }
  if (!j || typeof j !== 'object') return { lines: [['Message', txt.length > 140 ? txt.slice(0, 140) + '…' : txt]], raw: txt.length > 140 ? txt : '' }
  const lines = []
  const add = (label, v) => { if (v !== undefined && v !== null && v !== '') lines.push([label, String(v)]) }
  add('Code HTTP', start > 0 ? txt.slice(0, start).replace(/[^0-9]/g, '') || null : null)
  add('ID opérateur', j.financialTransactionId)
  add('Statut opérateur', j.status)
  add('Payeur', j.payer?.partyId)
  add('Motif', j.reason?.message || j.reason || j.message)
  return { lines, raw: JSON.stringify(j, null, 2) }
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

<style scoped>
.req-wrap { overflow-x: auto; }
.gw-card > .req-wrap .req-table th:first-child, .gw-card > .req-wrap .req-table td:first-child { padding-left: 16px; }
.gw-acc { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr)); gap: 16px; align-items: start; }
.acc-bal { font-size: 26px; font-weight: 800; letter-spacing: -.01em; cursor: pointer; }
.cb-row { display: grid; grid-template-columns: 110px minmax(0, 1fr); align-items: center; gap: 10px; }
.cb-k { font-size: 12.5px; font-weight: 600; color: var(--text-2); text-transform: capitalize; }
.req-table { width: 100%; border-collapse: collapse; table-layout: auto; font-size: 13px; line-height: 1.4; }
.req-table th { font-size: 11px; letter-spacing: .04em; padding: 8px 8px; white-space: nowrap; }
.req-table td { vertical-align: top; padding: 9px 8px; }
.req-table small { font-size: 11.5px; }
.nowrap { white-space: nowrap; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 11.5px; }
.small { font-size: 11.5px; }
.track { font-size: 11px; word-break: break-all; min-width: 90px; max-width: 130px; }
.act-cell a { display: block; white-space: nowrap; margin: 2px 0; }
/* Écrans moyens : service et corridor masqués (le track_id et le numéro suffisent) */
@media (max-width: 1340px) { .peex-req th:nth-child(2), .peex-req td:nth-child(2), .peex-req th:nth-child(4), .peex-req td:nth-child(4) { display: none; } }
.muted { color: #6b7280; }
.peex-cell { min-width: 200px; max-width: 280px; }
.peex-cell .badge, .tx-cell .badge { font-size: 11.5px; padding: 1px 8px; }
.proof { margin-top: 4px; font-size: 12px; line-height: 1.45; overflow-wrap: anywhere; }
.raw { margin-top: 4px; font-size: 11.5px; }
.raw summary { cursor: pointer; color: var(--link, #0972d3); }
.raw pre { margin: 6px 0 0; padding: 8px; background: #f6f7f9; border-radius: 6px; max-height: 200px; overflow: auto; white-space: pre-wrap; word-break: break-all; font-size: 11px; }
.tx-cell { min-width: 110px; max-width: 150px; }
.tx-cell .mono { word-break: break-all; }
.tx-cell .badge { margin-top: 3px; display: inline-block; }
</style>
