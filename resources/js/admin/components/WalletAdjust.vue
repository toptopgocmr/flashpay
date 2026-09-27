<template>
  <IconAction icon="wallet" tone="accent" label="Crédit / débit exceptionnel" @click="open = true" />
  <Modal v-if="open" title="Intervention exceptionnelle sur le wallet" :subtitle="name + ' · solde ' + money(balance, currency)" @close="open = false">
    <div v-if="error" class="flash err" style="margin:0;"><div>{{ error }}</div></div>
    <div class="flash warn" style="margin:0;"><div>Opération tracée dans le journal d'audit et notifiée aux administrateurs (§3.4.3).</div></div>
    <div class="row2">
      <div><label class="field">Sens</label>
        <select v-model="form.direction"><option value="credit">Crédit (ajouter)</option><option value="debit">Débit (retirer)</option></select></div>
      <div><label class="field">Montant</label><input v-model.number="form.amount" type="number" min="1" /></div>
    </div>
    <div><label class="field">Motif (obligatoire, visible par l'utilisateur)</label>
      <input v-model.trim="form.reason" placeholder="Ex. correction d'un cash-in contesté (litige LT-…)" /></div>
    <template #foot>
      <button class="btn-normal" @click="open = false">Annuler</button>
      <button class="btn" :disabled="busy || !form.amount || form.reason.length < 5" @click="submit">Valider</button>
    </template>
  </Modal>
</template>

<script setup>
import IconAction from './IconAction.vue'
import { reactive, ref } from 'vue'
import api from '../services/api'
import Modal from './Modal.vue'
import { money, errMsg } from '../utils/format'

const props = defineProps({ userId: Number, name: String, balance: Number, currency: String })
const emit = defineEmits(['done'])
const open = ref(false)
const busy = ref(false)
const error = ref('')
const form = reactive({ direction: 'credit', amount: null, reason: '' })

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const { data } = await api.post(`/admin/users/${props.userId}/wallet/adjust`, form)
    open.value = false
    emit('done', `${form.direction === 'credit' ? 'Crédit' : 'Débit'} de ${money(form.amount, props.currency)} enregistré (${data.reference}).`)
    Object.assign(form, { direction: 'credit', amount: null, reason: '' })
  } catch (e) {
    error.value = errMsg(e)
  } finally {
    busy.value = false
  }
}
</script>
