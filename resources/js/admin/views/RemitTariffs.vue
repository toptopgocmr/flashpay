<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Tarifs internationaux</h1>
        <p>Frais FlashPay des transferts internationaux, à part de la grille nationale : par pays de destination, type (P2P, P2B, B2P, B2B), réception et tranche. Le palier le plus précis l'emporte.</p>
      </div>
      <div class="actions">
        <router-link class="btn-normal" to="/tariffs">Grille nationale</router-link>
        <button class="btn-normal" @click="load">Actualiser</button>
        <button class="btn accent" @click="edit()">+ Ajouter un palier</button>
      </div>
    </div>

    <div v-if="error" class="flash err"><div>{{ error }}</div></div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>

    <div class="kpis mb">
      <div class="kpi"><span>Paliers actifs</span><b>{{ n(kpi.active) }} <small>/ {{ n(kpi.tiers) }}</small></b></div>
      <div class="kpi"><span>Pays avec un tarif propre</span><b>{{ n(kpi.countries) }}</b><small>les autres : « Toutes destinations »</small></div>
      <div class="kpi"><span>Frais perçus (30 j)</span><b>{{ short(kpi.fees_30d) }} <small>{{ cur }}</small></b><small>{{ n(kpi.count_30d) }} transfert(s)</small></div>
      <div class="kpi"><span>Taux de prélèvement moyen</span><b>{{ kpi.volume_30d ? (kpi.fees_30d * 100 / kpi.volume_30d).toFixed(2).replace('.', ',') + ' %' : '—' }}</b></div>
    </div>

    <div class="rule mb">
      <b>Ordre de priorité :</b> pays précis &gt; type de transfert &gt; mode de réception &gt; partenaire &gt; « Toutes destinations ».
      Sans aucun palier, le tarif « Envoi d'argent · international » de la grille nationale s'applique.
      Les frais des partenaires (WacePay, TerraPay, PawaPay) sont en plus, selon leurs tarifs.
    </div>

    <div class="layout mb">
      <section class="container">
        <div class="container-head">
          <h3>Paliers <span class="counter">({{ filtered.length }})</span></h3>
          <div class="filters">
            <input v-model="flt.q" type="search" placeholder="Pays…" />
            <select v-model="flt.bt"><option value="">Tous les types</option><option value="*">Tous (*)</option><option v-for="(l, k) in businessTypes" :key="k" :value="k">{{ l }}</option></select>
            <select v-model="flt.active"><option value="">Tous</option><option value="1">Actifs</option><option value="0">Inactifs</option></select>
          </div>
        </div>
        <div class="container-body flush" style="overflow-x:auto;">
          <table>
            <thead><tr><th>Destination</th><th>Type</th><th>Réception</th><th>Partenaire</th><th>Tranche ({{ cur }})</th><th>Frais</th><th>Min / max</th><th>Actif</th><th></th></tr></thead>
            <tbody>
              <tr v-for="t in filtered" :key="t.id" :class="{ off: !t.active }">
                <td>
                  <template v-if="t.country === '*'"><span class="all">🌍 Toutes destinations</span></template>
                  <template v-else><Flag :iso="t.country" :size="14" /> <strong>{{ countryName(t.country) }}</strong> <span class="stat-label">{{ t.country }}</span></template>
                  <div v-if="t.label" class="stat-label">{{ t.label }}</div>
                </td>
                <td><span class="tag">{{ t.business_type === '*' ? 'Tous' : t.business_type.toUpperCase() }}</span></td>
                <td>{{ t.method === '*' ? 'Toutes' : methods[t.method] }}</td>
                <td>{{ t.partner === '*' ? 'Tous' : partners[t.partner] }}</td>
                <td class="mono nowrap">{{ n(t.min_amount) }} – {{ t.max_amount >= 1e9 ? '∞' : n(t.max_amount) }}</td>
                <td class="nowrap"><strong :class="{ 't-ok': isFree(t) }">{{ feeText(t) }}</strong></td>
                <td class="stat-label nowrap">{{ t.min_fee ? n(t.min_fee) : '—' }} / {{ t.max_fee ? n(t.max_fee) : '—' }}</td>
                <td><label class="switch"><input type="checkbox" :checked="t.active" @change="toggle(t, $event.target.checked)" /><span></span></label></td>
                <td style="white-space:nowrap;">
                  <IconAction icon="edit" label="Modifier" @click="edit(t)" />
                  <IconAction icon="copy" label="Dupliquer (tranche suivante)" @click="edit({ ...t, id: undefined, min_amount: t.max_amount + 1, max_amount: 1000000000 })" />
                  <IconAction icon="trash" tone="danger" label="Supprimer" @click="remove(t)" />
                </td>
              </tr>
              <tr v-if="!filtered.length"><td colspan="9" class="stat-label">Aucun palier : le tarif « Envoi d'argent · international » de la grille nationale s'applique. Ajoutez un palier « Toutes destinations » pour commencer.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="container sim">
        <div class="container-head"><div><h3>Simulateur</h3></div></div>
        <div class="container-body">
          <label class="field">Pays de destination</label>
          <select v-model="sim.country"><option v-for="c in countries" :key="c.iso" :value="c.iso">{{ c.name }} ({{ c.currency }})</option></select>
          <label class="field">Type</label>
          <select v-model="sim.business_type"><option v-for="(l, k) in businessTypes" :key="k" :value="k">{{ l }}</option></select>
          <label class="field">Réception</label>
          <select v-model="sim.method"><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select>
          <label class="field">Montant ({{ cur }})</label>
          <input v-model.number="sim.amount" type="number" min="1" />
          <div class="quick"><button v-for="a in [25000, 100000, 500000, 2000000]" :key="a" type="button" @click="sim.amount = a">{{ short(a) }}</button></div>
          <button class="btn" style="width:100%;" :disabled="simBusy" @click="simulate">{{ simBusy ? '…' : 'Calculer' }}</button>
          <div v-if="simRes" class="preview" style="margin-top:12px;">
            <span>Résultat</span>
            <div><span>Frais FlashPay</span><b>{{ n(simRes.fee) }} {{ cur }}</b></div>
            <div><span>Total débité</span><b>{{ n(simRes.total) }} {{ cur }}</b></div>
            <div><span>Partenaire</span><b>{{ simRes.partner_name || '—' }}</b></div>
            <small class="stat-label">{{ simRes.tariff ? 'Palier : ' + describe(simRes.tariff) : simRes.source }}</small>
          </div>
        </div>
      </section>
    </div>

    <Modal v-if="form" :title="form.id ? 'Modifier le palier' : 'Nouveau palier international'" subtitle="Payé par l'expéditeur, en plus du montant" @close="form = null">
      <label class="field">Pays de destination</label>
      <select v-model="form.country">
        <option value="*">🌍 Toutes destinations</option>
        <option v-for="c in countries" :key="c.iso" :value="c.iso">{{ c.name }} ({{ c.iso }})</option>
      </select>
      <div class="row2">
        <div><label class="field">Type de transfert</label><select v-model="form.business_type"><option value="*">Tous</option><option v-for="(l, k) in businessTypes" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="field">Réception</label><select v-model="form.method"><option value="*">Toutes</option><option v-for="(l, k) in methods" :key="k" :value="k">{{ l }}</option></select></div>
      </div>
      <div class="row2">
        <div><label class="field">Partenaire</label><select v-model="form.partner"><option value="*">Tous</option><option v-for="(l, k) in partners" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="field">Libellé (facultatif)</label><input v-model="form.label" maxlength="120" placeholder="ex. Promotion diaspora" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Montant min ({{ cur }})</label><input v-model.number="form.min_amount" type="number" min="0" /></div>
        <div><label class="field">Montant max ({{ cur }})</label><input v-model.number="form.max_amount" type="number" min="1" /><div class="hint">1 000 000 000 = sans limite</div></div>
      </div>
      <div class="row2">
        <div><label class="field">Type de frais</label><select v-model="form.fee_type"><option value="percent">Pourcentage</option><option value="fixed">Montant fixe</option></select></div>
        <div><label class="field">Valeur {{ form.fee_type === 'percent' ? '(%)' : '(' + cur + ')' }}</label><input v-model.number="form.fee_value" type="number" step="0.01" min="0" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Frais minimum ({{ cur }})</label><input v-model.number="form.min_fee" type="number" min="0" /></div>
        <div><label class="field">Frais maximum ({{ cur }})</label><input v-model.number="form.max_fee" type="number" min="0" placeholder="Aucun plafond" /></div>
      </div>
      <label class="check"><input v-model="form.active" type="checkbox" /> Palier actif</label>
      <div class="preview">
        <span>Aperçu</span>
        <div v-for="a in previewAmounts" :key="a"><span>{{ n(a) }} {{ cur }}</span><b>{{ n(feeOf(form, a)) }} {{ cur }} de frais</b></div>
      </div>
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
import Flag from '../components/Flag.vue'
import Modal from '../components/Modal.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'

