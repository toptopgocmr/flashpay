<template>
  <Modal :title="'Remboursement PEEX — ' + (info?.transaction.reference || '')" subtitle="L'argent part du compte PEEX de FlashPay vers le mobile money ou le compte bancaire du client." @close="$emit('close')">
    <div v-if="!info" class="stat-label">{{ loadError || 'Chargement…' }}</div>
    <template v-else>
      <div class="creds">
        <div><span>Opération</span><b>{{ money(info.transaction.amount) }} + frais {{ money(info.transaction.fee) }}</b></div>
        <div><span>Encore remboursable</span><b>{{ money(info.refundable) }}</b></div>
      </div>

      <div class="seg-types" style="grid-template-columns: 1fr 1fr;">
        <button type="button" :class="{ on: f.channel === 'mobile' }" @click="f.channel = 'mobile'"><strong>Mobile money</strong><small>MTN, Airtel, Orange… via PEEX</small></button>
        <button type="button" :class="{ on: f.channel === 'bank' }" @click="f.channel = 'bank'"><strong>Compte bancaire</strong><small>Virement PEEX (IBAN + SWIFT)</small></button>
      </div>

      <div class="row2">
        <div><label class="field">Montant à rembourser *</label><input v-model.number="f.amount" type="number" min="1" :max="info.refundable" /></div>
        <div><label class="field">Devise</label><input v-model="f.currency" maxlength="3" /></div>
      </div>
      <div><label class="field">Nom complet du bénéficiaire *</label><input v-model="f.beneficiary_name" placeholder="Prénom Nom (titulaire du compte)" /></div>

      <template v-if="f.channel === 'mobile'">
        <div class="row2">
          <div><label class="field">Numéro mobile money *</label><input v-model="f.phone" placeholder="+242 06 000 00 00" /></div>
          <div><label class="field">Pays (ISO)</label><input v-model="f.country" maxlength="2" placeholder="CG" /></div>
        </div>
      </template>

      <template v-else>
        <div class="section-title">Banque du bénéficiaire</div>
        <div class="row2">
          <div><label class="field">Nom de la banque</label><input v-model="f.bank_name" placeholder="Ex. BGFIBank Congo" /></div>
          <div><label class="field">Adresse de la banque *</label><input v-model="f.bank_address" placeholder="Agence, ville" /></div>
        </div>
        <div><label class="field">IBAN / RIB *</label><input v-model="f.bank_iban" class="mono" placeholder="CG39 30011 00010 …" /></div>
        <div class="row2">
          <div><label class="field">Code SWIFT / BIC *</label><input v-model="f.bank_swift" class="mono" maxlength="11" placeholder="BGFICGCG" /></div>
          <div><label class="field">Pays de la banque (ISO) *</label><input v-model="f.to_country" maxlength="2" placeholder="CG" /></div>
        </div>
        <div class="row2">
          <div><label class="field">Devise reçue</label><input v-model="f.to_currency" maxlength="3" placeholder="XAF" /></div>
          <div><label class="field">Téléphone du bénéficiaire</label><input v-model="f.mobile_phone" placeholder="+242…" /></div>
        </div>
        <div><label class="field">E-mail du bénéficiaire</label><input v-model="f.email" type="email" /></div>
      </template>

      <div class="row2">
        <div><label class="field">Objet (purpose)</label><input v-model="f.purpose" placeholder="Remboursement" /></div>
        <div><label class="field">Origine des fonds</label><input v-model="f.fund_origin" placeholder="Remboursement" /></div>
      </div>
      <div><label class="field">Motif interne *</label><input v-model="f.reason" placeholder="Ex. versement échoué, remboursement automatique refusé" /></div>

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
      <button class="btn-primary" :disabled="!info || busy || !info.refundable" @click="submit">{{ busy ? 'Envoi…' : 'Rembourser via PEEX' }}</button>
    </template>
  </Modal>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import Modal from './Modal.vue'
import api from '../services/api'

const props = defineProps({ transactionId: { type: Number, required: true } })
const emit = defineEmits(['close', 'done'])
const LABEL = { successful: 'Remboursé', failed: 'Échoué', pending: 'En cours' }

const info = ref(null)
const loadError = ref('')
const error = ref('')
const ok = ref('')
const busy = ref(false)
const f = reactive({ channel: 'mobile', amount: null, currency: 'XAF', beneficiary_name: '', phone: '', country: 'CG',
  bank_name: '', bank_address: '', bank_iban: '', bank_swift: '', to_country: 'CG', to_currency: '', mobile_phone: '', email: '',
  purpose: 'Remboursement', fund_origin: 'Remboursement', reason: '' })

const money = (v, c) => (v == null ? '—' : Number(v).toLocaleString('fr-FR') + ' ' + (c || info.value?.transaction.currency || 'XAF'))

async function load() {
  try {
    const { data } = await api.get(`/admin/transactions/${props.transactionId}/refunds`)
    info.value = data
    if (f.amount == null) {
      Object.assign(f, { amount: data.refundable, currency: data.defaults.currency || 'XAF', beneficiary_name: data.defaults.beneficiary_name || '',
        phone: data.defaults.phone || '', country: data.defaults.country || 'CG', mobile_phone: data.defaults.phone || '', to_country: data.defaults.country || 'CG' })
    }
  } catch (e) { loadError.value = e.response?.data?.message || e.message }
}

async function submit() {
  error.value = ''; ok.value = ''
  if (!confirm(`Envoyer ${money(f.amount, f.currency)} à ${f.beneficiary_name} (${f.channel === 'bank' ? 'compte bancaire ' + f.bank_iban : f.phone}) depuis le compte PEEX de FlashPay ?`)) return
  busy.value = true
  try {
    const payload = { ...f }
    const { data } = await api.post(`/admin/transactions/${props.transactionId}/refunds`, payload)
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
</style>
