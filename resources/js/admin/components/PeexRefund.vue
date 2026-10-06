<template>
  <Modal wide :title="'Remboursement PEEX — ' + (info?.transaction.reference || '')" @close="$emit('close')">
    <div v-if="!info" class="stat-label">{{ loadError || 'Chargement…' }}</div>
    <template v-else>
      <div class="creds">
        <div><span>Opération</span><b>{{ money(info.transaction.amount) }} + frais {{ money(info.transaction.fee) }}</b></div>
        <div><span>Encore remboursable</span><b>{{ money(info.refundable) }}</b></div>
      </div>

      <!-- API PEEX utilisée -->
      <div class="pf-apis">
        <button v-for="a in APIS" :key="a.key" type="button" :class="{ on: f.api === a.key }" @click="f.api = a.key">
          <strong>{{ a.label }}</strong><code>POST {{ a.path }}</code>
        </button>
      </div>

      <fieldset class="pf">
        <legend>Paiement</legend>
        <div class="pf-grid">
          <label>track_id<input :value="nextTrack" disabled class="mono" /></label>
          <label>amount *<input v-model.number="f.amount" type="number" min="1" :max="info.refundable" /></label>
          <template v-if="f.api === 'disbursement'">
            <label>currency *<input v-model="f.currency" maxlength="3" class="up" /></label>
          </template>
          <template v-else>
            <label>from_currency *<input v-model="f.from_currency" maxlength="3" class="up" /></label>
            <label>to_currency<input v-model="f.to_currency" maxlength="3" class="up" /></label>
            <label>fxrate *<input v-model.number="f.fxrate" type="number" step="any" min="0" /></label>
          </template>
        </div>
        <label v-if="f.api !== 'disbursement'" class="pf-check"><input v-model="f.aml_cft" type="checkbox" /> aml_cft = 1 · conformité LCB-FT confirmée</label>
      </fieldset>

      <fieldset class="pf">
        <legend>Expéditeur (sender)</legend>
        <div class="pf-grid">
          <label>sender_first_name *<input v-model="f.sender_first_name" /></label>
          <label>sender_last_name *<input v-model="f.sender_last_name" /></label>
          <label>sender_mobile_phone *<input v-model="f.sender_mobile_phone" placeholder="+242…" /></label>
          <template v-if="f.api !== 'disbursement'">
            <label>sender_country *<input v-model="f.sender_country" maxlength="2" class="up" /></label>
            <label>sender_email<input v-model="f.sender_email" type="email" /></label>
            <label v-if="f.api === 'remittance'">sender_city<input v-model="f.sender_city" /></label>
          </template>
        </div>
      </fieldset>

      <fieldset class="pf">
        <legend>Bénéficiaire</legend>
        <div class="pf-grid">
          <label>first_name *<input v-model="f.first_name" /></label>
          <label>last_name *<input v-model="f.last_name" /></label>
          <label>mobile_phone {{ f.api === 'bank' ? '' : '*' }}<input v-model="f.mobile_phone" placeholder="+242…" /></label>
          <label v-if="f.api === 'disbursement'">country *<input v-model="f.country" maxlength="2" class="up" /></label>
          <template v-else>
            <label>to_country *<input v-model="f.to_country" maxlength="2" class="up" /></label>
            <label>email<input v-model="f.email" type="email" /></label>
          </template>
        </div>
      </fieldset>

      <fieldset v-if="f.api === 'bank'" class="pf">
        <legend>Banque</legend>
        <div class="pf-grid">
          <label>bank_name<input v-model="f.bank_name" placeholder="BGFIBank Congo" /></label>
          <label>bank_address *<input v-model="f.bank_address" /></label>
          <label class="span2">bank_iban *<input v-model="f.bank_iban" class="mono" /></label>
          <label>bank_swift *<input v-model="f.bank_swift" maxlength="11" class="mono up" /></label>
        </div>
      </fieldset>

      <fieldset class="pf">
        <legend>Objet</legend>
        <div class="pf-grid">
          <label>purpose *<select v-model="f.purpose"><option v-for="v in PURPOSES" :key="v" :value="v">{{ v }}</option></select></label>
          <label>fund_origin *<select v-model="f.fund_origin"><option v-for="v in ORIGINS" :key="v" :value="v">{{ v }}</option></select></label>
          <label class="span2">Motif interne *<input v-model="f.reason" placeholder="Versement échoué…" /></label>
        </div>
      </fieldset>

      <details class="pf-json"><summary>Requête PEEX</summary><pre>{{ preview }}</pre></details>

      <div v-if="error" class="flash err"><div>{{ error }}</div></div>
      <div v-if="ok" class="flash info"><div>{{ ok }}</div></div>

      <template v-if="info.refunds.length">
        <div class="section-title">Remboursements déjà demandés</div>
        <div v-for="r in info.refunds" :key="r.id" class="rf-row">
          <div>
            <b>{{ money(r.amount, r.currency) }}</b> · {{ r.channel === 'bank' ? 'Banque' : 'Mobile' }} · {{ r.beneficiary }}<br />
            <small class="mono">{{ r.track_id }} · {{ r.account }}</small>
            <small v-if="r.message" style="display:block;color:var(--text-2)">{{ r.message }}</small>
          </div>
          <div style="text-align:right">
            <span class="status" :class="{ ok: r.status === 'successful', err: r.status === 'failed', pending: r.status === 'pending' }">{{ LABEL[r.status] || r.status }}</span><br />
            <button v-if="r.status === 'pending'" class="btn-link" @click="refresh(r)">Vérifier chez PEEX</button>
          </div>
        </div>
      </template>
    </template>

    <template #foot>
      <button class="btn-normal" @click="$emit('close')">Fermer</button>
      <button class="btn" :disabled="!info || busy || !info.refundable" @click="submit">{{ busy ? 'Envoi…' : 'Envoyer à PEEX' }}</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import Modal from './Modal.vue'
