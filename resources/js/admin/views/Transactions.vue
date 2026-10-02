<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Transactions</h1>
        <p>{{ subtitle }}</p>
      </div>
      <div class="actions">
        <ExportButton filename="transactions" :columns="EXP_COLS" :fetch="expFetch" />
        <router-link class="btn-normal" to="/">‹ Tableau de bord</router-link>
      </div>
    </div>

    <section class="container mb">
      <div class="container-body filters">
        <select v-model="f.channel" @change="apply" aria-label="Canal">
          <option value="">Tous les canaux</option>
          <optgroup v-for="(fam, key) in FAMILIES" :key="key" :label="fam.label">
            <option :value="key">Toute la famille « {{ fam.label }} »</option>
            <option v-for="c in fam.channels" :key="c" :value="c">{{ CHANNELS[c] }}</option>
          </optgroup>
        </select>
        <select v-model="f.status" @change="apply" aria-label="Statut">
          <option value="">Tous les statuts</option>
          <option value="successful">Réussies</option>
          <option value="failed">Échouées</option>
          <option value="reversed">Rejetées</option>
          <option value="processing">En attente</option>
        </select>
        <select v-model="f.days" @change="f.from = ''; f.to = ''; apply()" aria-label="Période">
          <option value="">{{ f.from || f.to ? 'Période personnalisée' : 'Toute la période' }}</option>
          <option value="1">Aujourd'hui</option>
          <option value="7">7 derniers jours</option>
          <option value="14">14 derniers jours</option>
          <option value="30">30 derniers jours</option>
          <option value="90">90 derniers jours</option>
        </select>
        <label class="date-range">
          <span>Du</span>
          <input v-model="f.from" type="date" :max="f.to || today" @change="f.days = ''; apply()" aria-label="Date de début" />
          <span>au</span>
          <input v-model="f.to" type="date" :min="f.from" :max="today" @change="f.days = ''; apply()" aria-label="Date de fin" />
        </label>
        <input v-model="f.q" type="search" placeholder="Référence ou numéro…" @keyup.enter="apply" />
        <button class="btn-normal" @click="apply">Rechercher</button>
        <button v-if="hasFilter" class="btn-link" @click="reset">Effacer les filtres</button>
      </div>
    </section>

    <section class="container">
      <div class="container-head">
        <h3>Résultats <span class="counter">({{ meta?.total ?? 0 }})</span></h3>
      </div>
      <div class="container-body flush" style="overflow-x: auto;">
        <table>
          <thead>
            <tr><th>Référence</th><th>Opération</th><th>Canal</th><th>Opérateurs / passerelle</th><th>Expéditeur</th><th>Bénéficiaire</th><th class="num">Montant</th><th class="num">Frais</th><th>Statut</th><th>Date</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="t in transactions" :key="t.id" style="cursor:pointer" @click="$router.push('/transactions/' + t.id)">
              <td class="mono">{{ t.reference }}</td>
              <td>
                <span :style="{ color: t.journal?.flow === 'in' ? 'var(--success, #15803d)' : 'var(--danger, #dc2626)', fontWeight: 800 }">{{ t.journal?.arrow }}</span>
                <strong> {{ t.journal?.label }}</strong><br />
                <small style="color:var(--text-2)">{{ t.journal?.direction_label }} · {{ t.journal?.channel }}</small>
                <small v-if="t.journal?.partner" style="display:block;color:var(--text-2)">via <strong>{{ t.journal.partner }}</strong></small>
              </td>
              <td>{{ t.channel_label }}</td>
              <td class="gw">
                <div><span class="gw-k">Débité</span> {{ t.gateway?.in.operator || '—' }}<em v-if="t.gateway?.in.partner" :class="'p-' + t.gateway.in.rail">{{ t.gateway.in.partner }}</em></div>
                <div><span class="gw-k">Crédité</span> {{ t.gateway?.out.operator || '—' }}<em v-if="t.gateway?.out.partner" :class="'p-' + t.gateway.out.rail">{{ t.gateway.out.partner }}</em></div>
                <small v-if="t.gateway?.track_ids?.length" class="mono" :title="t.gateway.track_ids.join(', ')">{{ t.gateway.track_ids[t.gateway.track_ids.length - 1] }}</small>
              </td>
              <td><strong>{{ t.sender?.name || '—' }}</strong><br /><small class="mono" style="color:var(--text-2)">{{ t.sender?.account || t.source_rail }}</small></td>
              <td><strong>{{ t.beneficiary?.name || '—' }}</strong><br /><small class="mono" style="color:var(--text-2)">{{ t.beneficiary?.account || t.destination_rail }}</small></td>
              <td class="num">{{ money(t.amount, t.currency) }}</td>
              <td class="num">{{ money(t.fee, t.currency) }}</td>
              <td><span class="status" :class="statusClass(t.status)">{{ STATUS[t.status] || t.status }}</span></td>
              <td>{{ new Date(t.created_at).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) }}</td>
              <td @click.stop><button v-if="t.refundable > 0" class="btn-normal btn-sm" title="Rembourser le client par PEEX (mobile money ou compte bancaire)" @click="refundTx = t.id">Rembourser</button></td>
            </tr>
            <tr v-if="!loading && !transactions.length"><td colspan="11" class="stat-label">Aucune transaction pour ces filtres.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="container-foot pager" v-if="meta && meta.last_page > 1">
        <button class="btn-normal" :disabled="!meta.prev_page_url" @click="load(meta.current_page - 1)">Précédent</button>
        <span style="margin:0 12px;">Page {{ meta.current_page }} / {{ meta.last_page }}</span>
        <button class="btn-normal" :disabled="!meta.next_page_url" @click="load(meta.current_page + 1)">Suivant</button>
      </div>
    </section>
    <PeexRefund v-if="refundTx" :transaction-id="refundTx" @close="refundTx = null" @done="load(meta?.current_page || 1)" />
  </div>
