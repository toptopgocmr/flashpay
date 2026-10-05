<template>
  <div>
    <div class="page-header">
      <div><h1>Commissions agents</h1></div>
      <div class="actions"><button class="btn" @click="edit = { operation: 'cash_in', min_amount: 0, max_amount: null, type: 'fixed', value: 0, active: true }">Ajouter une règle</button></div>
    </div>
    <section class="container">
      <div class="container-body flush"><table>
        <thead><tr><th>Opération</th><th>Palier</th><th>Type</th><th class="num">Valeur</th><th>Active</th><th></th></tr></thead>
        <tbody><tr v-for="r in d?.rules || []" :key="r.id">
          <td>{{ d.operations[r.operation] }}</td>
          <td>{{ money(r.min_amount) }} → {{ r.max_amount ? money(r.max_amount) : '∞' }}</td>
          <td>{{ TYPE[r.type] }}</td>
          <td class="num">{{ r.type === 'fixed' ? money(r.value) : r.value + ' %' }}</td>
          <td><span class="status" :class="r.active ? 'ok' : 'muted'">{{ r.active ? 'Oui' : 'Non' }}</span></td>
          <td class="actions-cell"><IconAction icon="edit" label="Modifier la règle" @click="edit = { ...r }" /><IconAction icon="trash" tone="danger" label="Supprimer la règle" @click="remove(r)" /></td>
        </tr></tbody>
      </table></div>
    </section>
    <Modal v-if="edit" title="Règle de commission" @close="edit = null">
      <div v-if="error" class="flash err" style="margin:0"><div>{{ error }}</div></div>
      <div><label class="field">Opération</label><select v-model="edit.operation"><option v-for="(l, k) in d.operations" :key="k" :value="k">{{ l }}</option></select></div>
      <div class="row2">
        <div><label class="field">Montant min.</label><input v-model.number="edit.min_amount" type="number" /></div>
        <div><label class="field">Montant max. (vide = illimité)</label><input v-model.number="edit.max_amount" type="number" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Type</label><select v-model="edit.type"><option v-for="(l, k) in TYPE" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="field">Valeur</label><input v-model.number="edit.value" type="number" step="0.01" /></div>
      </div>
      <label><input type="checkbox" v-model="edit.active" /> Règle active</label>
      <template #foot><button class="btn-normal" @click="edit = null">Annuler</button><button class="btn" @click="save">Enregistrer</button></template>
    </Modal>
  </div>
</template>

<script setup>
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'
import { money, errMsg } from '../utils/format'

const TYPE = { fixed: 'Montant fixe', percent: '% du montant', fee_share: '% des frais client' }
const d = ref(null)
const edit = ref(null)
const error = ref('')
async function load() { d.value = (await api.get('/admin/commission-rules')).data }
async function save() {
  error.value = ''
  try {
    const body = { ...edit.value, max_amount: edit.value.max_amount || null }
    await api.post('/admin/commission-rules', body)
    edit.value = null
    load()
  } catch (e) { error.value = errMsg(e) }
}
async function remove(r) {
  if (!confirm('Supprimer cette règle ?')) return
  await api.delete(`/admin/commission-rules/${r.id}`)
  load()
}
onMounted(load)
</script>
