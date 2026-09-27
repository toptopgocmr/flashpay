<template>
  <div>
    <div class="page-header">
      <div><h1>Réconciliation</h1><p>Contrôle automatique toutes les heures (§13.1) : équilibre du ledger, cohérence solde wallet ↔ écritures, opérations bloquées chez l'opérateur (« en cours de vérification »), écritures orphelines PEEX.</p></div>
      <div class="actions"><button class="btn" :disabled="busy" @click="run">Lancer maintenant</button></div>
    </div>
    <div class="grid grid-4 mb" v-if="last">
      <div class="card" v-for="(v, k) in last.results" :key="k"><div class="stat-label">{{ LBL[k] }}</div><div class="stat-value" :style="v.length ? 'color:var(--err)' : ''">{{ v.length }}</div></div>
    </div>
    <section class="container mb" v-for="(rows, k) in last?.results || {}" :key="k" v-show="rows.length">
      <div class="container-head"><h3>{{ LBL[k] }}</h3></div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th v-for="c in Object.keys(rows[0] || {})" :key="c">{{ c }}</th></tr></thead>
          <tbody><tr v-for="(r, i) in rows" :key="i"><td v-for="c in Object.keys(r)" :key="c" class="mono">{{ r[c] }}</td></tr></tbody>
        </table>
      </div>
    </section>
    <section class="container">
      <div class="container-head"><h3>Historique des rapports</h3></div>
      <div class="container-body flush">
        <table>
          <thead><tr><th>#</th><th>Date</th><th>Anomalies</th><th></th></tr></thead>
          <tbody><tr v-for="r in reports" :key="r.id"><td>{{ r.id }}</td><td>{{ date(r.created_at) }}</td><td><span class="status" :class="r.anomalies ? 'err' : 'ok'">{{ r.anomalies }}</span></td><td class="actions-cell"><IconAction icon="eye" label="Voir le détail" @click="last = r" /></td></tr></tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import { date } from '../utils/format'

const LBL = { unbalanced_transactions: 'Transactions déséquilibrées', wallet_mismatches: 'Écarts solde / ledger', stuck_transactions: 'En cours de vérification', peex_orphans: 'Orphelines PEEX' }
const reports = ref([])
const last = ref(null)
const busy = ref(false)
async function load() {
  reports.value = (await api.get('/admin/reconciliation')).data
  last.value = reports.value[0] || null
}
async function run() {
  busy.value = true
  try { await api.post('/admin/reconciliation/run'); await load() } finally { busy.value = false }
}
onMounted(load)
</script>
