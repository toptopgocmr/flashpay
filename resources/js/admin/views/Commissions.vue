<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Commissions agents</h1>
        <p>Barème versé aux agents à chaque opération, par palier de montant. Les règles inactives sont ignorées.</p>
      </div>
      <div class="actions"><button class="btn" @click="openNew()">Ajouter une règle</button></div>
    </div>

    <!-- Simulateur -->
    <section class="card mb cm-sim">
      <div>
        <h3>Simuler une commission</h3>
        <p class="hint">Montant d'une opération → règle appliquée et commission versée à l'agent.</p>
      </div>
      <div class="cm-sim-f">
        <select v-model="sim.operation"><option v-for="(l, k) in d?.operations || {}" :key="k" :value="k">{{ l }}</option></select>
        <input v-model.number="sim.amount" type="number" min="0" placeholder="Montant" />
        <input v-if="needsFee" v-model.number="sim.fee" type="number" min="0" placeholder="Frais client" title="Frais facturés au client (règles « % des frais »)" />
        <div class="cm-sim-r">
          <template v-if="simResult">
            <b>{{ money(simResult.value) }}</b>
            <small>{{ ruleText(simResult.rule) }}</small>
          </template>
          <small v-else class="muted">Aucune règle active pour ce montant</small>
        </div>
      </div>
    </section>

    <div v-if="!d" class="card"><div class="skeleton" style="height:120px"></div></div>
    <section v-for="(rules, op) in grouped" :key="op" class="container mb">
      <div class="container-head">
        <div><h3>{{ d.operations[op] || op }} <span class="counter">· {{ rules.length }} palier{{ rules.length > 1 ? 's' : '' }}</span></h3></div>
        <button class="btn-link" @click="openNew(op)">+ Palier</button>
      </div>
      <div class="container-body flush"><table>
        <thead><tr><th>Palier de montant</th><th>Type</th><th class="num">Valeur</th><th>Exemple</th><th>État</th><th></th></tr></thead>
        <tbody><tr v-for="r in rules" :key="r.id" :class="{ off: !r.active }">
          <td><strong>{{ money(r.min_amount) }}</strong> → {{ r.max_amount ? money(r.max_amount) : 'et plus' }}</td>
          <td>{{ TYPE[r.type] }}</td>
          <td class="num"><b>{{ r.type === 'fixed' ? money(r.value) : r.value + ' %' }}</b></td>
          <td class="muted">{{ example(r) }}</td>
          <td>
            <button class="cm-toggle" :class="{ on: r.active }" :title="r.active ? 'Désactiver' : 'Activer'" @click="toggle(r)"><span></span></button>
          </td>
          <td class="actions-cell"><IconAction icon="edit" label="Modifier la règle" @click="edit = { ...r }" /><IconAction icon="trash" tone="danger" label="Supprimer la règle" @click="remove(r)" /></td>
        </tr></tbody>
      </table></div>
    </section>
    <div v-if="d && !d.rules.length" class="card empty"><strong>Aucune règle</strong>Ajoutez la première règle de commission.</div>

    <Modal v-if="edit" :title="edit.id ? 'Modifier la règle' : 'Nouvelle règle de commission'" @close="edit = null">
      <div v-if="error" class="flash err" style="margin:0"><div>{{ error }}</div></div>
      <div><label class="field">Opération</label><select v-model="edit.operation"><option v-for="(l, k) in d.operations" :key="k" :value="k">{{ l }}</option></select></div>
      <div class="row2">
        <div><label class="field">Montant min.</label><input v-model.number="edit.min_amount" type="number" min="0" /></div>
        <div><label class="field">Montant max.</label><input v-model.number="edit.max_amount" type="number" min="0" placeholder="Vide = illimité" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Type</label><select v-model="edit.type"><option v-for="(l, k) in TYPE" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="field">Valeur {{ edit.type === 'fixed' ? '(XAF)' : '(%)' }}</label><input v-model.number="edit.value" type="number" step="0.01" min="0" /></div>
      </div>
      <p class="hint">Exemple : {{ example(edit) }}</p>
      <label><input type="checkbox" v-model="edit.active" /> Règle active</label>
      <template #foot><button class="btn-normal" @click="edit = null">Annuler</button><button class="btn" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : 'Enregistrer' }}</button></template>
    </Modal>
  </div>
