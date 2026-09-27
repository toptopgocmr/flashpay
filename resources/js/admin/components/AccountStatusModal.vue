<template>
  <Modal :title="(active ? 'Activer' : 'Désactiver') + ' le compte'" :subtitle="name + (phone ? ' · ' + $phone(phone) : '')" @close="$emit('close')">
    <div v-if="error" class="flash err" style="margin:0;"><div>{{ error }}</div></div>
    <p style="margin:0;">
      {{ active
        ? 'La personne pourra de nouveau se connecter et effectuer des opérations.'
        : 'La personne sera déconnectée immédiatement et ne pourra plus se connecter ni effectuer d\'opérations.' }}
    </p>
    <div v-if="!active">
      <label class="field">Motif (facultatif)</label>
      <input v-model="reason" maxlength="200" placeholder="Ex. : fraude suspectée, demande du client…" />
    </div>
    <template #foot>
      <button class="btn-normal" @click="$emit('close')">Annuler</button>
      <button :class="active ? 'btn' : 'btn danger-solid'" :disabled="busy" @click="save">{{ active ? 'Activer' : 'Désactiver' }}</button>
    </template>
  </Modal>
</template>

<script setup>
import { ref } from 'vue'
import Modal from './Modal.vue'
import api from '../services/api'

const props = defineProps({ userId: { type: Number, required: true }, name: String, phone: String, active: Boolean })
const emit = defineEmits(['close', 'done'])
const reason = ref('')
const busy = ref(false)
const error = ref('')

async function save() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.post(`/admin/accounts/${props.userId}/status`, { active: props.active, reason: reason.value || null })
    emit('done', data.message)
    emit('close')
  } catch (e) {
    error.value = e.response?.data?.message || e.message
  } finally {
    busy.value = false
  }
}
</script>

<style>
.danger-solid { background: var(--err); border-color: var(--err); color: #fff; }
.danger-solid:hover { background: #991b1b; }
</style>
