<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Grille tarifaire</h1>
      </div>
      <div class="actions">
        <button class="btn-normal" @click="load">Actualiser</button>
        <button class="btn accent" @click="edit()">+ Ajouter un palier</button>
      </div>
    </div>

    <div v-if="error" class="flash err"><div>{{ error }}</div></div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>

    <!-- Indicateurs -->
    <div class="kpis mb">
      <div class="kpi" v-go="() => showTiers('1')" title="Voir les paliers actifs"><span>Paliers actifs</span><b>{{ n(kpi.active) }} <small>/ {{ n(kpi.tiers) }}</small></b></div>
      <div class="kpi" v-go="'#tariff-matrix'" title="Voir la vue d'ensemble"><span>Opérations gratuites</span><b>{{ n(kpi.free_operations) }} <small>/ {{ Object.keys(operations).length }}</small></b></div>
      <div class="kpi" v-go="{ path: '/transactions', query: { status: 'successful', days: '30' } }" title="Voir les transactions réussies des 30 derniers jours"><span>Frais perçus (30 j)</span><b>{{ short(kpi.fees_30d) }} <small>XAF</small></b></div>
      <div class="kpi" v-go="'#tariff-sim'" title="Ouvrir le simulateur"><span>Taux de prélèvement moyen</span><b>{{ kpi.volume_30d ? (kpi.fees_30d * 100 / kpi.volume_30d).toFixed(2).replace('.', ',') + ' %' : '—' }}</b></div>
    </div>

    <div class="zones mb">
      <span v-for="s in scopes" :key="s" class="zone"><b>{{ scopeLabel[s] }}</b> {{ scopeHint[s] }}</span>
    </div>

    <div class="layout mb">
      <!-- Matrice par famille -->
      <section id="tariff-matrix" class="container">
        <div class="container-head"><div><h3>Vue d'ensemble</h3></div></div>
        <div class="container-body flush" style="overflow-x:auto;">
          <table class="matrix">
            <thead>
              <tr><th>Opération</th><th>Payé par</th><th v-for="s in scopes" :key="s" class="c">{{ scopeLabel[s] }}</th><th class="num">Frais 30 j</th></tr>
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
                      <small v-if="tiersOf(op, s).length > 1">+{{ tiersOf(op, s).length - 1 }} palier(s)</small>
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

      <!-- Simulateur -->
      <section id="tariff-sim" class="container sim">
        <div class="container-head"><div><h3>Simulateur</h3></div></div>
        <div class="container-body">
          <label class="field">Opération</label>
          <select v-model="sim.op"><option v-for="(l, k) in operations" :key="k" :value="k">{{ l }}</option></select>
          <label class="field">Montant (XAF)</label>
          <input v-model.number="sim.amount" type="number" min="1" />
          <div class="quick"><button v-for="a in [5000, 25000, 100000, 500000]" :key="a" type="button" @click="sim.amount = a">{{ short(a) }}</button></div>
          <table class="simtab">
            <thead><tr><th>Zone</th><th class="num">Frais</th><th class="num">{{ sim.op === 'merchant_fee' ? 'Net marchand' : 'Total débité' }}</th></tr></thead>
            <tbody>
              <tr v-for="s in scopes" :key="s">
                <td>{{ scopeLabel[s] }}</td>
                <td class="num"><b>{{ n(compute(sim.op, s, sim.amount || 0)) }}</b></td>
                <td class="num">{{ n(sim.op === 'merchant_fee' ? sim.amount - compute(sim.op, s, sim.amount || 0) : sim.amount + compute(sim.op, s, sim.amount || 0)) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- Tous les paliers -->
    <section id="tariff-list" class="container">
      <div class="container-head">
        <h3>Tous les paliers <span class="counter">({{ filtered.length }})</span></h3>
        <div class="filters">
          <select v-model="flt.op"><option value="">Toutes les opérations</option><option v-for="(l, k) in operations" :key="k" :value="k">{{ l }}</option></select>
          <select v-model="flt.scope"><option value="">Toutes les zones</option><option v-for="s in scopes" :key="s" :value="s">{{ scopeLabel[s] }}</option></select>
          <select v-model="flt.active"><option value="">Tous</option><option value="1">Actifs</option><option value="0">Inactifs</option></select>
        </div>
      </div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Opération</th><th>Zone</th><th>Tranche (montant)</th><th>Frais</th><th>Min / max</th><th>Exemple</th><th>Actif</th><th></th></tr></thead>
          <tbody>
            <tr v-for="t in filtered" :key="t.id" :class="{ off: !t.active }">
              <td>{{ operations[t.operation_type] || t.operation_type }}</td>
              <td><span class="zone-tag" :class="t.scope">{{ scopeLabel[t.scope] }}</span></td>
              <td class="mono">{{ n(t.min_amount) }} – {{ t.max_amount >= 1e9 ? '∞' : n(t.max_amount) }}</td>
              <td><strong :class="{ 't-ok': isFree(t) }">{{ feeText(t) }}</strong></td>
              <td class="stat-label">{{ t.min_fee ? n(t.min_fee) : '—' }} / {{ t.max_fee ? n(t.max_fee) : '—' }}</td>
              <td class="stat-label">{{ exampleText(t) }}</td>
              <td><label class="switch"><input type="checkbox" :checked="t.active" @change="toggle(t, $event.target.checked)" /><span></span></label></td>
              <td style="white-space:nowrap;">
                <IconAction icon="edit" label="Modifier" @click="edit(t)" />
                <IconAction icon="copy" label="Dupliquer (palier suivant)" @click="edit({ ...t, id: undefined, min_amount: t.max_amount + 1, max_amount: 1000000000 })" />
                <IconAction icon="trash" tone="danger" label="Supprimer" @click="remove(t)" />
              </td>
            </tr>
            <tr v-if="!filtered.length"><td colspan="8" class="stat-label">Aucun palier.</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Paliers d'une case -->
    <Modal v-if="cell" :title="operations[cell.op]" :subtitle="'Zone ' + scopeLabel[cell.scope].toLowerCase() + ' · ' + (cell.op === 'merchant_fee' ? 'payé par le marchand' : 'payé par le client')" @close="cell = null">
      <table v-if="tiersOf(cell.op, cell.scope, true).length" class="simtab">
        <thead><tr><th>Tranche</th><th>Frais</th><th>Actif</th><th></th></tr></thead>
        <tbody>
          <tr v-for="t in tiersOf(cell.op, cell.scope, true)" :key="t.id">
            <td class="mono">{{ n(t.min_amount) }} – {{ t.max_amount >= 1e9 ? '∞' : n(t.max_amount) }}</td>
            <td><b>{{ feeText(t) }}</b></td>
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
    <Modal v-if="form" :title="form.id ? 'Modifier le palier' : 'Nouveau palier'" :subtitle="operations[form.operation_type]" @close="form = null">
      <div class="row2">
        <div><label class="field">Opération</label><select v-model="form.operation_type"><option v-for="(l, k) in operations" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="field">Zone</label><select v-model="form.scope"><option v-for="s in scopes" :key="s" :value="s">{{ scopeLabel[s] }}</option></select></div>
      </div>
      <div class="row2">
        <div><label class="field">Montant min (XAF)</label><input v-model.number="form.min_amount" type="number" min="0" /></div>
        <div><label class="field">Montant max (XAF)</label><input v-model.number="form.max_amount" type="number" min="1" /><div class="hint">1 000 000 000 = sans limite</div></div>
      </div>
      <div class="row2">
        <div><label class="field">Type de frais</label><select v-model="form.fee_type"><option value="percent">Pourcentage</option><option value="fixed">Montant fixe</option></select></div>
        <div><label class="field">Valeur {{ form.fee_type === 'percent' ? '(%)' : '(XAF)' }}</label><input v-model.number="form.fee_value" type="number" step="0.01" min="0" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Frais minimum (XAF)</label><input v-model.number="form.min_fee" type="number" min="0" /></div>
        <div><label class="field">Frais maximum (XAF)</label><input v-model.number="form.max_fee" type="number" min="0" placeholder="Aucun plafond" /></div>
      </div>
      <label class="check"><input v-model="form.active" type="checkbox" /> Palier actif</label>
      <div class="preview">
        <span>Aperçu</span>
        <div v-for="a in previewAmounts" :key="a"><span>{{ n(a) }} XAF</span><b>{{ n(feeOf(form, a)) }} XAF de frais</b></div>
      </div>
      <template #foot>
        <button class="btn-normal" @click="form = null">Annuler</button>
        <button class="btn" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
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
const flt = reactive({ op: '', scope: '', active: '' })
function showTiers(active) {
  flt.op = ''; flt.scope = ''; flt.active = flt.active === active ? '' : active
  const el = document.getElementById('tariff-list'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' })
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

const filtered = computed(() => tariffs.value.filter((t) => (!flt.op || t.operation_type === flt.op) && (!flt.scope || t.scope === flt.scope) && (flt.active === '' || String(+t.active) === flt.active)))
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
  return [lo, Math.max(lo, 25000), Math.max(lo, 100000)].filter((a, i, arr) => a <= form.value.max_amount && arr.indexOf(a) === i)
})
const nextMin = (op, scope) => { const t = tiersOf(op, scope, true); return t.length ? Math.min(t[t.length - 1].max_amount + 1, 999999999) : 0 }

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
    error.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || e.message)
    form.value = null
  } finally {
    saving.value = false
  }
}
async function toggle(t, active) {
  try { await api.post(`/admin/tariffs/${t.id}/active`, { active }); t.active = active; await load() } catch (e) { error.value = e.response?.data?.message || e.message }
}
async function remove(t) {
  if (!window.confirm(`Supprimer ce palier (${operations.value[t.operation_type]}, ${scopeLabel[t.scope]}) ?`)) return
  await api.delete(`/admin/tariffs/${t.id}`)
  msg.value = 'Palier supprimé.'
  await load()
}

