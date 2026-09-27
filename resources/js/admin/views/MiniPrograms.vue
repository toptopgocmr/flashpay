<template>
  <div>
    <div class="page-header">
      <div><h1>Mini-programmes</h1><p>Mini-applications de marchands partenaires affichées dans la zone Services de l'app (§3.5.3). Le paiement s'appuie sur l'API e-commerce (canal « mini_program »).</p></div>
      <div class="actions"><button class="btn" @click="edit = { category: 'recharge', status: 'pending', sort: 100 }">Ajouter</button></div>
    </div>
    <section class="container">
      <div class="container-body flush"><table>
        <thead><tr><th>Nom</th><th>Marchand</th><th>Catégorie</th><th>URL</th><th>Statut</th><th></th></tr></thead>
        <tbody><tr v-for="m in list" :key="m.id">
          <td><strong>{{ m.name }}</strong><br /><small>{{ m.description }}</small></td><td>{{ m.merchant?.business_name }}</td><td>{{ CAT[m.category] }}</td>
          <td class="mono" style="font-size:12px">{{ m.entry_url }}</td>
          <td><span class="status" :class="m.status === 'approved' ? 'ok' : m.status === 'suspended' ? 'err' : 'pending'">{{ STATUS[m.status] }}</span></td>
          <td class="actions-cell"><IconAction icon="edit" label="Modifier" @click="edit = { ...m }" /></td>
        </tr></tbody>
      </table>
      <div v-if="!list.length" class="empty"><strong>Aucun mini-programme</strong></div></div>
    </section>
    <Modal v-if="edit" :title="edit.id ? 'Modifier le mini-programme' : 'Nouveau mini-programme'" @close="edit = null">
      <div v-if="error" class="flash err" style="margin:0"><div>{{ error }}</div></div>
      <div class="row2">
        <div><label class="field">Nom</label><input v-model="edit.name" /></div>
        <div><label class="field">ID marchand</label><input v-model.number="edit.merchant_id" type="number" /></div>
      </div>
      <div class="row2">
        <div><label class="field">Catégorie</label><select v-model="edit.category"><option v-for="(l, k) in CAT" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="field">Statut</label><select v-model="edit.status"><option v-for="(l, k) in STATUS" :key="k" :value="k">{{ l }}</option></select></div>
      </div>
      <div><label class="field">URL d'entrée (https)</label><input v-model="edit.entry_url" /></div>
      <div><label class="field">Icône (URL)</label><input v-model="edit.icon_url" /></div>
      <div><label class="field">Description</label><input v-model="edit.description" /></div>
      <template #foot><button class="btn-normal" @click="edit = null">Annuler</button><button class="btn" @click="save">Enregistrer</button></template>
    </Modal>
  </div>
</template>

<script setup>
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'
import { errMsg } from '../utils/format'

const CAT = { recharge: 'Recharge', billetterie: 'Billetterie', services_publics: 'Services publics', ecommerce: 'E-commerce', autre: 'Autre' }
const STATUS = { pending: 'En attente', approved: 'Publié', suspended: 'Suspendu' }
const list = ref([])
const edit = ref(null)
const error = ref('')
async function load() { list.value = (await api.get('/admin/mini-programs')).data }
async function save() {
  error.value = ''
  try { await api.post('/admin/mini-programs', edit.value); edit.value = null; load() } catch (e) { error.value = errMsg(e) }
}
onMounted(load)
</script>
