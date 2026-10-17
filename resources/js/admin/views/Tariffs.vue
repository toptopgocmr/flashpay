<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Grille tarifaire</h1>
        <p>Frais facturés par opération, par zone (national, régional, international) et par tranche de montant.</p>
      </div>
      <div class="actions">
        <router-link class="btn-normal" to="/remit-tariffs">Tarifs internationaux</router-link>
        <button class="btn-normal" @click="load">Actualiser</button>
      </div>
    </div>

    <div v-if="error" class="flash err"><div>{{ error }}</div></div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>

    <!-- Indicateurs -->
    <div class="stats mb">
      <div v-go="() => showTiers('1')" title="Voir les paliers actifs"><span>Paliers actifs</span><b>{{ n(kpi.active) }}<small> / {{ n(kpi.tiers) }}</small></b></div>
      <div v-go="() => (tab = 'overview')" title="Voir la vue d'ensemble"><span>Opérations gratuites</span><b>{{ n(kpi.free_operations) }}<small> / {{ Object.keys(operations).length }}</small></b></div>
      <div v-go="{ path: '/transactions', query: { status: 'successful', days: '30' } }" title="Transactions réussies des 30 derniers jours"><span>Frais perçus (30 j)</span><b>{{ short(kpi.fees_30d) }}<small> XAF</small></b></div>
      <div><span>Prélèvement moyen</span><b>{{ kpi.volume_30d ? (kpi.fees_30d * 100 / kpi.volume_30d).toFixed(2).replace('.', ',') + ' %' : '—' }}</b></div>
    </div>

    <!-- Zones -->
    <div class="legend mb">
      <span v-for="s in scopes" :key="s" class="lg"><span class="zone-tag" :class="s">{{ scopeLabel[s] }}</span> {{ scopeHint[s] }}</span>
      <span class="lg muted">Sans palier régional ou international : le tarif national s'applique.</span>
      <router-link class="lg link" to="/remit-tariffs">Transferts internationaux (WacePay, TerraPay, PawaPay) : grille à part →</router-link>
    </div>

    <div class="tabs mb">
      <button :class="{ on: tab === 'overview' }" @click="tab = 'overview'">Vue d'ensemble</button>
      <button :class="{ on: tab === 'list' }" @click="tab = 'list'">Paliers par opération <span class="tab-count">{{ tariffs.length }}</span></button>
    </div>

    <!-- Barre d'outils -->
    <div class="toolbar mb">
      <template v-if="tab === 'list'">
        <input v-model="flt.q" type="search" placeholder="Rechercher une opération…" />
        <select v-model="flt.scope"><option value="">Toutes les zones</option><option v-for="s in scopes" :key="s" :value="s">{{ scopeLabel[s] }}</option></select>
        <select v-model="flt.active"><option value="">Actifs et inactifs</option><option value="1">Actifs</option><option value="0">Inactifs</option></select>
      </template>
      <span v-else class="stat-label">Cliquez sur une case pour voir ou modifier ses paliers.</span>
      <span class="grow"></span>
      <button class="btn-normal sm" :class="{ on: showSim }" @click="showSim = !showSim">Simulateur</button>
      <button class="btn-normal sm" @click="exportCsv">Excel</button>
      <button class="btn-normal sm" @click="printPage">PDF</button>
      <button class="btn accent sm" @click="edit()">+ Ajouter un palier</button>
    </div>

    <!-- Simulateur -->
    <section v-if="showSim" id="tariff-sim" class="container mb">
      <div class="container-body">
        <div class="sim-form">
          <div><label class="field">Opération</label>
            <select v-model="sim.op"><option v-for="(l, k) in operations" :key="k" :value="k">{{ l }}</option></select></div>
          <div><label class="field">Montant (XAF)</label>
            <input v-model.number="sim.amount" type="number" min="1" />
            <div class="quick"><button v-for="a in [5000, 25000, 100000, 500000]" :key="a" type="button" @click="sim.amount = a">{{ short(a) }}</button></div></div>
          <div class="sim-res">
            <div v-for="s in scopes" :key="s" class="cell-r">
              <span><span class="zone-tag" :class="s">{{ scopeLabel[s] }}</span></span>
              <b>{{ n(compute(sim.op, s, sim.amount || 0)) }} XAF</b>
              <small>{{ sim.op === 'merchant_fee' ? 'Net marchand' : 'Total débité' }} : {{ n(sim.op === 'merchant_fee' ? sim.amount - compute(sim.op, s, sim.amount || 0) : sim.amount + compute(sim.op, s, sim.amount || 0)) }}</small>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ===================== Vue d'ensemble ===================== -->
    <section v-show="tab === 'overview'" id="tariff-matrix" class="container mb">
      <div class="container-body flush" style="overflow-x:auto;">
        <table class="matrix">
          <colgroup><col class="c-op" /><col class="c-payer" /><col v-for="s in scopes" :key="s" class="c-zone" /><col class="c-rev" /></colgroup>
          <thead>
            <tr><th>Opération</th><th>Payé par</th><th v-for="s in scopes" :key="s" class="c"><span class="zone-tag" :class="s">{{ scopeLabel[s] }}</span></th><th class="num">Frais 30 j</th></tr>
          </thead>
          <tbody v-for="g in groups" :key="g.label">
            <tr class="grp"><td :colspan="scopes.length + 3">{{ g.label }}</td></tr>
            <tr v-for="op in g.ops" :key="op">
              <td><strong>{{ operations[op] }}</strong></td>
              <td><span class="payer" :class="op === 'merchant_fee' ? 'm' : 'c'">{{ op === 'merchant_fee' ? 'Marchand' : 'Client' }}</span></td>
              <td v-for="s in scopes" :key="s" class="c">
                <button class="cell" @click="openCell(op, s)">
                  <template v-if="tiersOf(op, s).length">
                    <span class="fee" :class="{ free: isFree(tiersOf(op, s)[0]) }">{{ feeText(tiersOf(op, s)[0]) }}</span>
                    <small v-if="tiersOf(op, s).length > 1">+{{ tiersOf(op, s).length - 1 }} tranche(s)</small>
                    <small v-else-if="tiersOf(op, s)[0].min_fee || tiersOf(op, s)[0].max_fee">{{ minMax(tiersOf(op, s)[0]) }}</small>
                  </template>
                  <span v-else class="inherit">{{ s === 'national' ? 'Gratuit' : '= National' }}</span>
                </button>
              </td>
              <td class="num">{{ revenue[op]?.fees ? short(revenue[op].fees) : '—' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ===================== Paliers par opération ===================== -->
    <div v-show="tab === 'list'" id="tariff-list">
      <section v-for="g in opGroups" :key="g.op" class="container op-card mb">
        <div class="container-head">
          <div class="oh">
            <h3>{{ operations[g.op] || g.op }} <span class="counter">({{ g.rows.length }})</span></h3>
            <div class="oh-meta">
              <span class="payer" :class="g.op === 'merchant_fee' ? 'm' : 'c'">Payé par le {{ g.op === 'merchant_fee' ? 'marchand' : 'client' }}</span>
              <span v-if="revenue[g.op]?.fees" class="stat-label">{{ short(revenue[g.op].fees) }} XAF perçus sur 30 j</span>
            </div>
          </div>
          <button class="btn-normal sm" @click="addForOp(g.op)">+ Palier</button>
        </div>
        <div class="container-body flush" style="overflow-x:auto;">
          <table class="tier-table">
            <colgroup><col class="c-zone" /><col class="c-amt" /><col class="c-amt" /><col class="c-fee" /><col class="c-mm" /><col class="c-ex" /><col class="c-on" /><col class="c-act" /></colgroup>
            <thead><tr><th>Zone</th><th>Tranche A</th><th>Tranche B</th><th>Frais</th><th>Min · max</th><th>Exemple</th><th>Actif</th><th></th></tr></thead>
            <tbody>
              <tr v-for="t in g.rows" :key="t.id" :class="{ off: !t.active }">
                <td><span class="zone-tag" :class="t.scope">{{ scopeLabel[t.scope] }}</span></td>
                <td class="amt">{{ n(t.min_amount) }}</td>
                <td class="amt">{{ t.max_amount >= 1e9 ? 'sans limite' : n(t.max_amount) }}</td>
                <td><strong :class="{ 't-ok': isFree(t) }">{{ feeText(t) }}</strong></td>
                <td class="stat-label">{{ minMax(t) || '—' }}</td>
                <td class="stat-label amt">{{ exampleText(t) }}</td>
                <td><label class="switch"><input type="checkbox" :checked="t.active" @change="toggle(t, $event.target.checked)" /><span></span></label></td>
                <td class="acts">
                  <IconAction icon="edit" label="Modifier" @click="edit(t)" />
                  <IconAction icon="copy" label="Tranche suivante" @click="edit({ ...t, id: undefined, min_amount: t.max_amount + 1, max_amount: 1000000000 })" />
                  <IconAction icon="trash" tone="danger" label="Supprimer" @click="remove(t)" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <div v-if="!opGroups.length" class="container"><div class="container-body stat-label">Aucun palier pour ces filtres.</div></div>
    </div>

    <!-- Paliers d'une case -->
    <Modal v-if="cell" :title="operations[cell.op]" :subtitle="'Zone ' + scopeLabel[cell.scope].toLowerCase() + ' · ' + (cell.op === 'merchant_fee' ? 'payé par le marchand' : 'payé par le client')" @close="cell = null">
      <table v-if="tiersOf(cell.op, cell.scope, true).length" class="simtab">
        <thead><tr><th>Tranche</th><th>Frais</th><th>Min · max</th><th>Actif</th><th></th></tr></thead>
        <tbody>
          <tr v-for="t in tiersOf(cell.op, cell.scope, true)" :key="t.id">
            <td class="amt">{{ n(t.min_amount) }} – {{ t.max_amount >= 1e9 ? 'sans limite' : n(t.max_amount) }}</td>
            <td><b>{{ feeText(t) }}</b></td>
            <td class="stat-label">{{ minMax(t) || '—' }}</td>
            <td><label class="switch"><input type="checkbox" :checked="t.active" @change="toggle(t, $event.target.checked)" /><span></span></label></td>
            <td><IconAction icon="edit" label="Modifier" @click="edit(t)" /></td>
          </tr>
        </tbody>
      </table>
      <p v-else class="hint" style="margin:0;">Aucun palier : {{ cell.scope === 'national' ? 'opération gratuite.' : 'le tarif national s\'applique.' }}</p>
      <template #foot>
        <button class="btn-normal" @click="cell = null">Fermer</button>
        <button class="btn" @click="edit({ operation_type: cell.op, scope: cell.scope, min_amount: nextMin(cell.op, cell.scope) })">+ Ajouter un palier</button>
      </template>
    </Modal>

    <!-- Formulaire -->
    <Modal v-if="form" :title="form.id ? 'Modifier le palier' : 'Nouveau palier'" :subtitle="(operations[form.operation_type] || '') + ' · ' + (form.operation_type === 'merchant_fee' ? 'payé par le marchand' : 'payé par le client')" @close="form = null">
      <div v-if="formError" class="flash err"><div>{{ formError }}</div></div>
      <div class="fsec">
        <div class="fsec-t">1. Opération</div>
        <div class="row2">
          <div><label class="field">Opération</label><select v-model="form.operation_type"><option v-for="(l, k) in operations" :key="k" :value="k">{{ l }}</option></select></div>
          <div><label class="field">Zone</label><select v-model="form.scope"><option v-for="s in scopes" :key="s" :value="s">{{ scopeLabel[s] }} — {{ scopeHint[s] }}</option></select></div>
        </div>
      </div>
      <div class="fsec">
        <div class="fsec-t">2. Tranche (XAF)</div>
        <div class="row2">
          <div><label class="field">Tranche A (de)</label><input v-model.number="form.min_amount" type="number" min="0" /></div>
          <div><label class="field">Tranche B (à)</label>
            <input v-if="form.max_amount < 1e9" v-model.number="form.max_amount" type="number" min="1" />
            <input v-else type="text" disabled placeholder="Sans limite" />
            <label class="check small"><input type="checkbox" :checked="form.max_amount >= 1e9" @change="form.max_amount = $event.target.checked ? 1000000000 : Math.max((form.min_amount || 0) + 1, 50000)" /> Sans limite</label></div>
        </div>
      </div>
      <div class="fsec">
        <div class="fsec-t">3. Frais</div>
        <div class="seg mb-s">
          <button type="button" :class="{ on: form.fee_type === 'percent' }" @click="form.fee_type = 'percent'">Pourcentage</button>
          <button type="button" :class="{ on: form.fee_type === 'fixed' }" @click="form.fee_type = 'fixed'">Montant fixe</button>
          <button type="button" :class="{ on: form.fee_type === 'percent' && Number(form.fee_value) === 0 && !form.min_fee }" @click="form.fee_type = 'percent'; form.fee_value = 0; form.min_fee = 0; form.max_fee = null">Gratuit</button>
        </div>
        <div class="row3">
          <div><label class="field">{{ form.fee_type === 'percent' ? 'Taux (%)' : 'Frais (XAF)' }}</label><input v-model.number="form.fee_value" type="number" step="0.01" min="0" /></div>
          <div><label class="field">Minimum (XAF)</label><input v-model.number="form.min_fee" type="number" min="0" /></div>
          <div><label class="field">Plafond (XAF)</label><input v-model.number="form.max_fee" type="number" min="0" placeholder="Aucun" /></div>
        </div>
      </div>
      <div class="preview">
        <span>Aperçu</span>
        <table class="pv">
          <thead><tr><th>Montant</th><th>Frais</th><th>{{ form.operation_type === 'merchant_fee' ? 'Net marchand' : 'Total débité' }}</th></tr></thead>
          <tbody><tr v-for="a in previewAmounts" :key="a"><td>{{ n(a) }}</td><td><b>{{ n(feeOf(form, a)) }}</b></td><td>{{ n(form.operation_type === 'merchant_fee' ? a - feeOf(form, a) : a + feeOf(form, a)) }}</td></tr></tbody>
        </table>
      </div>
      <label class="check"><input v-model="form.active" type="checkbox" /> Palier actif</label>
      <template #foot>
        <button class="btn-normal" @click="form = null">Annuler</button>
        <button class="btn" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { confirmBox } from '../utils/ui'
import IconAction from '../components/IconAction.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'

const operations = ref({})
const scopes = ref(['national', 'regional', 'international'])
const scopeLabel = { national: 'National', regional: 'Régional', international: 'International' }
const scopeHint = { national: 'même pays', regional: 'même zone (CEMAC ↔ CEMAC, UEMOA ↔ UEMOA)', international: 'entre zones (CEMAC ↔ UEMOA, RDC, Guinée)' }
const GROUPS = [
  { label: 'Envois & transferts', ops: ['p2p'] },
  { label: 'Paiements', ops: ['merchant_payment', 'merchant_fee', 'card'] },
  { label: 'Recharges', ops: ['cash_in'] },
  { label: 'Retraits', ops: ['withdrawal', 'cash_out', 'cash_pickup', 'atm', 'bank_transfer'] },
]
const tariffs = ref([])
const revenue = ref({})
const kpi = ref({})
const flt = reactive({ q: '', op: '', scope: '', active: '' })
const tab = ref('overview')
const showSim = ref(false)
const formError = ref('')
function showTiers(active) {
  flt.q = ''; flt.op = ''; flt.scope = ''; flt.active = flt.active === active ? '' : active
  tab.value = 'list'
}
const form = ref(null)
const cell = ref(null)
const saving = ref(false)
const error = ref('')
const msg = ref('')
const sim = reactive({ op: 'p2p', amount: 25000 })

const groups = computed(() => {
  const known = GROUPS.flatMap((g) => g.ops)
  const others = Object.keys(operations.value).filter((k) => !known.includes(k))
  return [...GROUPS.map((g) => ({ ...g, ops: g.ops.filter((o) => operations.value[o]) })), ...(others.length ? [{ label: 'Autres', ops: others }] : [])].filter((g) => g.ops.length)
})
const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(v || 0)
const short = (v) => (!v ? '0' : v >= 1e6 ? (v / 1e6).toFixed(1).replace('.', ',') + ' M' : v >= 1e4 ? Math.round(v / 1e3) + ' k' : nf.format(v))

const filtered = computed(() => {
  const q = flt.q.trim().toLowerCase()
  return tariffs.value.filter((t) => (!q || (operations.value[t.operation_type] || t.operation_type).toLowerCase().includes(q))
    && (!flt.op || t.operation_type === flt.op) && (!flt.scope || t.scope === flt.scope) && (flt.active === '' || String(+t.active) === flt.active))
})
// Paliers regroupés par opération (ordre de la vue d'ensemble), triés par zone puis tranche
const SCOPE_ORDER = { national: 0, regional: 1, international: 2 }
const opGroups = computed(() => {
  const order = groups.value.flatMap((g) => g.ops)
  const ops = [...new Set(filtered.value.map((t) => t.operation_type))].sort((a, b) => (order.indexOf(a) + 1 || 99) - (order.indexOf(b) + 1 || 99))
  return ops.map((op) => ({
    op,
    rows: filtered.value.filter((t) => t.operation_type === op).sort((a, b) => (SCOPE_ORDER[a.scope] ?? 9) - (SCOPE_ORDER[b.scope] ?? 9) || a.min_amount - b.min_amount),
  }))
})
const minMax = (t) => (t.min_fee || t.max_fee ? `min ${t.min_fee ? n(t.min_fee) : '—'} · max ${t.max_fee ? n(t.max_fee) : '—'}` : '')
const tiersOf = (op, scope, all = false) => tariffs.value.filter((t) => t.operation_type === op && t.scope === scope && (all || t.active)).sort((a, b) => a.min_amount - b.min_amount)
const isFree = (t) => Number(t.fee_value) === 0 && !t.min_fee
const feeText = (t) => (isFree(t) ? 'Gratuit' : t.fee_type === 'percent' ? String(t.fee_value).replace('.', ',') + ' %' : n(t.fee_value) + ' XAF')
const feeOf = (t, amount) => {
  let fee = t.fee_type === 'fixed' ? Math.round(t.fee_value || 0) : Math.ceil((amount * (t.fee_value || 0)) / 100)
  fee = Math.max(fee, t.min_fee || 0)
  if (t.max_fee) fee = Math.min(fee, t.max_fee)
  return fee
}
const exampleText = (t) => { const a = Math.max(t.min_amount, Math.min(10000, t.max_amount)); return `${n(a)} → ${n(feeOf(t, a))}` }
function compute(op, scope, amount) {
  const find = (s) => tariffs.value.find((t) => t.operation_type === op && t.scope === s && t.active && t.min_amount <= amount && t.max_amount >= amount)
  const t = find(scope) || (scope !== 'national' ? find('national') : null)
  return t ? feeOf(t, amount) : 0
}
const previewAmounts = computed(() => {
  if (!form.value) return []
  const lo = Math.max(form.value.min_amount || 0, 1000)
  const hi = Math.min(form.value.max_amount || lo, 1e9)
  return (hi >= 1e9 ? [lo, Math.max(lo, 25000), Math.max(lo, 100000), Math.max(lo, 1000000)] : [lo, Math.round((lo + hi) / 2), hi])
    .filter((a, i, arr) => a <= hi && arr.indexOf(a) === i)
})
function exportCsv() {
  const rows = [...filtered.value].sort((a, b) => (a.operation_type > b.operation_type ? 1 : -1) || (SCOPE_ORDER[a.scope] - SCOPE_ORDER[b.scope]) || a.min_amount - b.min_amount)
    .map((t) => [operations.value[t.operation_type] || t.operation_type, scopeLabel[t.scope], t.min_amount, t.max_amount >= 1e9 ? '' : t.max_amount,
      t.fee_type === 'percent' ? 'Pourcentage' : 'Fixe', t.fee_value, t.min_fee || '', t.max_fee || '', t.active ? 'Actif' : 'Inactif'])
  const head = ['Opération', 'Zone', 'Tranche A', 'Tranche B', 'Type', 'Valeur', 'Minimum', 'Plafond', 'Statut']
  const esc = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"'
  const csv = '\ufeff' + [head, ...rows].map((r) => r.map(esc).join(';')).join('\r\n')
  const a = document.createElement('a')
  a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }))
  a.download = `grille-tarifaire-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  URL.revokeObjectURL(a.href)
}
const printPage = () => window.print()
// Tranche suivante : après la dernière tranche bornée (une tranche « sans limite » couvre déjà tout)
const nextMin = (op, scope) => { const t = tiersOf(op, scope, true).filter((x) => x.max_amount < 1e9); return t.length ? t[t.length - 1].max_amount + 1 : 0 }
// « + Palier » d'une opération : la première zone sans palier (sinon la zone filtrée, ou national)
function addForOp(op) {
  const scope = flt.scope || scopes.value.find((sc) => !tiersOf(op, sc, true).length) || 'national'
  edit({ operation_type: op, scope, min_amount: nextMin(op, scope) })
}

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/admin/tariffs')
    operations.value = data.operations
    scopes.value = data.scopes
    tariffs.value = data.tariffs
    revenue.value = data.revenue || {}
    kpi.value = data.kpi || {}
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  }
}
const openCell = (op, scope) => { cell.value = { op, scope } }
function edit(t = {}) {
  formError.value = ''
  cell.value = null
  form.value = { operation_type: 'p2p', scope: 'national', fee_type: 'percent', fee_value: 1, min_amount: 0, max_amount: 1000000000, min_fee: 0, max_fee: null, active: true, ...t }
}
async function save() {
  saving.value = true
  error.value = ''
  try {
    await api.post('/admin/tariffs', form.value)
    form.value = null
    msg.value = 'Palier enregistré.'
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    formError.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || e.message)
  } finally {
    saving.value = false
  }
}
async function toggle(t, active) {
  try { await api.post(`/admin/tariffs/${t.id}/active`, { active }); t.active = active; await load() } catch (e) { error.value = e.response?.data?.message || e.message }
}
async function remove(t) {
  if (!(await confirmBox(`${operations.value[t.operation_type]} · ${scopeLabel[t.scope]}`, { title: 'Supprimer ce palier de frais ?', confirmLabel: 'Supprimer', danger: true }))) return
  await api.delete(`/admin/tariffs/${t.id}`)
  msg.value = 'Palier supprimé.'
  await load()
}

onMounted(load)
</script>

<style scoped>
.stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); }
.stats > div { padding: 12px 16px; display: grid; gap: 2px; border-left: 1px solid var(--border); }
.stats > div:first-child { border-left: 0; }
.stats > div[title] { cursor: pointer; }
.stats > div[title]:hover { background: var(--surface-2); }
.stats span { font-size: 12px; color: var(--text-2); }
.stats b { font-size: 20px; }
.stats small { font-size: 12px; color: var(--text-2); font-weight: 500; }
@media (max-width: 900px) { .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } .stats > div:nth-child(3) { border-left: 0; } }
.legend { display: flex; flex-wrap: wrap; gap: 6px 16px; align-items: center; font-size: 12.5px; color: var(--text-2); }
.lg { display: inline-flex; gap: 6px; align-items: center; }
.lg.muted { font-style: italic; }
.lg.link { margin-left: auto; color: var(--link); font-weight: 600; }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
.tabs button { border: 0; background: none; padding: 9px 14px; font: inherit; font-weight: 600; color: var(--text-2); cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px; }
.tabs button.on { color: var(--link); border-bottom-color: var(--link); }
.tab-count { font-size: 11px; font-weight: 700; background: var(--surface-2); color: var(--text-2); border-radius: 99px; padding: 1px 7px; margin-left: 4px; }
.toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; min-height: 38px; }
.toolbar input, .toolbar select { padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; background: #fff; }
.toolbar input { min-width: 220px; flex: 0 1 280px; }
.toolbar .grow { flex: 1; }
.btn-normal.sm, .btn.sm { padding: 6px 12px; font-size: 13px; }
.btn-normal.on { border-color: var(--link); color: var(--link); }
.sim-form { display: grid; grid-template-columns: 1.2fr 1fr 2.2fr; gap: 16px; align-items: start; }
.sim-form select, .sim-form input { width: 100%; }
@media (max-width: 1100px) { .sim-form { grid-template-columns: 1fr; } }
.quick { display: flex; gap: 6px; margin-top: 6px; }
.quick button { flex: 1; border: 1px solid var(--border-strong); background: #fff; border-radius: 8px; padding: 4px; cursor: pointer; font: inherit; font-size: 12px; }
.quick button:hover { border-color: var(--link); }
.sim-res { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; padding-top: 22px; }
.cell-r { background: var(--surface-2); border-radius: 10px; padding: 8px 12px; display: grid; gap: 2px; }
.cell-r b { font-size: 17px; color: var(--link); }
.cell-r small { font-size: 12px; color: var(--text-2); }
table.matrix { table-layout: fixed; width: 100% !important; min-width: 760px; }
.matrix col.c-op { width: 26%; }
.matrix col.c-payer { width: 11%; }
.matrix col.c-zone { width: 16%; }
.matrix col.c-rev { width: 15%; }
.matrix .c { text-align: center; }
.matrix th, .matrix td { padding-left: 12px; padding-right: 12px; }
.matrix .grp td { background: var(--surface-2); font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-2); padding-top: 6px; padding-bottom: 6px; }
.cell { background: none; border: 1px solid transparent; border-radius: 8px; padding: 4px 10px; cursor: pointer; font: inherit; display: inline-flex; flex-direction: column; align-items: center; min-width: 110px; }
.cell:hover { border-color: var(--link); background: var(--info-bg); }
.cell small { font-size: 11px; color: var(--text-2); }
.fee { font-weight: 700; color: var(--text); }
.fee.free, .t-ok { color: var(--ok); }
.inherit { color: var(--text-3, #9aa3b2); font-size: 13px; }
.payer { font-size: 12px; padding: 2px 8px; border-radius: 99px; white-space: nowrap; }
.payer.c { background: var(--info-bg); color: var(--link); }
.payer.m { background: var(--warn-bg); color: var(--warn); }
.op-card .container-head { align-items: center; }
.oh { display: grid; gap: 4px; }
.oh h3 { margin: 0; }
.oh-meta { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
table.tier-table { table-layout: fixed; width: 100% !important; min-width: 760px; }
.tier-table col.c-zone { width: 14%; }
.tier-table col.c-amt { width: 12%; }
.tier-table col.c-fee { width: 11%; }
.tier-table col.c-mm { width: 15%; }
.tier-table col.c-ex { width: 12%; }
.tier-table col.c-on { width: 8%; }
.tier-table col.c-act { width: 16%; }
.tier-table td { padding-top: 9px; padding-bottom: 9px; }
td.amt, .amt { font-variant-numeric: tabular-nums; white-space: nowrap; }
td.acts { white-space: nowrap; text-align: right; }
.simtab { width: 100%; font-size: 13.5px; }
.zone-tag { font-size: 12px; padding: 2px 8px; border-radius: 99px; background: #f1f5f9; color: var(--text); font-weight: 600; white-space: nowrap; }
.zone-tag.regional { background: var(--info-bg); color: var(--link); }
.zone-tag.international { background: var(--warn-bg); color: var(--warn); }
tr.off td { color: var(--text-2); background: #fafafa; }
.switch { position: relative; display: inline-block; width: 36px; height: 20px; }
.switch input { opacity: 0; width: 0; height: 0; }
.switch span { position: absolute; inset: 0; background: #cbd2dc; border-radius: 99px; transition: .15s; cursor: pointer; }
.switch span::before { content: ''; position: absolute; width: 16px; height: 16px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: .15s; }
.switch input:checked + span { background: var(--ok); }
.switch input:checked + span::before { transform: translateX(16px); }
.check { display: inline-flex; gap: 8px; align-items: center; }
.check input { width: auto !important; }
.check.small { font-size: 12.5px; margin-top: 4px; }
.fsec { border: 1px solid var(--border); border-radius: 12px; padding: 10px 14px 4px; margin-bottom: 12px; }
.fsec-t { font-size: 12px; font-weight: 700; text-transform: uppercase; color: var(--text-2); margin-bottom: 4px; letter-spacing: .02em; }
.row3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
@media (max-width: 600px) { .row3 { grid-template-columns: 1fr; } }
.seg { display: inline-flex; border: 1px solid var(--border-strong); border-radius: 10px; overflow: hidden; }
.seg button { border: 0; background: #fff; padding: 6px 14px; font: inherit; cursor: pointer; }
.seg button + button { border-left: 1px solid var(--border); }
.seg button.on { background: var(--link); color: #fff; font-weight: 600; }
.mb-s { margin-bottom: 8px; }
.preview { background: var(--surface-2); border: 1px dashed var(--border-strong); border-radius: 10px; padding: 10px 14px; display: grid; gap: 4px; font-size: 13px; margin-bottom: 10px; }
.preview > span { font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: var(--text-2); }
table.pv { width: 100%; font-size: 13px; }
table.pv th { text-align: right; font-size: 11px; color: var(--text-2); font-weight: 700; text-transform: uppercase; padding: 2px 0; }
table.pv td { text-align: right; padding: 2px 0; }
table.pv th:first-child, table.pv td:first-child { text-align: left; }
@media print { .page-header .actions, .toolbar, .tabs, .legend, .stats, .switch, td.acts, .op-card .btn-normal { display: none !important; } #tariff-list { display: block !important; } #tariff-matrix { display: none !important; } }
</style>
