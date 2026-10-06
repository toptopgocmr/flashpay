<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Transactions</h1>
        <p v-if="subtitle">{{ subtitle }}</p>
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
        <div class="cost-sum" v-if="meta?.cost_totals" title="Totaux de la page affichée (transactions réussies)">
          <span>Facturé FlashPay <b>{{ n(meta.cost_totals.billed) }}</b></span>
          <span>Frais partenaires <b class="neg">−{{ n(meta.cost_totals.partner_total) }}</b></span>
          <span>Marge <b :class="meta.cost_totals.margin < 0 ? 'neg' : 'pos'">{{ n(meta.cost_totals.margin) }}</b></span>
          <button class="btn-link" @click="showRates = !showRates">Tarifs partenaires</button>
        </div>
      </div>
      <div v-if="showRates" class="rates">
        <p class="stat-label">Frais appliqués quand PEEX / WacePay ne renvoient pas leurs frais dans la réponse (affichés « estimé »). Les frais communiqués par le partenaire sont toujours prioritaires.</p>
        <div class="rate-grid">
          <template v-for="(r, k) in rates" :key="k">
            <label>{{ r.label }}</label>
            <span><input v-model.number="r.pct" type="number" min="0" max="50" step="0.01" /> %</span>
            <span>+ <input v-model.number="r.fixed" type="number" min="0" step="1" /> fixe</span>
          </template>
        </div>
        <button class="btn" @click="saveRates" :disabled="savingRates">{{ savingRates ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </div>
      <div class="container-body flush" style="overflow-x: auto;">
        <table class="tx-table">
          <thead>
            <tr><th>Opération</th><th>Parcours</th><th>Expéditeur → bénéficiaire</th><th class="num">Montant</th><th class="num" title="Frais facturés par FlashPay − frais PEEX / WacePay">Frais &amp; marge</th><th>Statut</th><th>Date</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="t in transactions" :key="t.id" style="cursor:pointer" @click="$router.push('/transactions/' + t.id)">
              <td class="c-op">
                <div class="op-l"><b :class="t.journal?.flow === 'in' ? 'in' : 'out'">{{ t.journal?.arrow }}</b> {{ t.journal?.label }}</div>
                <small class="mono">{{ t.reference }}</small>
              </td>
              <td class="c-gw" :title="gwTitle(t)">
                <div class="gw-line">
                  <span>{{ short(t.gateway?.in.operator) }}</span><em v-if="t.gateway?.in.partner" :class="'p-' + t.gateway.in.rail">{{ badge(t.gateway.in.partner) }}</em>
                  <span class="arr">→</span>
                  <span>{{ short(t.gateway?.out.operator) }}</span><em v-if="t.gateway?.out.partner" :class="'p-' + t.gateway.out.rail">{{ badge(t.gateway.out.partner) }}</em>
                </div>
                <small>{{ t.channel_label }}</small>
              </td>
              <td class="c-pt">
                <div :title="t.sender?.account"><span class="who">↑</span>{{ t.sender?.name || t.sender?.account || '—' }}</div>
                <div :title="t.beneficiary?.account"><span class="who">↓</span>{{ t.beneficiary?.name || t.beneficiary?.account || '—' }}</div>
              </td>
              <td class="num c-am"><b>{{ money(t.amount, t.currency) }}</b><small v-if="t.fee">frais {{ n(t.fee) }}</small></td>
              <td class="num c-cost" :title="costTitle(t)">
                <template v-if="t.costs">
                  <div>FlashPay <b>{{ n(t.costs.billed) }}</b></div>
                  <div v-for="l in t.costs.legs" :key="l.kind + l.reference" class="leg">{{ l.partner }} {{ l.kind === 'collect' ? 'coll.' : l.kind === 'payout' ? 'vers.' : 'remb.' }}
                    <b v-if="l.fee !== null">−{{ n(l.fee) }}</b><i v-else>?</i><sup v-if="l.fee_source === 'estimate'">est.</sup></div>
                  <div class="mg" :class="t.costs.margin < 0 ? 'neg' : 'pos'">Marge {{ n(t.costs.margin) }}</div>
                </template>
              </td>
              <td><span class="status" :class="statusClass(t.status)">{{ STATUS[t.status] || t.status }}</span></td>
              <td class="c-dt">{{ dt(t.created_at) }}</td>
              <td class="c-act" @click.stop>
                <IconAction icon="eye" label="Détails" :to="'/transactions/' + t.id" />
                <IconAction v-if="t.refundable > 0" icon="undo" tone="accent" label="Rembourser via PEEX" @click="refundTx = t.id" />
              </td>
            </tr>
            <tr v-if="!loading && !transactions.length"><td colspan="8" class="stat-label">Aucune transaction pour ces filtres.</td></tr>
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
import IconAction from '../components/IconAction.vue'
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
const n = (v) => Number(v || 0).toLocaleString('fr-FR')
const dt = (d) => new Date(d).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).replace(',', '')
// Libellés courts : « MTN Mobile Money » → « MTN », « Wallet FlashPay » → « Wallet »
const short = (op) => (op || '—').replace(/ Mobile Money| Money|Wallet FlashPay|Carte Visa \/ Mastercard/g, (m) => ({ 'Wallet FlashPay': 'Wallet', 'Carte Visa / Mastercard': 'Carte' }[m] ?? '')).trim()
const badge = (p) => (p || '').replace(/ \(.*\)/, '').replace('Carte (3-D Secure)', '3DS')
const gwTitle = (t) => {
  const g = t.gateway
  if (!g) return ''
  return `Débité : ${g.in.operator || '—'}${g.in.partner ? ' via ' + g.in.partner : ''}\nCrédité : ${g.out.operator || '—'}${g.out.partner ? ' via ' + g.out.partner : ''}${g.track_ids?.length ? '\nPEEX : ' + g.track_ids.join(', ') : ''}`
}
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
  return parts.length ? parts.join(' · ') : ''
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
const costTitle = (t) => {
  const c = t.costs
  if (!c) return ''
  const lines = [`Facturé FlashPay : ${n(c.billed)} ${c.currency} (frais client ${n(c.client_fee)}${c.merchant_fee ? ', commission marchand ' + n(c.merchant_fee) : ''})`]
  for (const l of c.legs) lines.push(`${l.label} via ${l.partner}${l.reference ? ' (' + l.reference + ')' : ''} : ${l.fee === null ? 'frais non communiqués' : n(l.fee) + ' ' + l.currency + (l.fee_source === 'estimate' ? ' (estimé)' : '')}`)
  lines.push(`Marge FlashPay : ${n(c.margin)} ${c.currency}`)
  return lines.join('\n')
}
const showRates = ref(false)
const rates = ref({})
const savingRates = ref(false)
watch(showRates, async (v) => { if (v) rates.value = (await api.get('/admin/settings/partner-fees')).data })
async function saveRates() {
  savingRates.value = true
  try {
    rates.value = (await api.post('/admin/settings/partner-fees', { rates: rates.value })).data
    await load(meta.value?.current_page || 1)
  } finally { savingRates.value = false }
}
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
  { label: 'Facturé FlashPay', value: (t) => t.costs?.billed ?? '' },
  { label: 'Frais PEEX', value: (t) => (t.costs?.legs || []).filter((l) => l.partner === 'PEEX').reduce((a, l) => a + (l.fee || 0), 0) },
  { label: 'Frais WacePay', value: (t) => (t.costs?.legs || []).filter((l) => l.partner === 'WacePay').reduce((a, l) => a + (l.fee || 0), 0) },
  { label: 'Frais partenaires (total)', value: (t) => t.costs?.partner_total ?? '' },
  { label: 'Marge FlashPay', value: (t) => t.costs?.margin ?? '' },
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
.tx-table { font-size: 13px; }
.tx-table th { white-space: nowrap; }
.tx-table td { padding-top: 9px; padding-bottom: 9px; vertical-align: middle; }
.c-op .op-l { font-weight: 700; white-space: nowrap; }
.c-op b.in { color: var(--success, #15803d); } .c-op b.out { color: var(--danger, #dc2626); }
.c-op small, .c-gw small { display: block; color: var(--text-2); font-size: 11px; }
.gw-line { white-space: nowrap; }
.gw-line .arr { color: var(--text-2); margin: 0 4px; }
.gw-line em { font-style: normal; font-size: 10px; font-weight: 700; margin-left: 4px; padding: 0 5px; border-radius: 4px; background: #e0e7ff; color: #1e3a8a; }
.gw-line em.p-digitwace { background: #ffedd5; color: #c2410c; }
.c-pt div { white-space: nowrap; max-width: 260px; overflow: hidden; text-overflow: ellipsis; }
.c-pt .who { display: inline-block; width: 14px; color: var(--text-2); font-weight: 700; }
.c-am b { white-space: nowrap; } .c-am small { display: block; color: var(--text-2); font-size: 11px; }
.c-cost { font-size: 11px; white-space: nowrap; color: var(--text-2); }
.c-cost b { color: var(--text); } .c-cost .leg b { color: #b91c1c; } .c-cost i { font-style: normal; color: #a16207; } .c-cost sup { font-size: 9px; margin-left: 2px; }
.c-cost .mg { font-weight: 700; } .pos { color: #15803d !important; } .neg { color: #b91c1c !important; }
.cost-sum { display: flex; gap: 14px; align-items: center; flex-wrap: wrap; font-size: 13px; color: var(--text-2); }
.rates { padding: 12px 16px; border-bottom: 1px solid var(--border, #eee); }
.rate-grid { display: grid; grid-template-columns: minmax(200px, auto) auto auto; gap: 6px 12px; align-items: center; margin: 8px 0 12px; font-size: 13px; }
.rate-grid input { width: 80px; padding: 5px 8px; border: 1px solid var(--border-strong); border-radius: 6px; font: inherit; }
.c-dt { white-space: nowrap; color: var(--text-2); }
.c-act { white-space: nowrap; text-align: right; }
.filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.filters select, .filters input { padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
.filters input[type=search] { min-width: 220px; }
.date-range { display: inline-flex; align-items: center; gap: 6px; }
.date-range span { color: var(--text-2); font-size: 13px; }
.date-range input { min-width: 0; }
.btn-link { background: none; border: 0; color: var(--link); cursor: pointer; font: inherit; }
</style>