onMounted(load)
</script>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; box-shadow: var(--shadow); display: grid; gap: 2px; }
.kpi span { color: var(--text-2); font-size: 12.5px; }
.kpi b { font-size: 24px; }
.kpi small { font-size: 13px; color: var(--text-2); font-weight: 500; }
.zones { display: flex; flex-wrap: wrap; gap: 8px; }
.zone { font-size: 12.5px; color: var(--text-2); background: var(--surface); border: 1px solid var(--border); border-radius: 99px; padding: 4px 12px; }
.zone b { color: var(--text); margin-right: 4px; }
.layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
.layout .container { margin: 0; }
@media (max-width: 1200px) { .layout { grid-template-columns: 1fr; } }
.matrix .c { text-align: center; }
.matrix th, .matrix td { padding-left: 8px; padding-right: 8px; }
.matrix .grp td { background: var(--surface-2); font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-2); padding-top: 6px; padding-bottom: 6px; }
.cell { background: none; border: 1px solid transparent; border-radius: 8px; padding: 4px 10px; cursor: pointer; font: inherit; display: inline-flex; flex-direction: column; align-items: center; min-width: 90px; }
.cell:hover { border-color: var(--link); background: var(--info-bg); }
.cell small { font-size: 11px; color: var(--text-2); }
.fee { font-weight: 700; color: var(--text); }
.fee.free, .t-ok { color: var(--ok); }
.inherit { color: var(--text-3, #9aa3b2); font-size: 13px; }
.payer { font-size: 12px; padding: 2px 8px; border-radius: 99px; }
.payer.c { background: var(--info-bg); color: var(--link); }
.payer.m { background: var(--warn-bg); color: var(--warn); }
.sim select, .sim input { width: 100%; margin-bottom: 10px; }
.quick { display: flex; gap: 6px; margin: -4px 0 12px; }
.quick button { flex: 1; border: 1px solid var(--border-strong); background: #fff; border-radius: 8px; padding: 4px; cursor: pointer; font: inherit; font-size: 12px; }
.quick button:hover { border-color: var(--link); }
.simtab { width: 100%; font-size: 13.5px; }
.filters { display: flex; gap: 8px; flex-wrap: wrap; }
.filters select { padding: 6px 8px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
.zone-tag { font-size: 12px; padding: 2px 8px; border-radius: 99px; background: #f1f5f9; }
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
.preview { background: var(--surface-2); border: 1px dashed var(--border-strong); border-radius: 10px; padding: 10px 14px; display: grid; gap: 4px; font-size: 13px; }
.preview > span { font-size: 11.5px; font-weight: 700; text-transform: uppercase; color: var(--text-2); }
.preview div { display: flex; justify-content: space-between; }
</style>
