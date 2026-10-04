<template>
  <Modal title="Réinitialiser le code secret" :subtitle="name" @close="$emit('close')">
    <template v-if="!ok">
      <div v-if="error" class="flash err" style="margin:0;"><div>{{ error }}</div></div>
      <div>
        <label class="field">Nouveau code secret</label>
        <div style="display:flex; gap:6px;"><input v-model.trim="pwd" type="text" minlength="4" inputmode="numeric" /><button class="btn-normal" type="button" @click="gen">↻</button></div>
        <div class="hint">Code à 4 chiffres saisi dans l'application mobile (« Code secret ») ; il devient aussi le PIN des opérations. La personne est déconnectée de ses appareils.</div>
      </div>
    </template>
    <div v-else class="creds">
      <div><span>Téléphone</span><strong class="mono">{{ $phone(phone) }}</strong></div>
      <div><span>Nouveau code secret</span><strong class="mono">{{ pwd }}</strong></div>
    </div>
    <template #foot>
      <button class="btn-normal" @click="$emit('close')">{{ ok ? 'Fermer' : 'Annuler' }}</button>
      <button v-if="!ok" class="btn" :disabled="saving || pwd.length < 4" @click="save">Réinitialiser</button>
    </template>
  </Modal>
</template>

<script setup>
import { ref } from 'vue'
import Modal from './Modal.vue'
import api from '../services/api'

const props = defineProps({ userId: Number, name: String, phone: String })
defineEmits(['close'])
const pwd = ref('')
const ok = ref(false)
const saving = ref(false)
const error = ref('')
// Code secret de l'app mobile : 4 chiffres, sans suite triviale
function gen() {
  let c
  do {
    c = String(Math.floor(1000 + Math.random() * 9000))
  } while (/^(\d)\1+$/.test(c) || ['1234', '4321'].includes(c))
  pwd.value = c
}
gen()
async function save() {
  saving.value = true
  error.value = ''
  try {
    await api.post(`/admin/users/${props.userId}/password`, { password: pwd.value })
    ok.value = true
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    saving.value = false
  }
}
</script>
