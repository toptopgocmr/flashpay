<template>
  <div>
    <div class="page-header">
      <div><h1>Points de service</h1><p>Agences, points de vente, GAB et position des agents : ce que les clients voient sur la carte « Localiser un agent » de l'application.</p></div>
      <div class="actions"><button class="btn" @click="edit = { type: 'atm', country: 'CG', city: 'Brazzaville', active: true }">Ajouter un point</button></div>
    </div>

    <section class="container">
      <div class="container-header"><h3>Agences, points de vente et GAB</h3>
        <select v-model="filter" style="max-width:200px"><option value="">Tous les types</option><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select>
      </div>
      <div class="container-body flush"><table>
        <thead><tr><th>Type</th><th>Nom</th><th>Banque / enseigne</th><th>Adresse</th><th>Horaires</th><th>Position</th><th>Statut</th><th></th></tr></thead>
        <tbody><tr v-for="p in shown" :key="p.id">
          <td>{{ types[p.type] || p.type }}</td>
          <td><strong>{{ p.name }}</strong><br /><small>{{ p.city }} · {{ p.country }}</small></td>
          <td>{{ p.operator || '—' }}</td>
          <td>{{ p.address || '—' }}</td>
          <td>{{ p.opening_hours || (p.type === 'atm' ? '24h/24' : '—') }}</td>
          <td class="mono" style="font-size:12px"><a :href="mapUrl(p)" target="_blank" rel="noopener">{{ Number(p.latitude).toFixed(5) }}, {{ Number(p.longitude).toFixed(5) }}</a></td>
          <td><span class="status" :class="p.active ? 'ok' : 'err'">{{ p.active ? 'Visible' : 'Masqué' }}</span></td>
          <td class="actions-cell">
            <IconAction icon="edit" label="Modifier" @click="edit = { ...p }" />
            <IconAction icon="trash" label="Supprimer" @click="remove(p)" />
          </td>
        </tr></tbody>
      </table>
      <div v-if="!shown.length" class="empty"><strong>Aucun point</strong><span>Ajoutez les GAB des banques partenaires, vos agences et vos points de vente.</span></div></div>
    </section>

    <section class="container">
      <div class="container-header"><h3>Agents sur la carte</h3><small>Un agent apparaît sur la carte dès que sa position est renseignée (par lui-même depuis l'app, ou ici).</small></div>
      <div class="container-body flush"><table>
        <thead><tr><th>Agent</th><th>Ville</th><th>Adresse</th><th>Horaires</th><th>Position</th><th>Carte</th><th></th></tr></thead>
        <tbody><tr v-for="a in agents" :key="a.id">
          <td><strong>{{ a.name || '—' }}</strong><br /><small class="mono">{{ a.code }}</small></td>
          <td>{{ a.city || '—' }}</td>
          <td>{{ a.address || '—' }}</td>
          <td>{{ a.opening_hours || '—' }}</td>
          <td class="mono" style="font-size:12px"><a v-if="a.latitude" :href="mapUrl(a)" target="_blank" rel="noopener">{{ Number(a.latitude).toFixed(5) }}, {{ Number(a.longitude).toFixed(5) }}</a><span v-else>non renseignée</span></td>
          <td><span class="status" :class="a.latitude && a.show_on_map ? 'ok' : 'pending'">{{ a.latitude && a.show_on_map ? 'Visible' : 'Absent' }}</span></td>
          <td class="actions-cell"><IconAction icon="edit" label="Position" @click="agentEdit = { ...a }" /></td>
        </tr></tbody>
      </table>
      <div v-if="!agents.length" class="empty"><strong>Aucun agent validé</strong></div></div>
    </section>

    <Modal v-if="edit" :title="edit.id ? 'Modifier le point' : 'Nouveau point'" @close="edit = null">
      <div v-if="error" class="flash err" style="margin:0"><div>{{ error }}</div></div>
      <div class="row2">
        <div><label class="field">Type</label><select v-model="edit.type"><option v-for="(l, k) in types" :key="k" :value="k">{{ l }}</option></select></div>
        <div><label class="field">{{ edit.type === 'atm' ? 'Banque du GAB' : 'Enseigne (facultatif)' }}</label><input v-model="edit.operator" :placeholder="edit.type === 'atm' ? 'ex. BGFIBank, Ecobank, LCB Bank' : ''" /></div>
      </div>
      <div><label class="field">Nom affiché</label><input v-model="edit.name" :placeholder="edit.type === 'atm' ? 'ex. GAB BGFI Poto-Poto' : 'ex. Agence OfficePay Plateau'" /></div>
      <div><label class="field">Adresse</label><input v-model="edit.address" /></div>
      <div class="row2">
        <div><label class="field">Ville</label><input v-model="edit.city" /></div>
        <div><label class="field">Pays (ISO)</label><input v-model="edit.country" maxlength="2" /></div>
      </div>
      <div><label class="field">Position (coller « latitude, longitude » depuis Google Maps)</label><input :value="coords(edit)" @input="setCoords(edit, $event.target.value)" placeholder="-4.26340, 15.28320" /></div>
      <div class="row2">
        <div><label class="field">Horaires</label><input v-model="edit.opening_hours" :placeholder="edit.type === 'atm' ? '24h/24' : 'Lun-Sam 8h-18h'" /></div>
        <div><label class="field">Téléphone</label><input v-model="edit.phone" /></div>
      </div>
      <label class="check"><input type="checkbox" v-model="edit.active" /> Visible sur la carte des clients</label>
      <template #foot><button class="btn-normal" @click="edit = null">Annuler</button><button class="btn" @click="save">Enregistrer</button></template>
    </Modal>

    <Modal v-if="agentEdit" :title="`Position de ${agentEdit.name || 'l\'agent'}`" @close="agentEdit = null">
      <div v-if="error" class="flash err" style="margin:0"><div>{{ error }}</div></div>
      <div><label class="field">Position (« latitude, longitude »)</label><input :value="coords(agentEdit)" @input="setCoords(agentEdit, $event.target.value)" placeholder="-4.23800, 15.26600" /></div>
      <div><label class="field">Adresse</label><input v-model="agentEdit.address" /></div>
      <div><label class="field">Horaires</label><input v-model="agentEdit.opening_hours" placeholder="7h-20h" /></div>
      <label class="check"><input type="checkbox" v-model="agentEdit.show_on_map" /> Afficher cet agent sur la carte</label>
      <template #foot><button class="btn-normal" @click="agentEdit = null">Annuler</button><button class="btn" @click="saveAgent">Enregistrer</button></template>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'
import IconAction from '../components/IconAction.vue'
import { toast } from '../utils/ui'
import { errMsg } from '../utils/format'

const types = ref({})
const points = ref([])
const agents = ref([])
const filter = ref('')
const edit = ref(null)
const agentEdit = ref(null)
const error = ref('')
const shown = computed(() => points.value.filter((p) => !filter.value || p.type === filter.value))

const mapUrl = (p) => `https://www.google.com/maps?q=${p.latitude},${p.longitude}`
const coords = (p) => (p.latitude != null && p.longitude != null && p.latitude !== '' ? `${p.latitude}, ${p.longitude}` : '')
function setCoords(p, v) {
  const m = String(v).match(/(-?\d+(?:\.\d+)?)\s*[,; ]\s*(-?\d+(?:\.\d+)?)/)
  if (m) { p.latitude = Number(m[1]); p.longitude = Number(m[2]) }
}

async function load() {
  const d = (await api.get('/admin/service-points')).data
  types.value = d.types
  points.value = d.points
  agents.value = d.agents
}
async function save() {
  error.value = ''
  try {
    await api.post(edit.value.id ? `/admin/service-points/${edit.value.id}` : '/admin/service-points', edit.value)
    toast('Point enregistré.'); edit.value = null; load()
  } catch (e) { error.value = errMsg(e) }
}
async function saveAgent() {
  error.value = ''
  const a = agentEdit.value
  try {
    await api.post(`/admin/agents/${a.id}/location`, { latitude: a.latitude, longitude: a.longitude, address: a.address, opening_hours: a.opening_hours, show_on_map: !!a.show_on_map })
    toast('Position enregistrée.'); agentEdit.value = null; load()
  } catch (e) { error.value = errMsg(e) }
}
async function remove(p) {
  if (!window.confirm(`Supprimer « ${p.name} » de la carte ?`)) return
  await api.delete(`/admin/service-points/${p.id}`); toast('Point supprimé.'); load()
}
onMounted(load)
</script>