</template>

<script setup>
import PeexRefund from '../components/PeexRefund.vue'
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'

// Même classement que le tableau de bord (App\Support\TransactionChannels)
const FAMILIES = {
  withdrawals: { label: 'Retraits', channels: ['withdrawal_qr', 'withdrawal_wallet', 'bank_transfer'] },
  deposits: { label: 'Recharges', channels: ['deposit_agent', 'deposit_card', 'deposit_mm'] },
  payments: { label: 'Paiements', channels: ['payment_wallet', 'payment_mm', 'payment_bank'] },
  transfers: { label: 'Transferts & autres', channels: ['transfer_wallet', 'transfer_to_mm', 'interop', 'card_transfer', 'agent_float'] },
}
const CHANNELS = {
  withdrawal_qr: 'Retrait QR code / bon de retrait', withdrawal_wallet: 'Retrait wallet', bank_transfer: 'Virement bancaire',
  deposit_agent: 'Recharge cash agent', deposit_card: 'Recharge carte prépayée / bancaire', deposit_mm: 'Recharge mobile money → wallet',
  payment_wallet: 'Paiement wallet', payment_mm: 'Paiement mobile money', payment_bank: 'Paiement banque / carte',
  transfer_wallet: 'Wallet → wallet', transfer_to_mm: 'Wallet → mobile money', interop: 'Interopérabilité', card_transfer: 'Envoi payé par carte', agent_float: 'Float agent',
}
const STATUS = { successful: 'Réussie', processing: 'En attente', failed: 'Échouée', reversed: 'Rejetée' }

