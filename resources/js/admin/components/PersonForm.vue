<template>
  <Modal :title="title" :subtitle="subtitle" @close="$emit('close')">
    <div v-if="error" class="flash err" style="margin:0;"><div>{{ error }}</div></div>
    <template v-if="!done">
      <div class="row2">
        <div><label class="field">Nom complet</label><input v-model.trim="f.full_name" required /></div>
        <div><label class="field">Téléphone</label><input v-model.trim="f.phone" required placeholder="Numéro local ou international" /></div>
      </div>
      <div class="row2">
        <div><label class="field">E-mail (facultatif)</label><input v-model.trim="f.email" type="email" /></div>
        <div v-if="withCountry">
          <label class="field">Pays</label>
          <select v-model="f.country"><option v-for="c in countries" :key="c.country" :value="c.country">{{ c.name }} ({{ c.currency }})</option></select>
        </div>
      </div>
      <div v-if="withAgent" class="row2">
        <div><label class="field">Ville</label><input v-model.trim="f.city" list="pf-cities" /><datalist id="pf-cities"><option v-for="v in cities" :key="v" :value="v" /></datalist></div>
        <div><label class="field">Quartier / point de service</label><input v-model.trim="f.zone" /></div>
      </div>
      <div v-if="create">
        <label class="field">Mot de passe initial</label>
        <div style="display:flex; gap:6px;"><input v-model="f.password" type="text" minlength="6" /><button type="button" class="btn-normal" title="Générer" @click="gen">↻</button></div>
      </div>
    </template>
    <div v-else class="creds">
      <div><span>Profil</span><strong>Client</strong></div>
      <div><span>Téléphone</span><strong class="mono">{{ $phone(done.login.phone) }}</strong></div>
      <div><span>Mot de passe</span><strong class="mono">{{ f.password }}</strong></div>
    </div>
    <template #foot>
      <template v-if="!done">
        <button class="btn-normal" @click="$emit('close')">Annuler</button>
        <button class="btn" :disabled="saving" @click="save">{{ saving ? 'Enregistrement…' : (create ? 'Créer' : 'Enregistrer') }}</button>
      </template>
      <button v-else class="btn" @click="$emit('close')">Terminer</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Modal from './Modal.vue'
import api from '../services/api'

// endpoint : POST (création) ou PUT (modification) ; initial : valeurs actuelles
const props = defineProps({ title: String, subtitle: String, endpoint: { type: String, required: true }, create: Boolean, initial: { type: Object, default: () => ({}) }, withCountry: Boolean, withAgent: Boolean })
const emit = defineEmits(['close', 'saved'])
const f = reactive({ full_name: '', phone: '', email: '', country: 'CG', city: '', zone: '', password: '', ...Object.fromEntries(Object.entries(props.initial).filter(([, v]) => v != null)) })
const saving = ref(false)
const error = ref('')
const done = ref(null)
const countries = ref([])
const cities = computed(() => countries.value.find((c) => c.country === f.country)?.cities || [])

function gen() {
  const chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'
  f.password = Array.from({ length: 10 }, () => chars[Math.floor(Math.random() * chars.length)]).join('')
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    const keys = ['full_name', 'phone', 'email', ...(props.withCountry ? ['country'] : []), ...(props.withAgent ? ['city', 'zone'] : []), ...(props.create ? ['password'] : [])]
    const payload = Object.fromEntries(keys.map((k) => [k, f[k] === '' ? null : f[k]]).filter(([k, v]) => v !== null || k === 'email' || k === 'zone'))
    const { data } = props.create ? await api.post(props.endpoint, payload) : await api.put(props.endpoint, payload)
    emit('saved', data)
    if (props.create && data.login) done.value = data
    else emit('close')
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || e.message)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  if (props.create) gen()
  if (props.withCountry || props.withAgent) {
    try { countries.value = (await api.get('/admin/geo')).data.countries } catch (_) { countries.value = [{ country: 'CG', name: 'Congo-Brazzaville', currency: 'XAF', cities: [] }] }
  }
})
</script>