const tariffs = ref([])
const countries = ref([])
const businessTypes = ref({})
const methods = ref({})
const partners = ref({})
const kpi = ref({})
const cur = ref('XAF')
const error = ref('')
const msg = ref('')
const form = ref(null)
const saving = ref(false)
const flt = reactive({ q: '', bt: '', active: '' })
const sim = reactive({ country: 'CI', business_type: 'p2p', method: 'wallet', amount: 100000 })
const simRes = ref(null)
const simBusy = ref(false)

const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(v || 0)
const short = (v) => (!v ? '0' : v >= 1e6 ? (v / 1e6).toFixed(1).replace('.', ',') + ' M' : v >= 1e4 ? Math.round(v / 1e3) + ' k' : nf.format(v))
const countryName = (iso) => countries.value.find((c) => c.iso === iso)?.name || iso
const isFree = (t) => Number(t.fee_value) === 0 && !t.min_fee
const feeText = (t) => (isFree(t) ? 'Gratuit' : t.fee_type === 'percent' ? String(Number(t.fee_value)).replace('.', ',') + ' %' : n(t.fee_value) + ' ' + cur.value)
const feeOf = (t, amount) => {
  let fee = t.fee_type === 'fixed' ? Math.round(t.fee_value || 0) : Math.ceil((amount * (t.fee_value || 0)) / 100)
  fee = Math.max(fee, t.min_fee || 0)
  if (t.max_fee) fee = Math.min(fee, t.max_fee)
  return fee
}
const describe = (t) => [t.country === '*' ? 'Toutes destinations' : countryName(t.country), t.business_type === '*' ? null : t.business_type.toUpperCase(), t.method === '*' ? null : methods.value[t.method], feeText(t)].filter(Boolean).join(' · ')
const filtered = computed(() => {
  const q = flt.q.trim().toLowerCase()
  return tariffs.value.filter((t) => (!q || (t.country === '*' ? 'toutes destinations' : countryName(t.country) + ' ' + t.country).toLowerCase().includes(q))
    && (!flt.bt || t.business_type === flt.bt) && (flt.active === '' || String(+t.active) === flt.active))
})
const previewAmounts = computed(() => {
  if (!form.value) return []
  const lo = Math.max(form.value.min_amount || 0, 10000)
  return [lo, Math.max(lo, 100000), Math.max(lo, 1000000)].filter((a, i, arr) => a <= form.value.max_amount && arr.indexOf(a) === i)
})

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/admin/remit-tariffs')
    tariffs.value = data.tariffs
    countries.value = data.countries
    businessTypes.value = data.business_types
    methods.value = data.methods
    partners.value = data.partners
    kpi.value = data.kpi || {}
    cur.value = data.currency || 'XAF'
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  }
}
function edit(t = {}) {
  form.value = { country: '*', business_type: '*', method: '*', partner: '*', fee_type: 'percent', fee_value: 2, min_amount: 0, max_amount: 1000000000, min_fee: 0, max_fee: null, active: true, label: '', ...t }
}
async function save() {
  saving.value = true
  error.value = ''
  try {
    const { data } = await api.post('/admin/remit-tariffs', form.value)
    form.value = null
    msg.value = data.message || 'Palier enregistré.'
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || e.message)
  } finally {
    saving.value = false
  }
}
async function toggle(t, active) {
  try {
    await api.post(`/admin/remit-tariffs/${t.id}/active`, { active })
    await load()
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  }
}
async function remove(t) {
  if (!(await confirmBox(describe(t), { title: 'Supprimer ce palier international ?', confirmLabel: 'Supprimer', danger: true }))) return
  await api.delete(`/admin/remit-tariffs/${t.id}`)
  msg.value = 'Palier supprimé.'
  await load()
}
async function simulate() {
  simBusy.value = true
  try {
    simRes.value = (await api.post('/admin/remit-tariffs/simulate', sim)).data
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    simBusy.value = false
  }
}
onMounted(load)
</script>

