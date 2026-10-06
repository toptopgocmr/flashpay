<template>
  <div>
    <div class="page-header">
      <div><h1>Demandes de plafonds</h1><p>Demandes de relèvement de plafonds envoyées depuis l'app, avec leur justificatif.</p></div>
      <div class="actions"><button class="btn-normal" @click="load">Actualiser</button></div>
    </div>
    <div class="tabs mb">
      <button v-for="t in ['pending', 'approved', 'rejected', 'cancelled']" :key="t" :class="{ on: status === t }" @click="status = t; load()">{{ LBL[t] }}</button>
    </div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>
    <section class="container">
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Client</th><th>Motif & justification</th><th>Actuels</th><th>Demandés</th><th>Justificatif</th><th>Demandé</th><th></th></tr></thead>
          <tbody>
            <tr v-for="r in d?.data || []" :key="r.id">
              <td>
                <router-link :to="`/clients/${r.user_id}`"><strong>{{ r.user?.full_name }}</strong></router-link><br />
                <small class="mono">{{ $phone(r.user?.phone) }}</small><br />
                <small>Palier KYC {{ r.current_tier }} · solde {{ money(r.balance, r.currency) }}</small>
              </td>
              <td style="max-width:320px;"><strong>{{ r.reason_label }}</strong><br /><small style="white-space:pre-wrap;">{{ r.justification }}</small></td>
              <td><small v-for="k in KEYS" :key="k" v-show="r.current_limits?.[k]">{{ LIM[k] }} : {{ money(r.current_limits?.[k], r.currency) }}<br /></small>
                <small v-if="r.usage" style="color:var(--muted)">Utilisé ce mois : {{ money(r.usage.monthly, r.currency) }}</small></td>
              <td><small v-for="k in KEYS" :key="k" v-show="r[k]"><strong>{{ LIM[k] }} : {{ money(r[k], r.currency) }}</strong><br /></small>
                <small v-if="r.granted" style="color:var(--ok)">Accordé : <span v-for="(v, k) in r.granted" :key="k">{{ LIM[k] }} {{ money(v, r.currency) }} · </span><span v-if="r.granted_until">jusqu'au {{ day(r.granted_until) }}</span></small>
                <small v-if="r.decision_note"><br /><em>« {{ r.decision_note }} »</em></small></td>
              <td><button v-if="r.has_file" class="btn-normal" @click="view(r)">Voir</button><span v-else>—</span></td>
              <td>{{ date(r.created_at) }}<span v-if="r.reviewer"><br /><small>{{ r.reviewer.full_name }} · {{ date(r.reviewed_at) }}</small></span></td>
              <td class="actions-cell" v-if="r.status === 'pending'">
                <IconAction icon="check" tone="ok" label="Accorder" @click="openApprove(r)" />
                <IconAction icon="ban" tone="danger" label="Refuser" @click="act = { r, decision: 'reject', note: '' }" />
              </td><td v-else></td>
            </tr>
          </tbody>
        </table>
        <div v-if="d && !d.data.length" class="empty"><strong>Aucune demande</strong></div>
      </div>
    </section>

    <Modal v-if="act" :title="act.decision === 'approve' ? 'Accorder des plafonds' : 'Refuser la demande'" :subtitle="act.r.user?.full_name" @close="act = null">
      <div v-if="error" class="flash err" style="margin:0"><div>{{ error }}</div></div>
      <template v-if="act.decision === 'approve'">
        <div class="hint">Montants pré-remplis avec la demande : ajustez-les si besoin (laisser vide = non modifié).</div>
        <div class="row2">
          <div v-for="k in KEYS" :key="k"><label class="field">{{ LIM[k] }} ({{ act.r.currency }})</label><input v-model.number="act[k]" type="number" min="0" /></div>
        </div>
        <div class="row2">
          <div><label class="field">Valable jusqu'au (facultatif)</label><input v-model="act.until" type="date" /></div>
          <div><label class="field">Message au client (facultatif)</label><input v-model.trim="act.note" placeholder="Ex. accordé pour la période des fêtes" /></div>
        </div>
      </template>
      <div v-else><label class="field">Motif du refus (envoyé au client)</label><input v-model.trim="act.note" placeholder="Ex. justificatif illisible, merci de renvoyer une facture récente" /></div>
      <template #foot><button class="btn-normal" @click="act = null">Annuler</button><button class="btn" :disabled="busy" @click="submit">Confirmer</button></template>
    </Modal>

    <Modal v-if="preview" title="Justificatif" :subtitle="preview.user?.full_name" @close="closePreview">
      <img v-if="previewUrl && !previewPdf" :src="previewUrl" style="max-width:100%;border-radius:8px" />
      <iframe v-else-if="previewUrl" :src="previewUrl" style="width:100%;height:60vh;border:0"></iframe>
    </Modal>
  </div>
</template>

<script setup>
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'
import { money, date, errMsg } from '../utils/format'

const LBL = { pending: 'En attente', approved: 'Accordées', rejected: 'Refusées', cancelled: 'Annulées' }
const KEYS = ['daily', 'monthly', 'per_operation', 'max_balance']
const LIM = { daily: 'Par jour', monthly: 'Par mois', per_operation: 'Par opération', max_balance: 'Solde max' }
const d = ref(null)
const status = ref('pending')
const act = ref(null)
const busy = ref(false)
const error = ref('')
const msg = ref('')
const preview = ref(null)
const previewUrl = ref('')
const previewPdf = ref(false)
const day = (s) => (s ? new Date(s).toLocaleDateString('fr-FR') : '')

async function load() {
  const { data } = await api.get('/admin/limit-requests', { params: { status: status.value } })
  d.value = data
}
function openApprove(r) {
  error.value = ''
  act.value = { r, decision: 'approve', daily: r.daily, monthly: r.monthly, per_operation: r.per_operation, max_balance: r.max_balance, until: '', note: '' }
}
async function view(r) {
  preview.value = r
  const res = await api.get(`/admin/limit-requests/${r.id}/file`, { responseType: 'blob' })
  previewPdf.value = res.data.type === 'application/pdf'
  previewUrl.value = URL.createObjectURL(res.data)
}
function closePreview() {
  URL.revokeObjectURL(previewUrl.value)
  preview.value = null
  previewUrl.value = ''
}
async function submit() {
  busy.value = true
  error.value = ''
  try {
    const a = act.value
    const payload = { decision: a.decision, note: a.note || undefined }
    if (a.decision === 'approve') {
      for (const k of KEYS) if (a[k]) payload[k] = a[k]
      if (a.until) payload.until = a.until
    }
    await api.post(`/admin/limit-requests/${a.r.id}/review`, payload)
    msg.value = a.decision === 'approve' ? `Plafonds de ${a.r.user?.full_name} relevés, client notifié.` : 'Demande refusée, client notifié.'
    act.value = null
    load()
  } catch (e) {
    error.value = errMsg(e)
  } finally {
    busy.value = false
  }
}
onMounted(load)
</script>
