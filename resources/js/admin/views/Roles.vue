<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Rôles & habilitations</h1>
      </div>
      <div class="actions">
        <button class="btn-normal" @click="load">Actualiser</button>
        <button class="btn-normal" :disabled="!anyOverride || busy" @click="confirmReset = 'all'">Tout rétablir par défaut</button>
      </div>
    </div>

    <div v-if="error" class="flash err"><div>{{ error }}</div></div>
    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>

    <div v-if="d" class="roles-layout">
      <!-- ===== Liste des profils ===== -->
      <aside class="container role-list">
        <div class="list-head">Application mobile</div>
        <button v-for="key in keysOf('app')" :key="key" class="role-item" :class="{ on: sel === key }" @click="sel = key">
          <span class="pastille sm" :class="{ blue: d.roles[key].tone === 'blue' }">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICONS[key]"></svg>
          </span>
          <span class="txt"><strong>{{ d.roles[key].label }}</strong><small>{{ num(d.counts[key]?.total) }} compte(s) · {{ grantedCount(key) }} droit(s)</small></span>
          <span v-if="overrides(key)" class="dot" title="Modifié par rapport aux valeurs par défaut"></span>
        </button>
        <div class="list-head">Console</div>
        <button v-for="key in keysOf('console')" :key="key" class="role-item" :class="{ on: sel === key }" @click="sel = key">
          <span class="pastille sm" :class="{ blue: d.roles[key].tone === 'blue' }">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICONS[key]"></svg>
          </span>
          <span class="txt"><strong>{{ d.roles[key].label }}</strong><small>{{ num(d.counts[key]?.total) }} compte(s){{ d.roles[key].locked ? ' · tous les droits' : '' }}</small></span>
          <span v-if="overrides(key)" class="dot"></span>
        </button>
      </aside>

      <!-- ===== Habilitations du profil sélectionné ===== -->
      <section v-if="role" class="container">
        <div class="container-head">
          <div class="who">
            <span class="pastille" :class="{ blue: role.tone === 'blue' }">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICONS[sel]"></svg>
            </span>
            <div>
              <h3 style="margin:0">{{ role.label }}</h3>
              <small>{{ role.space === 'app' ? 'Application mobile' : 'Console' }} · {{ num(d.counts[sel]?.total) }} compte(s), {{ num(d.counts[sel]?.active) }} actif(s)</small>
            </div>
          </div>
          <div class="actions">
            <router-link :to="LINKS[sel]" class="btn-link">Voir les comptes →</router-link>
            <button class="btn-normal" :disabled="!overrides(sel) || busy || role.locked" @click="confirmReset = sel">Rétablir ce profil</button>
          </div>
        </div>
        <div class="container-body">
          <div class="facts">
            <div><span>Création du compte</span><b>{{ role.created_by }}</b></div>
            <div><span>Connexion</span><b>{{ role.login }}</b></div>
          </div>

          <div class="caps">
            <div v-for="(label, cap) in d.capabilities[role.space]" :key="cap" class="cap" :class="['v-' + (role.grants[cap]?.value || 'no'), { changed: role.grants[cap]?.overridden }]">
              <div class="cap-main">
                <div class="cap-label">
                  <strong>{{ label }}</strong>
                  <small>
                    <span v-if="d.enforced.includes(cap)" class="tag">Contrôlé par l'API</span>
                    <span v-else class="tag muted">Affichage dans l'app</span>
                    <template v-if="role.grants[cap]?.overridden"> · par défaut : {{ LBL[role.grants[cap].default] }}</template>
                  </small>
                </div>
                <div class="seg" role="radiogroup" :aria-label="label">
                  <button v-for="v in ['yes', 'limited', 'no']" :key="v" type="button" :class="['s-' + v, { on: (role.grants[cap]?.value || 'no') === v }]"
                          :disabled="role.locked || saving === cap" @click="setGrant(cap, v)">{{ LBL[v] }}</button>
                </div>
              </div>
              <div v-if="role.grants[cap]?.value === 'limited'" class="cap-note">
                <label>Restriction</label>
                <input v-model.trim="notes[cap]" :disabled="role.locked" maxlength="120" placeholder="Ex. uniquement ses propres encaissements"
                       @keyup.enter="saveNote(cap)" @blur="saveNote(cap)" />
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>

    <!-- ===== Vue d'ensemble ===== -->
    <section v-if="d" class="container mb" style="margin-top:16px;">
      <div class="container-head">
        <div><h3>Vue d'ensemble</h3></div>
        <div class="tabs" style="border:0;padding:0;">
          <button :class="{ on: space === 'app' }" @click="space = 'app'">Application mobile</button>
          <button :class="{ on: space === 'console' }" @click="space = 'console'">Console</button>
        </div>
      </div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table class="matrix">
          <thead>
            <tr>
              <th class="sticky">Habilitation</th>
              <th v-for="key in keysOf(space)" :key="key" class="c clickable" :class="{ sel: sel === key }" @click="sel = key">{{ d.roles[key].label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(label, cap) in d.capabilities[space]" :key="cap">
              <td class="sticky">{{ label }}</td>
              <td v-for="key in keysOf(space)" :key="key" class="c" :class="{ sel: sel === key }">
                <span class="g" :class="d.roles[key].grants[cap]?.value || 'no'" :title="d.roles[key].grants[cap]?.note || LBL[d.roles[key].grants[cap]?.value || 'no']">
                  {{ SYM[d.roles[key].grants[cap]?.value || 'no'] }}
                </span>
                <i v-if="d.roles[key].grants[cap]?.overridden" class="mod"></i>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ===== Comptes démo ===== -->
    <section v-if="d?.demo_accounts?.length" class="container">
      <div class="container-head"><div><h3>Comptes de démonstration</h3></div></div>
      <div class="container-body flush">
        <table>
          <thead><tr><th>Profil</th><th>Titulaire</th><th>Numéro</th><th>Code</th></tr></thead>
          <tbody>
            <tr v-for="a in d.demo_accounts" :key="a.phone">
              <td><span class="role-chip" :class="{ red: d.roles[a.role]?.tone === 'red' }">{{ d.roles[a.role]?.label || a.role }}</span></td>
              <td>{{ a.name }}</td>
              <td class="mono">{{ $phone(a.phone) }}</td>
              <td class="mono"><strong>{{ a.code }}</strong></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Modal v-if="confirmReset" title="Rétablir les valeurs par défaut" :subtitle="confirmReset === 'all' ? 'Tous les profils' : d.roles[confirmReset]?.label" @close="confirmReset = null">
      <div>Les habilitations modifiées seront remplacées par celles prévues dans le cahier des charges. Cette action est tracée dans le journal d'audit.</div>
      <template #foot>
        <button class="btn-normal" @click="confirmReset = null">Annuler</button>
        <button class="btn accent" :disabled="busy" @click="reset(confirmReset)">Rétablir</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import api from '../services/api'
import Modal from '../components/Modal.vue'
import { errMsg, num } from '../utils/format'

const d = ref(null)
const error = ref('')
const msg = ref('')
const sel = ref('client')
const space = ref('app')
const busy = ref(false)
const saving = ref(null)
const confirmReset = ref(null)
const notes = reactive({})

const LBL = { yes: 'Autorisé', limited: 'Restreint', no: 'Retiré' }
const SYM = { yes: '✓', limited: '◐', no: '—' }
const LINKS = {
  client: '/clients', merchant: '/merchants', cashier: '/cashiers',
  agent: '/agents?level=simple', sub_agent: '/agents?level=sub', super_agent: '/agents?level=super',
  support: '/users', super_admin: '/users',
}
const ICONS = {
  client: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  merchant: '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/><path d="M5 13v7h14v-7"/>',
  cashier: '<rect x="4" y="3" width="12" height="7" rx="1.5"/><path d="M3 21h18l-2-9H5z"/><path d="M8 15h.01M12 15h.01M16 15h.01"/>',
  agent: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>',
  sub_agent: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M12 11v6M9 14h6"/>',
  super_agent: '<path d="M3 18h18M4 18l-1-10 5 4 4-7 4 7 5-4-1 10"/>',
  support: '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4" height="6" rx="1.5"/><rect x="17" y="14" width="4" height="6" rx="1.5"/>',
  super_admin: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
}

const role = computed(() => d.value?.roles?.[sel.value])
const keysOf = (s) => Object.keys(d.value?.roles || {}).filter((k) => d.value.roles[k].space === s)
const overrides = (key) => Object.values(d.value?.roles?.[key]?.grants || {}).filter((g) => g.overridden).length
const grantedCount = (key) => Object.values(d.value?.roles?.[key]?.grants || {}).filter((g) => g.value !== 'no').length
const anyOverride = computed(() => Object.keys(d.value?.roles || {}).some((k) => overrides(k) > 0))

function syncNotes() {
  Object.keys(notes).forEach((k) => delete notes[k])
  for (const [cap, g] of Object.entries(role.value?.grants || {})) notes[cap] = g.note || ''
}
watch(sel, () => {
  syncNotes()
  if (role.value) space.value = role.value.space
})

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/admin/roles')
    d.value = data
    syncNotes()
  } catch (e) {
    error.value = errMsg(e)
  }
}

