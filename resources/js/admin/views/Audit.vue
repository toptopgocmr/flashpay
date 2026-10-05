<template>
  <div>
    <div class="page-header">
      <div><h1>Journal d'audit</h1></div>
      <div class="actions">
        <ExportButton filename="journal-audit" :columns="EXP_COLS" :fetch="expFetch" /><button class="btn-normal" @click="verify">Vérifier l'intégrité</button></div>
    </div>
    <div v-if="integrity" class="flash" :class="integrity.intact ? 'info' : 'err'"><div>{{ integrity.intact ? `Chaîne intègre (${integrity.entries} entrées).` : `Altération détectée à l'entrée #${integrity.first_broken_id} !` }}</div></div>
    <div class="toolbar mb"><input v-model="action" placeholder="Filtrer par action (ex. wallet., kyc., refund.)" @keyup.enter="load" /><button class="btn-normal" @click="load">Filtrer</button></div>
    <section class="container">
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>#</th><th>Date</th><th>Acteur</th><th>Action</th><th>Objet</th><th>Données</th><th>IP</th></tr></thead>
          <tbody>
            <tr v-for="l in d?.data || []" :key="l.id">
              <td>{{ l.id }}</td><td>{{ date(l.created_at) }}</td><td>{{ l.actor?.full_name || 'Système' }}</td>
              <td class="mono">{{ l.action }}</td><td>{{ l.subject_type }} #{{ l.subject_id }}</td>
              <td class="mono" style="font-size:12px;max-width:360px;word-break:break-all">{{ l.data ? JSON.stringify(l.data) : '' }}</td><td class="mono">{{ l.ip }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import { date } from '../utils/format'

const d = ref(null)
const action = ref('')
const integrity = ref(null)
async function load() {
  d.value = (await api.get('/admin/audit-logs', { params: action.value ? { action: action.value } : {} })).data
}
async function verify() {
  integrity.value = (await api.get('/admin/audit-logs/verify')).data
}
onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: '#', value: (l) => l.id },
  { label: 'Date', value: (l) => fmtDate(l.created_at) },
  { label: 'Acteur', value: (l) => l.actor?.full_name || 'Système' },
  { label: 'Action', value: (l) => l.action },
  { label: 'Objet', value: (l) => (l.subject_type ? l.subject_type + ' #' + l.subject_id : '') },
  { label: 'Données', value: (l) => (l.data ? JSON.stringify(l.data) : '') },
  { label: 'IP', value: (l) => l.ip },
  { label: 'Empreinte', value: (l) => l.hash },
]
const expFetch = (onP) => fetchAllPages('/admin/audit-logs', action.value ? { action: action.value } : {}, onP)
</script>
