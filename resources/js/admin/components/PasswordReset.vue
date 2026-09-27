<template>
  <Modal title="Réinitialiser le mot de passe" :subtitle="name" @close="$emit('close')">
    <template v-if="!ok">
      <div v-if="error" class="flash err" style="margin:0;"><div>{{ error }}</div></div>
      <div>
        <label class="field">Nouveau mot de passe</label>
        <div style="display:flex; gap:6px;"><input v-model="pwd" type="text" minlength="6" /><button class="btn-normal" type="button" @click="gen">↻</button></div>
        <div class="hint">La personne est déconnectée de ses appareils et devra utiliser ce nouveau mot de passe.</div>
      </div>
    </template>
    <div v-else class="creds">
      <div><span>Téléphone</span><strong class="mono">{{ $phone(phone) }}</strong></div>
      <div><span>Nouveau mot de passe</span><strong class="mono">{{ pwd }}</strong></div>
    </div>
    <template #foot>
      <button class="btn-normal" @click="$emit('close')">{{ ok ? 'Fermer' : 'Annuler' }}</button>
      <button v-if="!ok" class="btn" :disabled="saving || pwd.length < 6" @click="save">Réinitialiser</button>
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
function gen() {
  const c = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789'
  pwd.value = Array.from({ length: 10 }, () => c[Math.floor(Math.random() * c.length)]).join('')
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
