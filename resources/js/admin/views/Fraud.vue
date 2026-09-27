<template>
  <div>
    <div class="page-header">
      <div><h1>Anti-fraude</h1><p>Alertes générées par les contrôles (§4.5) : vélocité anormale, seuil LCB-FT, blocages temporaires, comptes déclarés perdus/volés. Levez le blocage après vérification d'identité.</p></div>
      <div class="actions">
        <ExportButton filename="alertes-fraude" :columns="EXP_COLS" :fetch="expFetch" /><button class="btn-normal" @click="load">Actualiser</button></div>
    </div>
    <div class="tabs mb">
      <button v-for="t in ['open', 'cleared', 'confirmed']" :key="t" :class="{ on: status === t }" @click="status = t; load()">{{ LBL[t] }}</button>
    </div>
    <section class="container">
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Règle</th><th>Utilisateur</th><th>Score</th><th>Détails</th><th>Bloqué jusqu'au</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <tr v-for="a in d?.data || []" :key="a.id">
              <td><strong>{{ RULE[a.rule] || a.rule }}</strong></td>
              <td>{{ a.user?.full_name }}<br /><small class="mono">{{ $phone(a.user?.phone) }}</small></td>
              <td><span class="status" :class="a.score >= 80 ? 'err' : 'warn'">{{ a.score }}</span> <small>risque profil {{ a.user?.risk_score }}</small></td>
              <td class="mono" style="font-size:12px;max-width:280px">{{ JSON.stringify(a.details) }}</td>
              <td>{{ date(a.user?.blocked_until) }}</td>
              <td>{{ date(a.created_at) }}</td>
              <td class="actions-cell" v-if="a.status === 'open'">
                <IconAction icon="unlock" tone="ok" label="Faux positif · débloquer le compte" @click="review(a, 'cleared', true)" />
                <IconAction icon="alert" tone="danger" label="Confirmer la fraude" @click="review(a, 'confirmed', false)" />
              </td><td v-else></td>
            </tr>
          </tbody>
        </table>
        <div v-if="d && !d.data.length" class="empty"><strong>Aucune alerte</strong></div>
      </div>
    </section>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import { date } from '../utils/format'

const LBL = { open: 'Ouvertes', cleared: 'Levées', confirmed: 'Confirmées' }
const RULE = { velocity: 'Vélocité anormale', aml_threshold: 'Seuil LCB-FT dépassé' }
const d = ref(null)
const status = ref('open')
async function load() {
  d.value = (await api.get('/admin/fraud-alerts', { params: { status: status.value } })).data
}
async function review(a, decision, unblock) {
  const note = prompt('Note de revue (optionnelle)') ?? ''
  await api.post(`/admin/fraud-alerts/${a.id}`, { decision, unblock, note })
  load()
}
onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Règle', value: (a) => RULE[a.rule] || a.rule },
  { label: 'Utilisateur', value: (a) => a.user?.full_name },
  { label: 'Téléphone', value: (a) => fmtPhone(a.user?.phone) },
  { label: 'Score', value: (a) => a.score },
  { label: 'Risque profil', value: (a) => a.user?.risk_score },
  { label: 'Statut', value: (a) => LBL[a.status] || a.status },
  { label: 'Détails', value: (a) => JSON.stringify(a.details || {}) },
  { label: 'Date', value: (a) => fmtDate(a.created_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/fraud-alerts', { status: status.value }, onP)
</script>
