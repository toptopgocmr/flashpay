<template>
  <div>
    <div class="page-header">
      <div><h1>Équipe interne</h1></div>
      <div class="actions"><ExportButton filename="equipe-interne" :columns="EXP_COLS" :fetch="expFetch" /></div>
    </div>
    <div class="container mb" style="overflow-x:auto;">
      <table>
        <thead><tr><th>Nom</th><th>Téléphone</th><th>Rôles</th><th>Créé le</th></tr></thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td>{{ u.full_name }}</td>
            <td>{{ $phone(u.phone) }}</td>
            <td>{{ (u.roles || []).map(r => r.name).join(', ') }}</td>
            <td>{{ new Date(u.created_at).toLocaleDateString('fr-FR') }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h3>Créer un profil interne (Support / Agent / Super Admin)</h3>
      <form @submit.prevent="submit" class="grid grid-2" style="gap:12px;">
        <input v-model="form.full_name" placeholder="Nom complet" required />
        <input v-model="form.phone" placeholder="Téléphone" required />
        <input v-model="form.password" type="password" placeholder="Mot de passe" required />
        <select v-model="form.role">
          <option value="support">Support</option>
          <option value="agent">Agent</option>
          <option value="super_admin">Super Admin</option>
        </select>
        <button class="btn" type="submit" style="grid-column: span 2;">Créer</button>
      </form>
    </div>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import { ref, onMounted, reactive } from 'vue'
import api from '../services/api'

const users = ref([])
const form = reactive({ full_name: '', phone: '', password: '', role: 'support' })

async function load() {
  const { data } = await api.get('/admin/users')
  users.value = data.data || data
}

async function submit() {
  await api.post('/admin/users', form)
  form.full_name = ''; form.phone = ''; form.password = ''
  await load()
}

onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Nom', value: (u) => u.full_name },
  { label: 'Téléphone', value: (u) => fmtPhone(u.phone) },
  { label: 'Rôles', value: (u) => (u.roles || []).map((r) => r.name).join(', ') },
  { label: 'Créé le', value: (u) => fmtDate(u.created_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/users', {}, onP)
</script>