async function setGrant(cap, value, note) {
  if (!role.value || role.value.locked) return
  const current = role.value.grants[cap]?.value
  if (current === value && note === undefined) return
  saving.value = cap
  msg.value = ''
  try {
    const n = value === 'limited' ? (note ?? (notes[cap] || role.value.grants[cap]?.default_note || 'Restreint')) : null
    const { data } = await api.put(`/admin/roles/${sel.value}/grants/${cap}`, { value, note: n })
    d.value.roles[sel.value] = data.role
    notes[cap] = data.role.grants[cap]?.note || ''
    msg.value = `${role.value.label} · ${d.value.capabilities[role.value.space][cap]} : ${LBL[value].toLowerCase()}.`
  } catch (e) {
    error.value = errMsg(e)
  } finally {
    saving.value = null
  }
}
function saveNote(cap) {
  const g = role.value?.grants?.[cap]
  if (!g || g.value !== 'limited' || (notes[cap] || '') === (g.note || '')) return
  setGrant(cap, 'limited', notes[cap] || 'Restreint')
}

async function reset(which) {
  busy.value = true
  try {
    const { data } = await api.post('/admin/roles/reset', which === 'all' ? {} : { role: which })
    d.value.roles = data.roles
    syncNotes()
    msg.value = data.message
    confirmReset.value = null
  } catch (e) {
    error.value = errMsg(e)
  } finally {
    busy.value = false
  }
}
onMounted(load)
</script>

