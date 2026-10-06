<template>
  <Modal title="Modes de retrait" :subtitle="merchant.business_name + ' · canaux de retrait / règlement des fonds'" @close="$emit('close')">
    <div v-if="error" class="flash err" style="margin:0;"><div>{{ error }}</div></div>
    <div v-if="msg" class="flash info" style="margin:0;"><div>{{ msg }}</div></div>

    <div class="acc-list">
      <div v-for="a in accounts" :key="a.id" class="acc" :class="{ def: a.is_default }">
        <div>
          <strong>{{ a.type_label }}</strong> <span v-if="a.is_default" class="status ok">Par défaut</span>
          <div class="hint" style="margin:0;">{{ a.summary }}</div>
        </div>
        <div class="acc-actions">
          <IconAction v-if="!a.is_default" icon="star" tone="accent" label="Définir par défaut" :disabled="busy" @click="setDefault(a)" />
          <IconAction icon="trash" tone="danger" :label="accounts.length <= 1 ? 'Au moins un mode requis' : 'Supprimer'" :disabled="busy || accounts.length <= 1" @click="remove(a)" />
        </div>
      </div>
      <div v-if="!accounts.length && !loading" class="hint">Aucun mode de retrait.</div>
    </div>

    <div class="section-title">Ajouter un canal</div>
    <div class="seg-types">
      <button v-for="t in TYPES" :key="t.key" type="button" :class="{ on: f.type === t.key }" @click="f.type = t.key">
        <strong>{{ t.label }}</strong><small>{{ t.hint }}</small>
      </button>
    </div>
    <div v-if="f.type === 'mobile_money' || f.type === 'wallet'">
      <label class="field">{{ f.type === 'wallet' ? 'Numéro du wallet FlashPay' : 'Numéro mobile money (tout opérateur)' }}</label>
      <input v-model.trim="f.phone" :placeholder="'Par défaut : +' + (merchant.phone || '')" />
    </div>
    <template v-if="f.type === 'bank'">
      <div class="row2">
        <div><label class="field">Banque</label><input v-model.trim="f.bank_name" placeholder="Ex. BGFI Bank Congo" /></div>
        <div><label class="field">Titulaire</label><input v-model.trim="f.account_holder" :placeholder="merchant.business_name" /></div>
      </div>
      <div class="row2">
        <div><label class="field">RIB / IBAN</label><input v-model.trim="f.account_number" /></div>
        <div><label class="field">SWIFT / BIC (facultatif)</label><input v-model.trim="f.swift" /></div>
      </div>
    </template>
    <label class="def"><input v-model="f.is_default" type="checkbox" /> En faire le mode par défaut</label>

    <template #foot>
      <button class="btn-normal" @click="$emit('close')">Fermer</button>
      <button class="btn" :disabled="busy" @click="add">Ajouter ce canal</button>
    </template>
  </Modal>
</template>

<script setup>
import { confirmBox } from '../utils/ui'
import IconAction from './IconAction.vue'
import { onMounted, reactive, ref } from 'vue'
import Modal from './Modal.vue'
import api from '../services/api'

const props = defineProps({ merchant: { type: Object, required: true } })
const emit = defineEmits(['close', 'changed'])
const TYPES = [
  { key: 'mobile_money', label: 'Mobile money', hint: 'MTN, Airtel, Orange…' },
  { key: 'bank', label: 'Compte bancaire', hint: 'Virement' },
  { key: 'wallet', label: 'Wallet FlashPay', hint: 'Instantané' },
  { key: 'cash_pickup', label: 'Cash agent', hint: 'Code de retrait' },
]
const accounts = ref([])
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const msg = ref('')
const f = reactive({ type: 'bank', phone: '', bank_name: '', account_holder: '', account_number: '', swift: '', is_default: false })
const base = `/admin/merchants/${props.merchant.id}/accounts`

async function load() {
  loading.value = true
  try { accounts.value = (await api.get(base)).data } finally { loading.value = false }
}
async function run(fn, ok) {
  busy.value = true; error.value = ''; msg.value = ''
  try { const r = await fn(); msg.value = r?.data?.message || ok; await load(); emit('changed') } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || e.message)
  } finally { busy.value = false }
}
const add = () => run(() => api.post(base, Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))), 'Canal ajouté.')
  .then(() => { if (!error.value) Object.assign(f, { phone: '', bank_name: '', account_holder: '', account_number: '', swift: '', is_default: false }) })
const setDefault = (a) => run(() => api.post(`${base}/${a.id}/default`))
const remove = async (a) => { if (await confirmBox('Les règlements ne pourront plus être envoyés vers ce compte.', { title: `Supprimer « ${a.type_label} » ?`, confirmLabel: 'Supprimer', danger: true })) run(() => api.delete(`${base}/${a.id}`)) }

onMounted(load)
</script>

<style scoped>
.acc-list { display: grid; gap: 8px; }
.acc { display: flex; justify-content: space-between; align-items: center; gap: 10px; border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
.acc.def { border-color: var(--ok); background: var(--ok-bg); }
.acc-actions { display: flex; gap: 6px; white-space: nowrap; }
.def { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
.def input { width: auto !important; }
</style>
