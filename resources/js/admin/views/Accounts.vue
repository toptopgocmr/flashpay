<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Comptes utilisateurs</h1><p>Tous les comptes de la plateforme : activation, désactivation et réinitialisation d'accès.</p>
      </div>
      <div class="actions">
        <ExportButton filename="comptes-utilisateurs" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn-ok" :disabled="busy" @click="ask(true, 'all')">Activer tous</button>
        <button class="btn-danger" :disabled="busy" @click="ask(false, 'all')">Désactiver tous</button>
      </div>
    </div>

    <div v-if="msg" class="flash" :class="msg.type"><div>{{ msg.text }}</div></div>

    <div class="counts mb">
      <button :class="{ on: !f.status }" @click="setStatus('')"><span>Tous</span><b>{{ n(counts.total) }}</b></button>
      <button :class="{ on: f.status === 'active' }" @click="setStatus('active')"><span><i class="dot ok"></i>Actifs</span><b>{{ n(counts.active) }}</b></button>
      <button :class="{ on: f.status === 'inactive' }" @click="setStatus('inactive')"><span><i class="dot err"></i>Désactivés</span><b>{{ n(counts.inactive) }}</b></button>
    </div>

    <section class="container">
      <div class="container-body filters">
        <select v-model="f.role" @change="load(1)" aria-label="Profil">
          <option value="">Tous les profils</option>
          <option v-for="(l, r) in ROLES" :key="r" :value="r">{{ l }}</option>
        </select>
        <input v-model="f.q" @input="debounced" type="search" placeholder="Nom, téléphone ou e-mail…" />
        <span class="spacer"></span>
        <template v-if="selected.length">
          <span class="sel">{{ selected.length }} sélectionné(s)</span>
          <button class="btn-ok" :disabled="busy" @click="ask(true, 'selection')">Activer</button>
          <button class="btn-danger" :disabled="busy" @click="ask(false, 'selection')">Désactiver</button>
          <button class="btn-link" @click="selected = []">Annuler</button>
        </template>
      </div>
      <div class="container-body flush" style="overflow-x: auto;">
        <table>
          <thead>
            <tr>
              <th style="width: 36px;"><input type="checkbox" :checked="allChecked" @change="toggleAll" aria-label="Tout sélectionner" /></th>
              <th>Nom</th><th>Téléphone</th><th>Profil(s)</th><th class="num">Solde</th><th>Statut</th><th>Créé le</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in rows" :key="u.id" :class="{ off: !u.active }">
              <td><input type="checkbox" :value="u.id" v-model="selected" :disabled="u.is_me" /></td>
              <td>
                <strong>{{ u.full_name }}</strong> <span v-if="u.is_me" class="status muted">vous</span>
                <div v-if="u.business_name" class="hint">{{ u.business_name }}</div>
              </td>
              <td class="mono">{{ $phone(u.phone) }}</td>
              <td><span v-for="r in u.roles" :key="r" class="role">{{ ROLES[r] || r }}</span></td>
              <td class="num">{{ u.balance == null ? '—' : n(u.balance) + ' ' + (u.currency || 'XAF') }}</td>
              <td>
                <span class="status" :class="u.active ? 'ok' : 'err'">{{ u.active ? 'Actif' : 'Désactivé' }}</span>
                <div v-if="!u.active && (u.status_reason || u.status_changed_at)" class="hint">
                  {{ u.status_reason || '' }}<template v-if="u.status_changed_at"> · {{ fmt(u.status_changed_at) }}</template>
                </div>
              </td>
              <td>{{ fmt(u.created_at) }}</td>
              <td class="num">
                <span v-if="u.is_me" class="hint">—</span>
                <IconAction v-else-if="u.active" icon="power" tone="danger" label="Désactiver le compte" :disabled="busy" @click="ask(false, 'one', u)" />
                <IconAction v-else icon="power" tone="ok" label="Activer le compte" :disabled="busy" @click="ask(true, 'one', u)" />
              </td>
            </tr>
            <tr v-if="!loading && !rows.length"><td colspan="8" class="stat-label">Aucun compte ne correspond à ces filtres.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="container-foot pager" v-if="meta && meta.last_page > 1">
        <button class="btn-normal" :disabled="!meta.prev_page_url" @click="load(meta.current_page - 1)">Précédent</button>
        <span style="margin: 0 12px;">Page {{ meta.current_page }} / {{ meta.last_page }} · {{ n(meta.total) }} compte(s)</span>
        <button class="btn-normal" :disabled="!meta.next_page_url" @click="load(meta.current_page + 1)">Suivant</button>
      </div>
    </section>

    <Modal v-if="confirm" :title="confirm.title" :subtitle="confirm.subtitle" @close="confirm = null">
      <p style="margin: 0;">{{ confirm.body }}</p>
      <label v-if="!confirm.active">
        Motif (facultatif)
        <input v-model="confirm.reason" maxlength="200" placeholder="Ex. : fraude suspectée, demande du client…" />
      </label>
      <template #foot>
        <button class="btn-normal" @click="confirm = null">Annuler</button>
        <button :class="confirm.active ? 'btn' : 'btn danger-solid'" :disabled="busy" @click="run">{{ confirm.cta }}</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { computed, onMounted, reactive, ref } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'

const ROLES = { client: 'Client', merchant: 'Marchand', agent: 'Agent', support: 'Support', super_admin: 'Super Admin' }

const rows = ref([])
const meta = ref(null)
const counts = ref({})
const loading = ref(false)
const busy = ref(false)
const msg = ref(null)
const selected = ref([])
const confirm = ref(null)
const f = reactive({ role: '', status: '', q: '' })