import api from '../services/api'

const props = defineProps({ transactionId: { type: Number, required: true } })
const emit = defineEmits(['close', 'done'])
const LABEL = { successful: 'Remboursé', failed: 'Échoué', pending: 'En cours' }
const APIS = [
  { key: 'disbursement', label: 'Décaissement mobile', path: 'disbursement/request_payment' },
  { key: 'remittance', label: 'Remittance mobile', path: 'clients/request_payment' },
  { key: 'bank', label: 'Virement bancaire', path: 'clients/request_bank_payment' },
]
// Valeurs acceptées par PEEX (doc Disbursement › Payment Request)
const PURPOSES = ['FAMILY', 'BUSINESS', 'EDUCATION', 'MEDICAL']
const ORIGINS = ['SALARY', 'SALES_AND_BUSINESS_DEVELOPMENT', 'INVESTMENT']

const info = ref(null)
const loadError = ref('')
const error = ref('')
const ok = ref('')
const busy = ref(false)
const f = reactive({ api: 'disbursement', amount: null, currency: 'XAF', from_currency: 'XAF', to_currency: 'XAF', fxrate: 1, aml_cft: false,
  sender_first_name: '', sender_last_name: '', sender_mobile_phone: '', sender_country: 'CG', sender_email: '', sender_city: '',
  first_name: '', last_name: '', mobile_phone: '', country: 'CG', to_country: 'CG', email: '',
  bank_name: '', bank_address: '', bank_iban: '', bank_swift: '', purpose: 'FAMILY', fund_origin: 'SALARY', reason: '' })

const money = (v, c) => (v == null ? '—' : Number(v).toLocaleString('fr-FR') + ' ' + (c || info.value?.transaction.currency || 'XAF'))
const nextTrack = computed(() => info.value ? `${info.value.transaction.reference}-M${(info.value.refunds?.length || 0) + 1}` : '')

