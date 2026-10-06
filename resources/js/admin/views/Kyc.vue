<template>
  <div>
    <div class="page-header">
      <div><h1>Validation KYC</h1><p>Pièces d'identité, selfies et documents marchands à vérifier ; la validation relève les paliers et les plafonds.</p></div>
      <div class="actions">
        <ExportButton filename="kyc" :columns="EXP_COLS" :fetch="expFetch" /><button class="btn-normal" @click="load">Actualiser</button></div>
    </div>
    <div class="tabs mb">
      <button v-for="t in ['pending', 'approved', 'rejected']" :key="t" :class="{ on: status === t }" @click="status = t; load()">{{ LBL[t] }}</button>
    </div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>
    <section class="container">
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Utilisateur</th><th>Profil</th><th>Document</th><th>Palier actuel</th><th>Envoyé</th><th></th></tr></thead>
          <tbody>
            <tr v-for="doc in d?.data || []" :key="doc.id">
              <td><strong>{{ doc.user?.full_name }}</strong><br /><small class="mono">{{ $phone(doc.user?.phone) }}</small></td>
              <td><span class="role-chip" :class="{ red: ['merchant', 'cashier', 'super_agent'].includes(doc.role) }">{{ ROLE[doc.role] || doc.role }}</span></td>
              <td>{{ doc.type_label }}<br /><small v-if="doc.rejection_reason" style="color:var(--err)">{{ doc.rejection_reason }}</small></td>
              <td>Palier {{ doc.user?.kyc_tier }}</td>
              <td>{{ date(doc.created_at) }}</td>
              <td class="actions-cell">
                <IconAction icon="eye" label="Voir le document" @click="view(doc)" />
                <template v-if="doc.status === 'pending'">
                  <IconAction icon="check" tone="ok" label="Valider le document" @click="review(doc, 'approved')" />
                  <IconAction icon="ban" tone="danger" label="Refuser le document" @click="rejecting = doc; reason = ''" />
                </template>
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="d && !d.data.length" class="empty"><strong>Aucun document</strong></div>
      </div>
    </section>
    <Modal v-if="preview" :title="preview.type_label" :subtitle="preview.user?.full_name" @close="closePreview">
      <img v-if="previewUrl && !previewPdf" :src="previewUrl" style="max-width:100%;border-radius:8px" />
      <iframe v-else-if="previewUrl" :src="previewUrl" style="width:100%;height:60vh;border:0"></iframe>
    </Modal>
    <Modal v-if="rejecting" title="Refuser le document" :subtitle="rejecting.user?.full_name" @close="rejecting = null">
      <label class="field">Motif (communiqué à l'utilisateur)</label>
      <input v-model.trim="reason" placeholder="Ex. photo floue, pièce expirée" />
      <template #foot><button class="btn-normal" @click="rejecting = null">Annuler</button><button class="btn" :disabled="!reason" @click="review(rejecting, 'rejected', reason)">Refuser</button></template>
    </Modal>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'
import { date, errMsg } from '../utils/format'

const LBL = { pending: 'En attente', approved: 'Validés', rejected: 'Refusés' }
const ROLE = { client: 'Client', merchant: 'Marchand', agent: 'Agent', cashier: 'Caissier', support: 'Support', super_admin: 'Super Admin' }
const d = ref(null)
const status = ref('pending')
const msg = ref('')
const preview = ref(null)
const previewUrl = ref('')
const previewPdf = ref(false)
const rejecting = ref(null)
const reason = ref('')

async function load() {
  const { data } = await api.get('/support/desk/kyc', { params: { status: status.value } })
  d.value = data
}
async function view(doc) {
  preview.value = doc
  const res = await api.get(`/support/desk/kyc/documents/${doc.id}/file`, { responseType: 'blob' })
  previewPdf.value = res.data.type === 'application/pdf'
  previewUrl.value = URL.createObjectURL(res.data)
}
function closePreview() {
  URL.revokeObjectURL(previewUrl.value)
  preview.value = null
  previewUrl.value = ''
}
async function review(doc, decision, why = null) {
  try {
    const { data } = await api.post(`/admin/kyc/documents/${doc.id}/review`, { decision, reason: why })
    msg.value = `${doc.user?.full_name} : document ${decision === 'approved' ? 'validé' : 'refusé'} — palier ${data.user.kyc_tier}.`
    rejecting.value = null
    load()
  } catch (e) {
    msg.value = errMsg(e)
  }
}
onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Utilisateur', value: (d) => d.user?.full_name },
  { label: 'Téléphone', value: (d) => fmtPhone(d.user?.phone) },
  { label: 'Profil', value: (d) => ROLE[d.role] || d.role },
  { label: 'Document', value: (d) => d.type_label },
  { label: 'Statut', value: (d) => LBL[d.status] || d.status },
  { label: 'Motif de refus', value: (d) => d.rejection_reason },
  { label: 'Palier actuel', value: (d) => d.user?.kyc_tier },
  { label: 'Envoyé le', value: (d) => fmtDate(d.created_at) },
  { label: 'Vérifié par', value: (d) => d.reviewer?.full_name },
  { label: 'Vérifié le', value: (d) => fmtDate(d.reviewed_at) },
]
const expFetch = (onP) => fetchAllPages('/support/desk/kyc', { status: status.value }, onP)
</script>