const route = useRoute()
const router = useRouter()
const transactions = ref([])
const meta = ref(null)
const loading = ref(false)
const refundTx = ref(null)
const f = reactive({ channel: '', status: '', days: '', from: '', to: '', q: '', user: '' })
const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const frDate = (s) => new Date(s + 'T00:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })

const hasFilter = computed(() => !!(f.channel || f.status || f.days || f.from || f.to || f.q || f.user))
const subtitle = computed(() => {
  const parts = []
  if (f.user) parts.push(meta.value?.user_name ? `Compte : ${meta.value.user_name}` : `Compte n° ${f.user}`)
  if (f.channel) parts.push(FAMILIES[f.channel]?.label || CHANNELS[f.channel] || f.channel)
  if (f.status) parts.push(({ successful: 'réussies', processing: 'en attente', failed: 'échouées', reversed: 'rejetées' })[f.status] || f.status)
  if (f.from && f.to) parts.push(`du ${frDate(f.from)} au ${frDate(f.to)}`)
  else if (f.from) parts.push(`depuis le ${frDate(f.from)}`)
  else if (f.to) parts.push(`jusqu'au ${frDate(f.to)}`)
  else if (f.days) parts.push(f.days === '1' ? "aujourd'hui" : `${f.days} derniers jours`)
  return parts.length ? parts.join(' · ') : 'Toutes les transactions de la plateforme'
})

function syncFromRoute() {
  f.channel = route.query.channel || ''
  f.status = route.query.status || ''
  f.days = route.query.days ? String(route.query.days) : ''
  f.from = route.query.from || ''
  f.to = route.query.to || ''
  f.q = route.query.q || ''
  f.user = route.query.user || ''
}

function apply() {
  const query = Object.fromEntries(Object.entries(f).filter(([, v]) => v))
  router.replace({ path: '/transactions', query })
}
function reset() {
  router.replace({ path: '/transactions' })
}

async function load(page = 1) {
  loading.value = true
  try {
    const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v))
    const { data } = await api.get('/admin/transactions', { params: { ...params, page } })
    transactions.value = data.data
    meta.value = data
  } finally {
    loading.value = false
  }
}

const nf = new Intl.NumberFormat('fr-FR')
const money = (n, cur) => nf.format(n || 0) + ' ' + (cur || 'XAF')
const statusClass = (s) => ({ successful: 'ok', processing: 'pending', failed: 'err', reversed: 'warn' }[s] || 'muted')

watch(() => route.query, () => { syncFromRoute(); load() }, { immediate: true })

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Référence', value: (t) => t.reference },
  { label: 'Opération', value: (t) => t.journal?.label },
  { label: 'Sens', value: (t) => t.journal?.direction_label },
  { label: 'Type', value: (t) => t.journal?.channel },
  { label: 'Partenaire', value: (t) => t.journal?.partner || 'Interne FlashPay' },
  { label: 'Opérateur débité', value: (t) => t.gateway?.in.operator },
  { label: 'Opérateur crédité', value: (t) => t.gateway?.out.operator },
  { label: 'Track ID PEEX', value: (t) => (t.gateway?.track_ids || []).join(' ') },
  { label: 'Canal', value: (t) => t.channel_label },
  { label: 'Type', value: (t) => t.type },
  { label: 'Expéditeur', value: (t) => t.sender?.name },
  { label: 'Compte expéditeur', value: (t) => t.sender?.account || t.source_rail },
  { label: 'Bénéficiaire', value: (t) => t.beneficiary?.name },
  { label: 'Compte bénéficiaire', value: (t) => t.beneficiary?.account || t.destination_rail },
  { label: 'Montant', value: (t) => t.amount },
  { label: 'Frais', value: (t) => t.fee },
  { label: 'Commission marchand', value: (t) => t.merchant_fee },
  { label: 'Devise', value: (t) => t.currency },
  { label: 'Montant reçu', value: (t) => t.destination_amount ?? '' },
  { label: 'Devise reçue', value: (t) => t.destination_currency ?? '' },
  { label: 'Statut', value: (t) => STATUS[t.status] || t.status },
  { label: 'Motif échec', value: (t) => t.failure_reason },
  { label: 'Date', value: (t) => fmtDate(t.created_at) },
  { label: 'Finalisée le', value: (t) => fmtDate(t.completed_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/transactions', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), onP)
</script>

<style scoped>
.gw { font-size: 12.5px; min-width: 190px; }
.gw div { white-space: nowrap; }
.gw-k { display: inline-block; width: 52px; color: var(--text-2); font-size: 11px; }
.gw em { font-style: normal; font-size: 10.5px; font-weight: 700; margin-left: 6px; padding: 1px 6px; border-radius: 5px; background: #e0e7ff; color: #1e3a8a; }
.gw em.p-digitwace { background: #ffedd5; color: #c2410c; }
.gw small { color: var(--text-2); font-size: 11px; }
.btn-sm { padding: 4px 10px; font-size: 12.5px; }
.filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.filters select, .filters input { padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
.filters input[type=search] { min-width: 220px; }
.date-range { display: inline-flex; align-items: center; gap: 6px; }
.date-range span { color: var(--text-2); font-size: 13px; }
.date-range input { min-width: 0; }
.btn-link { background: none; border: 0; color: var(--link); cursor: pointer; font: inherit; }
</style>