const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => (v == null ? '—' : nf.format(v))
const fmt = (s) => new Date(s).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' })
const params = () => Object.fromEntries(Object.entries(f).filter(([, v]) => v))

const selectable = computed(() => rows.value.filter((u) => !u.is_me).map((u) => u.id))
const allChecked = computed(() => selectable.value.length > 0 && selectable.value.every((id) => selected.value.includes(id)))
function toggleAll() {
  selected.value = allChecked.value ? [] : [...new Set([...selected.value, ...selectable.value])]
}

async function load(page = 1) {
  loading.value = true
  try {
    const { data } = await api.get('/admin/accounts', { params: { ...params(), page } })
    rows.value = data.data
    meta.value = data
    counts.value = data.counts || {}
  } catch (e) {
    msg.value = { type: 'err', text: e.response?.data?.message || e.message }
  } finally {
    loading.value = false
  }
}

let t = null
function debounced() { clearTimeout(t); t = setTimeout(() => load(1), 300) }
function setStatus(s) { f.status = s; selected.value = []; load(1) }

function ask(active, scope, user = null) {
  const verb = active ? 'Activer' : 'Désactiver'
  const filtered = f.role || f.status || f.q
  let body
  if (scope === 'one') {
    body = active
      ? `${user.full_name} pourra de nouveau se connecter et effectuer des opérations.`
      : `${user.full_name} sera déconnecté immédiatement et ne pourra plus se connecter ni effectuer d'opérations.`
  } else if (scope === 'selection') {
    body = `${selected.value.length} compte(s) sélectionné(s) seront ${active ? 'activés' : 'désactivés et déconnectés immédiatement'}.`
  } else {
    body = (filtered ? 'Tous les comptes correspondant aux filtres actuels' : 'TOUS les comptes de la plateforme')
      + (active ? ' seront activés.' : ' seront désactivés et déconnectés immédiatement. Votre compte et les Super Admins sont exclus.')
  }
  confirm.value = {
    active, scope, user, reason: '', body,
    title: scope === 'one' ? `${verb} le compte` : `${verb} ${scope === 'all' ? 'tous les comptes' : 'la sélection'}`,
    subtitle: scope === 'one' ? `${user.full_name} · ${user.phone}` : '',
    cta: scope === 'one' ? verb : `${verb} ${scope === 'all' ? 'tous' : selected.value.length + ' compte(s)'}`,
  }
}

async function run() {
  const c = confirm.value
  busy.value = true
  try {
    let data
    if (c.scope === 'one') {
      ({ data } = await api.post(`/admin/accounts/${c.user.id}/status`, { active: c.active, reason: c.reason || null }))
    } else if (c.scope === 'selection') {
      ({ data } = await api.post('/admin/accounts/bulk-status', { active: c.active, ids: selected.value, reason: c.reason || null }))
    } else {
      ({ data } = await api.post('/admin/accounts/bulk-status', { active: c.active, all: true, ...params(), reason: c.reason || null }))
    }
    msg.value = { type: 'info', text: data.message }
    selected.value = []
    confirm.value = null
    await load(meta.value?.current_page || 1)
  } catch (e) {
    msg.value = { type: 'err', text: e.response?.data?.message || e.message }
    confirm.value = null
  } finally {
    busy.value = false
  }
}

onMounted(() => load())

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Nom', value: (u) => u.full_name },
  { label: 'Commerce', value: (u) => u.business_name },
  { label: 'Téléphone', value: (u) => fmtPhone(u.phone) },
  { label: 'Profil(s)', value: (u) => (u.roles || []).map((r) => ROLES[r] || r).join(', ') },
  { label: 'Solde', value: (u) => u.balance ?? '' },
  { label: 'Devise', value: (u) => u.currency },
  { label: 'Statut', value: (u) => (u.active ? 'Actif' : 'Désactivé') },
  { label: 'Motif', value: (u) => u.status_reason },
  { label: 'Créé le', value: (u) => fmtDate(u.created_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/accounts', params(), onP)
</script>

<style scoped>
.counts { display: grid; grid-template-columns: repeat(3, minmax(0, 220px)); gap: 12px; }
.counts button { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; padding: 12px 16px; border: 1px solid var(--border); border-radius: 10px; background: var(--surface, #fff); cursor: pointer; font: inherit; text-align: left; }
.counts button:hover { border-color: var(--link); }
.counts button.on { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(30, 58, 138, .12); }
.counts span { font-size: 13px; color: var(--text-2); }
.counts b { font-size: 24px; }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; }
.dot.ok { background: var(--ok); } .dot.err { background: var(--err); }
.filters { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.filters select, .filters input { padding: 8px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
.filters input { min-width: 240px; }
.spacer { flex: 1; }
.sel { font-weight: 600; }
.role { display: inline-block; font-size: 12px; padding: 2px 8px; margin: 0 4px 2px 0; border-radius: 99px; background: var(--info-bg); color: var(--link); }
tr.off td { background: #fff7f7; }
.hint { font-size: 12px; color: var(--text-2); margin-top: 2px; }
.btn-ok:disabled, .btn-danger:disabled { opacity: .5; cursor: not-allowed; }
.danger-solid { background: var(--err); border-color: var(--err); color: #fff; }
.danger-solid:hover { background: #991b1b; }
@media (max-width: 640px) { .counts { grid-template-columns: repeat(3, 1fr); } .filters input { min-width: 0; flex: 1; } }
</style>