<style scoped>
.roles-layout { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 16px; align-items: start; }
.role-list { padding: 8px; position: sticky; top: calc(var(--nav-h) + 12px); }
.list-head { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-3); padding: 10px 10px 6px; }
.role-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border: 0; background: none; border-radius: 12px; cursor: pointer; font: inherit; text-align: left; color: var(--text); }
.role-item:hover { background: var(--surface-2); }
.role-item.on { background: var(--soft); box-shadow: inset 3px 0 0 var(--accent); }
.role-item .txt { display: grid; flex: 1; min-width: 0; }
.role-item small { color: var(--text-2); font-size: 12px; }
.dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); flex: none; }
.who small { display: block; }
.facts { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.facts > div { background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; padding: 10px 14px; }
.facts span { display: block; color: var(--text-2); font-size: 12px; }
.facts b { font-weight: 600; }
.caps { display: grid; gap: 8px; margin-top: 16px; }
.cap { border: 1px solid var(--border); border-radius: 14px; padding: 12px 14px; background: #fff; }
.cap.v-no { background: var(--surface-2); }
.cap.v-no .cap-label strong { color: var(--text-2); }
.cap.changed { border-color: var(--accent); box-shadow: inset 3px 0 0 var(--accent); }
.cap-main { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
.cap-label { display: grid; gap: 2px; min-width: 220px; flex: 1; }
.cap-label small { color: var(--text-2); font-size: 12px; }
.tag { display: inline-block; font-size: 11px; font-weight: 600; padding: 0 7px; border-radius: 8px; background: var(--soft); color: var(--brand); }
.tag.muted { background: #f1f5f9; color: var(--text-2); }
.seg { display: inline-flex; border: 1px solid var(--border-strong); border-radius: 999px; padding: 3px; background: #fff; }
.seg button { border: 0; background: none; font: inherit; font-size: 13px; font-weight: 600; padding: 6px 14px; border-radius: 999px; cursor: pointer; color: var(--text-2); }
.seg button:disabled { cursor: not-allowed; opacity: .6; }
.seg button.on.s-yes { background: var(--ok-bg); color: var(--ok); }
.seg button.on.s-limited { background: var(--rose); color: var(--accent); }
.seg button.on.s-no { background: #e5e7eb; color: #374151; }
.cap-note { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
.cap-note label { font-size: 12px; color: var(--text-2); font-weight: 600; }
.cap-note input { flex: 1; }
.matrix th.c, .matrix td.c { text-align: center; position: relative; }
.matrix th.clickable { cursor: pointer; }
.matrix th.clickable:hover { color: var(--brand); }
.matrix .sel { background: var(--soft); }
.matrix .sticky { position: sticky; left: 0; background: #fff; z-index: 1; min-width: 240px; font-weight: 500; }
.matrix thead .sticky { background: var(--surface-2); }
.g { display: inline-flex; width: 26px; height: 26px; border-radius: 50%; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; }
.g.yes { background: var(--ok-bg); color: var(--ok); }
.g.limited { background: var(--rose); color: var(--accent); cursor: help; }
.g.no { color: var(--text-3); }
.mod { position: absolute; top: 8px; right: calc(50% - 18px); width: 7px; height: 7px; border-radius: 50%; background: var(--accent); }
@media (max-width: 1100px) {
  .roles-layout { grid-template-columns: 1fr; }
  .role-list { position: static; display: flex; flex-wrap: wrap; gap: 4px; }
  .role-list .list-head { width: 100%; }
  .role-item { width: auto; }
  .facts { grid-template-columns: 1fr; }
}
</style>