<style scoped>
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; box-shadow: var(--shadow); display: grid; gap: 2px; }
.kpi span { color: var(--text-2); font-size: 12.5px; }
.kpi b { font-size: 24px; }
.kpi small { font-size: 12.5px; color: var(--text-2); font-weight: 500; }
.rule { font-size: 13px; color: var(--text-2); background: var(--surface); border: 1px solid var(--border); border-left: 3px solid var(--link); border-radius: 10px; padding: 10px 14px; }
.rule b { color: var(--text); }
.layout { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 16px; align-items: start; }
.layout .container { margin: 0; }
@media (max-width: 1200px) { .layout { grid-template-columns: 1fr; } }
.filters { display: flex; gap: 8px; flex-wrap: wrap; }
.filters select, .filters input { padding: 6px 8px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
.all { font-weight: 700; }
.nowrap { white-space: nowrap; }
.tag { font-size: 12px; padding: 2px 8px; border-radius: 99px; background: var(--info-bg); color: var(--link); font-weight: 600; }
.t-ok { color: var(--ok); }
.sim select, .sim input { width: 100%; margin-bottom: 10px; }
.quick { display: flex; gap: 6px; margin: -4px 0 12px; }
.quick button { flex: 1; border: 1px solid var(--border-strong); background: #fff; border-radius: 8px; padding: 4px; cursor: pointer; font: inherit; font-size: 12px; }
.quick button:hover { border-color: var(--link); }
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
