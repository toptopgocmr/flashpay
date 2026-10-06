<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Équipe interne</h1>
        <p>Comptes de la console : Super Admin (tous les droits) et Support (opérations, KYC, litiges).</p>
      </div>
      <div class="actions">
        <ExportButton filename="equipe-interne" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn" @click="openNew">Ajouter un membre</button>
      </div>
    </div>

    <div class="grid grid-3 mb">
      <div class="card us-stat"><span class="pastille blue sm"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/></svg></span><div><b>{{ list.length }}</b><small>membres</small></div></div>
      <div class="card us-stat"><span class="pastille sm"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/></svg></span><div><b>{{ count('super_admin') }}</b><small>Super Admin</small></div></div>
      <div class="card us-stat"><span class="pastille blue sm"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/></svg></span><div><b>{{ count('support') }}</b><small>Support</small></div></div>
    </div>

    <section class="container">
      <div class="toolbar">
        <div class="search grow">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
          <input v-model="q" placeholder="Rechercher un nom ou un numéro" />
        </div>
      </div>
      <div class="container-body flush">
        <table>
          <thead><tr><th>Membre</th><th>Téléphone</th><th>Rôle</th><th>Créé le</th></tr></thead>
          <tbody>
            <tr v-if="loading"><td colspan="4"><div class="skeleton" style="height:18px"></div></td></tr>
            <tr v-for="u in shown" :key="u.id">
              <td><div class="who"><span class="ava">{{ initials(u.full_name) }}</span><div><strong>{{ u.full_name }}</strong><small v-if="u.email">{{ u.email }}</small></div></div></td>
              <td class="mono">{{ $phone(u.phone) }}</td>
              <td><span v-for="r in u.roles || []" :key="r.name" class="role-chip" :class="{ red: r.name === 'super_admin' }" style="margin-right:4px">{{ ROLE[r.name] || r.name }}</span></td>
              <td>{{ new Date(u.created_at).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }) }}</td>
            </tr>
            <tr v-if="!loading && !shown.length"><td colspan="4"><div class="empty"><strong>Aucun membre</strong></div></td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <Modal v-if="form" title="Ajouter un membre de l'équipe" subtitle="Il se connecte à la console avec ce numéro et ce mot de passe." @close="form = null">
      <div v-if="error" class="flash err" style="margin:0"><div>{{ error }}</div></div>
      <div><label class="field">Nom complet</label><input v-model="form.full_name" placeholder="Ex. Grâce Mabiala" /></div>
      <div class="row2">
        <div><label class="field">Téléphone</label><input v-model="form.phone" placeholder="+242 06 …" /></div>
        <div><label class="field">Mot de passe (6 caractères min.)</label><input v-model="form.password" type="password" autocomplete="new-password" /></div>
      </div>
      <div>
        <label class="field">Rôle</label>
        <div class="us-roles">
          <button v-for="(l, k) in ROLE_CREATE" :key="k" type="button" :class="{ on: form.role === k }" @click="form.role = k">
            <strong>{{ l.t }}</strong><small>{{ l.d }}</small>
          </button>
        </div>
      </div>
      <template #foot>
        <button class="btn-normal" @click="form = null">Annuler</button>
        <button class="btn" :disabled="saving || !form.full_name || !form.phone || form.password.length < 6" @click="submit">{{ saving ? 'Création…' : 'Créer le compte' }}</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import Modal from '../components/Modal.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import { computed, ref, onMounted } from 'vue'
import api from '../services/api'
import { errMsg } from '../utils/format'
import { toast } from '../utils/ui'

const ROLE = { super_admin: 'Super Admin', support: 'Support', agent: 'Agent' }
const ROLE_CREATE = {
  support: { t: 'Support', d: 'Opérations, KYC, litiges, chat' },
  super_admin: { t: 'Super Admin', d: 'Tous les droits, paramètres, tarifs' },
  agent: { t: 'Agent', d: 'Compte agent de l\'app mobile' },
}
const list = ref([])
const loading = ref(true)
const q = ref('')
const form = ref(null)
const error = ref('')
const saving = ref(false)

const count = (r) => list.value.filter((u) => (u.roles || []).some((x) => x.name === r)).length
const initials = (s) => String(s || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('')
const shown = computed(() => {
  const t = q.value.trim().toLowerCase()
  const digits = t.replace(/\D/g, '')
  return t ? list.value.filter((u) => (u.full_name || '').toLowerCase().includes(t) || (digits && String(u.phone).includes(digits))) : list.value
})

async function load() {
  loading.value = true
  try { list.value = await fetchAllPages('/admin/users', {}) } finally { loading.value = false }
}
function openNew() { error.value = ''; form.value = { full_name: '', phone: '', password: '', role: 'support' } }
async function submit() {
  error.value = ''
  saving.value = true
  try {
    await api.post('/admin/users', form.value)
    toast(`Compte créé pour ${form.value.full_name}.`)
    form.value = null
    await load()
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = (errs && Object.values(errs).flat()[0]) || errMsg(e)
  } finally { saving.value = false }
}
onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Nom', value: (u) => u.full_name },
  { label: 'Téléphone', value: (u) => fmtPhone(u.phone) },
  { label: 'Rôles', value: (u) => (u.roles || []).map((r) => ROLE[r.name] || r.name).join(', ') },
  { label: 'Créé le', value: (u) => fmtDate(u.created_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/users', {}, onP)
</script>

<style scoped>
.us-stat { display: flex; align-items: center; gap: 12px; padding: 14px 18px; }
.us-stat b { display: block; font-size: 22px; line-height: 1.1; }
.us-stat small { color: var(--text-2); }
.us-roles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.us-roles button { text-align: left; background: #fff; border: 1px solid var(--border-strong); border-radius: 10px; padding: 9px 11px; cursor: pointer; font: inherit; }
.us-roles button strong { display: block; font-size: 13.5px; }
.us-roles button small { color: var(--text-2); font-size: 11.5px; }
.us-roles button.on { border-color: var(--brand); background: var(--info-bg); box-shadow: 0 0 0 3px rgba(30, 58, 138, .12); }
@media (max-width: 600px) { .us-roles { grid-template-columns: 1fr; } }
</style>