// Champs envoyés à l'API PEEX choisie (doc peex-api-docs.peexit.com)
const FIELDS = {
  disbursement: ['amount', 'currency', 'mobile_phone', 'sender_first_name', 'sender_last_name', 'sender_mobile_phone', 'first_name', 'last_name', 'country', 'purpose', 'fund_origin'],
  remittance: ['amount', 'mobile_phone', 'from_currency', 'to_currency', 'fxrate', 'aml_cft', 'sender_first_name', 'sender_last_name', 'sender_mobile_phone', 'sender_country', 'first_name', 'last_name', 'to_country', 'purpose', 'fund_origin', 'email', 'sender_email', 'sender_city'],
  bank: ['bank_name', 'bank_address', 'bank_iban', 'bank_swift', 'amount', 'aml_cft', 'fxrate', 'from_currency', 'to_currency', 'to_country', 'sender_first_name', 'sender_last_name', 'sender_email', 'sender_mobile_phone', 'sender_country', 'mobile_phone', 'purpose', 'fund_origin'],
}
const payload = computed(() => {
  const out = { track_id: nextTrack.value }
  for (const k of FIELDS[f.api]) {
    const v = k === 'aml_cft' ? (f.aml_cft ? 1 : 0) : f[k]
    if (v !== '' && v !== null && v !== undefined) out[k] = v
  }
  if (f.api === 'bank') out.transaction_type = 'bank'
  return out
})
const preview = computed(() => JSON.stringify(payload.value, null, 2))

async function load() {
  try {
    const { data } = await api.get(`/admin/transactions/${props.transactionId}/refunds`)
    info.value = data
    if (f.amount == null && data.peex) {
      Object.assign(f, data.peex, { aml_cft: false })
      for (const k of Object.keys(f)) if (f[k] === null) f[k] = ''
    }
  } catch (e) { loadError.value = e.response?.data?.message || e.message }
}

async function submit() {
  error.value = ''; ok.value = ''
  const to = f.api === 'bank' ? 'IBAN ' + f.bank_iban : f.mobile_phone
  if (!confirm(`Envoyer ${money(f.amount, f.api === 'disbursement' ? f.currency : f.from_currency)} à ${f.first_name} ${f.last_name} (${to}) via PEEX ?`)) return
  busy.value = true
  try {
    const body = { ...payload.value, api: f.api, reason: f.reason, aml_cft: f.aml_cft }
    delete body.track_id
    delete body.transaction_type
    const { data } = await api.post(`/admin/transactions/${props.transactionId}/refunds`, body)
    ok.value = data.message
    f.amount = null
    await load()
    emit('done')
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = (errs && Object.values(errs).flat().join(' ')) || e.response?.data?.message || e.message
    await load()
  } finally { busy.value = false }
}

async function refresh(r) {
  try { await api.post(`/admin/transactions/${props.transactionId}/refunds/${r.id}/refresh`); await load() } catch (e) { error.value = e.response?.data?.message || e.message }
}

onMounted(load)
</script>

<style scoped>
.rf-row { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.status.pending { background: #eff6ff; color: #1d4ed8; }
.pf-apis { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.pf-apis button { text-align: left; background: #fff; border: 1px solid var(--border-strong); border-radius: 10px; padding: 8px 10px; cursor: pointer; font: inherit; display: grid; gap: 3px; min-width: 0; }
.pf-apis button strong { font-size: 13px; }
.pf-apis button code { font-size: 11px; color: var(--text-2); overflow-wrap: anywhere; }
.pf-apis button.on { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(30, 58, 138, .12); background: var(--info-bg); }
.pf { border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px 12px; margin: 0; min-width: 0; }
.pf legend { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-2); padding: 0 6px; }
.pf-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px 12px; }
.pf-grid label, .pf-check { display: grid; gap: 4px; font-size: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; color: var(--text-2); min-width: 0; }
.pf-grid input, .pf-grid select { width: 100%; font-family: inherit; }
.pf-grid .span2 { grid-column: span 2; }
.pf-check { display: flex; align-items: center; gap: 8px; margin-top: 10px; color: var(--text); }
.pf-check input { width: auto; }
.up { text-transform: uppercase; }
.pf-json summary { cursor: pointer; font-size: 13px; color: var(--link, #0972d3); }
.pf-json pre { margin: 6px 0 0; padding: 10px; background: #0f172a; color: #e2e8f0; border-radius: 8px; font-size: 11.5px; max-height: 260px; overflow: auto; }
@media (max-width: 720px) { .pf-grid, .pf-apis { grid-template-columns: 1fr 1fr; } }
</style>