</template>

<script setup>
import IconAction from '../components/IconAction.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'
import { money, errMsg } from '../utils/format'
import { confirmBox, toast } from '../utils/ui'

const TYPE = { fixed: 'Montant fixe', percent: '% du montant', fee_share: '% des frais client' }
const d = ref(null)
const edit = ref(null)
const error = ref('')
const saving = ref(false)
const sim = reactive({ operation: 'cash_in', amount: 10000, fee: 100 })

const grouped = computed(() => {
  const g = {}
  for (const r of d.value?.rules || []) (g[r.operation] ||= []).push(r)
  for (const k in g) g[k].sort((a, b) => a.min_amount - b.min_amount)
  return g
})
const needsFee = computed(() => (grouped.value[sim.operation] || []).some((r) => r.type === 'fee_share'))
const commissionOf = (r, amount, fee) => (r.type === 'fixed' ? Number(r.value) : Math.round((r.type === 'fee_share' ? fee : amount) * Number(r.value) / 100))
const simResult = computed(() => {
  const a = Number(sim.amount) || 0
  const r = (grouped.value[sim.operation] || []).find((x) => x.active && a >= x.min_amount && (!x.max_amount || a <= x.max_amount))
  return r ? { rule: r, value: commissionOf(r, a, Number(sim.fee) || 0) } : null
})
const ruleText = (r) => `${TYPE[r.type]} ${r.type === 'fixed' ? money(r.value) : r.value + ' %'} · palier ${money(r.min_amount)} → ${r.max_amount ? money(r.max_amount) : '∞'}`
function example(r) {
  const amount = Math.max(Number(r.min_amount) || 0, 10000)
  const v = commissionOf(r, amount, 100)
  return r.type === 'fee_share' ? `frais de 100 XAF → ${money(v)}` : `${money(amount)} → ${money(v)}`
}

async function load() { d.value = (await api.get('/admin/commission-rules')).data }
function openNew(op) {
  error.value = ''
  edit.value = { operation: op || 'cash_in', min_amount: 0, max_amount: null, type: 'fixed', value: 0, active: true }
}
async function save() {
  error.value = ''
  saving.value = true
  try {
    await api.post('/admin/commission-rules', { ...edit.value, max_amount: edit.value.max_amount || null })
    edit.value = null
    toast('Règle enregistrée.')
    load()
  } catch (e) { error.value = errMsg(e) } finally { saving.value = false }
}
async function toggle(r) {
  try {
    await api.post('/admin/commission-rules', { ...r, active: !r.active })
    toast(!r.active ? 'Règle activée.' : 'Règle désactivée.')
    load()
  } catch (e) { toast(errMsg(e), 'err') }
}
async function remove(r) {
  if (!(await confirmBox(`${d.value.operations[r.operation]} · ${money(r.min_amount)} → ${r.max_amount ? money(r.max_amount) : '∞'}`, { title: 'Supprimer cette règle ?', confirmLabel: 'Supprimer', danger: true }))) return
  try { await api.delete(`/admin/commission-rules/${r.id}`); toast('Règle supprimée.'); load() } catch (e) { toast(errMsg(e), 'err') }
}
onMounted(load)
</script>

<style scoped>
.cm-sim { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.cm-sim h3 { margin: 0; }
.cm-sim .hint { margin: 2px 0 0; }
.cm-sim-f { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; flex: 1; justify-content: flex-end; }
.cm-sim-f input { width: 130px; }
.cm-sim-r { min-width: 220px; padding: 6px 14px; border-radius: 10px; background: var(--ok-bg); }
.cm-sim-r b { display: block; font-size: 18px; color: var(--ok); }
.cm-sim-r small { color: var(--text-2); font-size: 11.5px; }
.muted { color: var(--text-3); font-size: 12.5px; }
tr.off td { opacity: .55; }
.cm-toggle { width: 38px; height: 22px; border-radius: 11px; border: 0; background: #cbd5e1; position: relative; cursor: pointer; padding: 0; }
.cm-toggle span { position: absolute; top: 3px; left: 3px; width: 16px; height: 16px; border-radius: 50%; background: #fff; transition: left .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
.cm-toggle.on { background: var(--ok); } .cm-toggle.on span { left: 19px; }
</style>
