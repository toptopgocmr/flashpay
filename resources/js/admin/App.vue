<template>
  <router-view v-if="isLoginPage" />

  <div v-else :class="['shell', { collapsed }]" @click="closeMenus">
    <!-- Alerte visuelle accompagnant le son d'une nouvelle notification -->
    <router-link v-if="toast" to="/notifications" class="fp-toast" :class="toast.severity" @click="toast = null">
      <strong>{{ toast.title }}</strong><span v-if="toast.body">{{ toast.body }}</span>
    </router-link>
    <!-- Le navigateur bloque le son tant qu'on n'a pas cliqué : bandeau pour l'activer -->
    <button v-if="soundOn && soundBlocked" class="fp-sound-bar" @click.stop="enableSound">
      🔔 Cliquez ici pour activer le son et les alertes Windows des notifications
    </button>
    <!-- ============ Barre de navigation supérieure ============ -->
    <header class="topnav" @click.stop>
      <router-link to="/" class="brand">
        <span class="brand-chip"><img src="/images/flashpay-logo.svg" alt="" class="brand-logo" /></span>
        FlashPay <small>Console</small>
      </router-link>

      <button class="nav-btn" :class="{ open: servicesOpen }" @click="toggle('services')">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><rect x="1" y="1" width="6" height="6" rx="1"/><rect x="9" y="1" width="6" height="6" rx="1"/><rect x="1" y="9" width="6" height="6" rx="1"/><rect x="9" y="9" width="6" height="6" rx="1"/></svg>
        Services
      </button>

      <div class="nav-search">
        <svg class="icon" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="#fff" stroke-width="2"><circle cx="7" cy="7" r="5"/><path d="M11 11l4 4"/></svg>
        <input
          v-model="query"
          placeholder="Rechercher un service, une page…  [Alt+S]"
          ref="searchInput"
          @focus="searchOpen = true"
          @keydown.down.prevent="move(1)"
          @keydown.up.prevent="move(-1)"
          @keydown.enter.prevent="go(results[cursor])"
          @keydown.esc="searchOpen = false"
        />
        <div class="results" v-if="searchOpen && query && results.length">
          <a v-for="(r, i) in results" :key="r.to" href="#" :class="{ active: i === cursor }" @mousedown.prevent="go(r)">
            {{ r.label }} <small>{{ r.group }}</small>
          </a>
        </div>
      </div>

      <div class="nav-spacer"></div>

      <span class="nav-btn hide-sm" title="Pays / devise"><Flag iso="CG" :size="15" title="Congo-Brazzaville" /> Congo · XAF</span>
      <button class="nav-btn" :title="soundOn ? 'Son des notifications activé (cliquer pour couper)' : 'Son des notifications coupé (cliquer pour activer)'" @click="toggleSound">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 5L6 9H2v6h4l5 4z"/><template v-if="soundOn"><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M19 5a10 10 0 0 1 0 14"/></template><path v-else d="M22 9l-6 6M16 9l6 6"/></svg>
      </button>
      <router-link to="/notifications" class="nav-btn" title="Notifications">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21h4"/></svg>
        <span v-if="badges.notifications" class="bell-count">{{ badges.notifications }}</span>
      </router-link>
      <span v-for="e in envs" :key="e.key" class="env-pill" :class="e.sandbox ? 'sandbox' : 'live'" :title="'Environnement ' + e.name">{{ e.name }} · {{ e.sandbox ? 'SANDBOX' : 'PROD' }}</span>

      <div class="dropdown">
        <button class="nav-btn" :class="{ open: userOpen }" @click="toggle('user')">
          <span class="avatar">{{ userName.slice(0, 1).toUpperCase() }}</span>
          <span class="hide-sm">{{ userName }}</span>
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4.5l3 3 3-3"/></svg>
        </button>
        <div class="dropdown-menu" v-if="userOpen">
          <div class="head">
            <strong>{{ auth.user?.full_name || 'Administrateur' }}</strong>
            <span>{{ $phone(auth.user?.phone) }} · {{ roleLabel }}</span>
          </div>
          <router-link to="/accounts" @click="closeMenus">Comptes utilisateurs</router-link>
          <router-link to="/users" @click="closeMenus">Utilisateurs internes</router-link>
          <router-link to="/tariffs" @click="closeMenus">Paramètres tarifaires</router-link>
          <button @click="logout">Se déconnecter</button>
        </div>
      </div>
    </header>

    <!-- Menu "Services" -->
    <div class="services-menu" v-if="servicesOpen" @click.stop>
      <div v-for="g in groups" :key="g.name">
        <h4>{{ g.name }}</h4>
        <router-link v-for="l in g.links" :key="l.to" :to="l.to" @click="closeMenus">
          {{ l.label }}<small>{{ l.desc }}</small>
        </router-link>
      </div>
    </div>

    <!-- ============ Panneau latéral ============ -->
    <aside class="sidenav" @click.stop>
      <div class="sidenav-head">
        <strong>Navigation</strong>
        <button class="icon-btn" title="Réduire le menu (icônes)" @click="collapsed = true">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/></svg>
        </button>
      </div>
      <nav>
        <div v-for="g in groups" :key="g.name" class="nav-group" :class="{ open: isOpen(g) }">
          <button type="button" class="section" :aria-expanded="isOpen(g)" @click="toggleGroup(g)">
            <span>{{ g.name }}</span>
            <span v-if="!isOpen(g) && groupCount(g)" class="count">{{ groupCount(g) }}</span>
            <svg class="chev" width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4.5l3 3 3-3"/></svg>
          </button>
          <div v-show="isOpen(g)" class="links">
            <router-link v-for="l in g.links" :key="l.to" :to="l.to" :class="{ active: isActive(l.to) }" @click="onNav">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICONS[l.icon]"></svg>
              <span>{{ l.label }}</span>
              <span v-if="l.badge && badges[l.badge]" class="count">{{ badges[l.badge] }}</span>
            </router-link>
          </div>
        </div>
      </nav>
      <div class="foot">FlashPay Group · Brazzaville<br /><span style="font-size:11px;">Console v2 · {{ envs.map((e) => e.name + ' ' + (e.sandbox ? 'sandbox' : 'production')).join(' · ') }}</span></div>
    </aside>

    <div v-if="!collapsed && narrow" class="side-backdrop" @click.stop="collapsed = true"></div>
 <!-- Menu réduit : rail d'icônes (une par rubrique), sous-menu au survol -->
    <aside v-if="collapsed" class="siderail" @click.stop @mouseleave="leaveRail">
      <button class="rail-btn rail-toggle" title="Afficher le menu complet" @click="collapsed = false; flyout = null">
        <svg viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
      </button>
      <button
        v-for="g in groups"
        :key="g.name"
        type="button"
        class="rail-item"
        :class="{ active: groupHasActive(g), hover: flyout?.name === g.name }"
        :title="g.name"
        @mouseenter="openFly(g, $event)"
        @click="toggleFly(g, $event)"
      >
        <svg viewBox="0 0 24 24" v-html="ICONS[g.icon]"></svg>
        <span v-if="groupCount(g)" class="rail-dot">{{ groupCount(g) > 99 ? '99+' : groupCount(g) }}</span>
      </button>
    </aside>
    <div v-if="collapsed && flyout" class="rail-fly" :style="{ top: flyout.top + 'px' }" @click.stop @mouseenter="cancelLeave" @mouseleave="leaveRail">
      <h4>{{ flyout.name }}</h4>
      <router-link v-for="l in flyout.links" :key="l.to" :to="l.to" :class="{ active: isActive(l.to) }" @click="flyout = null">
        <svg viewBox="0 0 24 24" v-html="ICONS[l.icon]"></svg>
        <span>{{ l.label }}</span>
        <span v-if="l.badge && badges[l.badge]" class="count">{{ badges[l.badge] }}</span>
      </router-link>
    </div>

    <button v-if="collapsed" class="side-open-btn" title="Afficher le menu" @click.stop="collapsed = false">
      <svg width="18" height="18" viewBox="0 0 18 18" stroke="currentColor" stroke-width="2"><path d="M2 4h14M2 9h14M2 14h14"/></svg>
    </button>

    <!-- ============ Contenu ============ -->
    <main class="main">
      <div class="breadcrumbs">
        <router-link to="/">FlashPay</router-link>
        <span class="sep">›</span>
        <span>{{ currentLabel }}</span>
      </div>
      <router-view />
    </main>
    <SupportCallBar />
    <UiHost />
  </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import api from './services/api'
import Flag from './components/Flag.vue'
import SupportCallBar from './components/SupportCallBar.vue'
import UiHost from './components/UiHost.vue'
import { playNotificationSound, unlockAudio } from './utils/sound'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const ICONS = {
  home: '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M10 20v-6h4v6"/>',
  list: '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
  store: '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/><path d="M5 13v7h14v-7"/>',
  agent: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>',
  gateway: '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
  globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
  tag: '<path d="M20 12l-8 8-9-9V3h8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
  bank: '<path d="M3 10l9-6 9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8"/><path d="M3 20h18"/>',
  lock: '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
  bell: '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10 21h4"/>',
  shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
  id: '<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="11" r="2.5"/><path d="M6 16c.5-1.5 1.7-2.2 3-2.2s2.5.7 3 2.2M14 10h4M14 13h3"/>',
  float: '<path d="M12 2v20M17 6H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
  chat: '<path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z"/>',
  scale: '<path d="M12 3v18M5 7h14M5 7l-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z"/>',
  code: '<path d="M8 6l-6 6 6 6M16 6l6 6-6 6"/>',
  grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
  gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
  pos: '<rect x="4" y="3" width="12" height="7" rx="1.5"/><path d="M3 21h18l-2-9H5z"/><path d="M8 15h.01M12 15h.01M16 15h.01"/>',
  key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M18 5l2 2"/>',
  users: '<circle cx="9" cy="8" r="3.5"/><path d="M2 20c0-3.5 3-5.5 7-5.5s7 2 7 5.5"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5c2.5.6 4 2.4 4 5.5"/>',
}

// Menu réorganisé par usage : piloter, gérer le réseau, traiter les validations,
// régler les tarifs, brancher les partenaires, administrer la plateforme.
const groups = [
  { name: 'Pilotage', icon: 'home', links: [
    { to: '/', label: 'Tableau de bord', desc: "Vue d'ensemble de l'activité", icon: 'home' },
    { to: '/transactions', label: 'Transactions', desc: 'Toutes les opérations, filtres par canal', icon: 'list', badge: 'processing' },
    { to: '/notifications', label: 'Notifications', desc: 'Centre d\'alertes : KYC, float, fraude, incidents', icon: 'bell', badge: 'notifications' },
  ] },
  { name: 'Réseau & comptes', icon: 'users', links: [
    { to: '/clients', label: 'Clients', desc: 'Comptes clients : fiche, wallet, KYC, activation', icon: 'users', badge: 'kyc' },
    { to: '/merchants', label: 'Marchands', desc: 'Comptes marchands et validations', icon: 'store', badge: 'merchants' },
    { to: '/cashiers', label: 'Caissiers', desc: 'Sous-comptes d\'encaissement des marchands', icon: 'pos' },
    { to: '/agents', label: 'Agents', desc: 'Agents, sous-agents et super-agents', icon: 'agent', badge: 'agents' },
    { to: '/accounts', label: 'Comptes utilisateurs', desc: 'Activer / désactiver les comptes', icon: 'lock', badge: 'inactive' },
  ] },
  { name: 'À traiter', icon: 'shield', links: [
    { to: '/kyc', label: 'Validation KYC', desc: 'Pièces à valider, paliers et plafonds', icon: 'id', badge: 'kyc_docs' },
    { to: '/float-requests', label: 'Approvisionnements', desc: 'Demandes de float des agents', icon: 'float', badge: 'float' },
    { to: '/limit-requests', label: 'Demandes de plafonds', desc: 'Relèvement des plafonds avec justificatif', icon: 'id', badge: 'limits' },
    { to: '/settlements', label: 'Règlements', desc: 'Virements bancaires des marchands à exécuter', icon: 'bank', badge: 'bank' },
    { to: '/support-chat', label: 'Chat support', desc: 'Messages, notes vocales et appels des clients', icon: 'chat', badge: 'support_chat' },
    { to: '/support', label: 'Litiges & support', desc: 'Contestations et tickets (SLA)', icon: 'chat', badge: 'disputes' },
    { to: '/chat-monitor', label: 'Supervision des échanges', desc: 'Toutes les discussions et appels (conformité)', icon: 'shield' },
    { to: '/fraud', label: 'Anti-fraude', desc: 'Alertes, blocages temporaires', icon: 'shield', badge: 'fraud' },
  ] },
  { name: 'Tarifs & finance', icon: 'tag', links: [
    { to: '/tariffs', label: 'Grille tarifaire', desc: 'Frais par opération et par zone', icon: 'tag' },
    { to: '/commissions', label: 'Commissions agents', desc: 'Barème par opération et palier', icon: 'tag' },
    { to: '/corridors', label: 'Pays & change', desc: 'Corridors, opérateurs, taux de change', icon: 'globe' },
    { to: '/reconciliation', label: 'Réconciliation', desc: 'Ledger, soldes, orphelines PEEX', icon: 'scale' },
  ] },
  { name: 'Intégrations', icon: 'gateway', links: [
    { to: '/peex', label: 'Passerelle PEEX', desc: 'Collecte, décaissement, tests sandbox', icon: 'gateway' },
    { to: '/wacepay', label: 'Passerelle WacePay', desc: 'API Digitwace : clés, IP, services, tests', icon: 'gateway' },
    { to: '/ecommerce', label: 'E-commerce & API', desc: 'Clés API, payment intents, webhooks', icon: 'code', badge: 'webhooks' },
    { to: '/mini-programs', label: 'Mini-programmes', desc: 'Services partenaires dans l\'app', icon: 'grid' },
  ] },
  { name: 'Administration', icon: 'gear', links: [
    { to: '/roles', label: 'Rôles & habilitations', desc: 'Ajouter / retirer les droits de chaque profil', icon: 'key' },
    { to: '/users', label: 'Équipe interne', desc: 'Super Admin et Support', icon: 'users' },
    { to: '/settings', label: 'Continuité & plafonds', desc: 'Mode dégradé par canal, plafonds KYC', icon: 'gear' },
    { to: '/audit', label: 'Journal d\'audit', desc: 'Trace immuable des interventions', icon: 'list' },
  ] },
]
const badges = ref({})
// --- Son + alerte à l'arrivée d'une nouvelle notification (§11.4)
const soundOn = ref(true)
try { soundOn.value = localStorage.getItem('fp_admin_sound') !== 'off' } catch (_) {}
function toggleSound() {
  soundOn.value = !soundOn.value
  try { localStorage.setItem('fp_admin_sound', soundOn.value ? 'on' : 'off') } catch (_) {}
  unlockAudio()
  if (soundOn.value) ring('info') // son de test à l'activation
  if (soundOn.value && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission().catch(() => {})
}
const toast = ref(null)
// Tant qu'aucun clic n'a eu lieu, le navigateur bloque le son : on l'annonce d'emblée.
const soundBlocked = ref(true)
function onFirstGesture() {
  unlockAudio()
  // Le bandeau reste si les alertes Windows n'ont jamais été proposées
  if (!('Notification' in window) || Notification.permission !== 'default') soundBlocked.value = false
}
// Clic sur le bandeau : débloque le son, demande l'autorisation des notifications
// Windows (elles ont leur propre son, même onglet en arrière-plan), joue un test.
async function enableSound() {
  unlockAudio()
  if ('Notification' in window && Notification.permission === 'default') {
    try { await Notification.requestPermission() } catch (_) {}
  }
  soundBlocked.value = !(await playNotificationSound('info'))
}
async function ring(severity) {
  if (!soundOn.value) return
  const ok = await playNotificationSound(severity)
  soundBlocked.value = !ok
}
let lastNotifId = null
let toastTimer = null
function announce(n) {
  ring(n.severity)
  toast.value = n
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => { toast.value = null }, 8000)
  // Notification du système si l'onglet n'est pas au premier plan
  // Alerte Windows / système (avec son du système), toujours émise si autorisée
  if ('Notification' in window && Notification.permission === 'granted') {
    try {
      const sys = new Notification('FlashPay · ' + n.title, { body: n.body || '', tag: 'fp-' + n.id, silent: false, icon: '/images/flashpay-logo.svg' })
      sys.onclick = () => { window.focus(); router.push('/notifications'); sys.close() }
    } catch (_) {}
  }
}
async function loadBadges() {
  try {
    const { data } = await api.get('/admin/badges', { silent: true })
    badges.value = data
    const n = data.last_notification
    const unread = Number(data.notifications || 0)
    if (n) {
      if (lastNotifId !== null && n.id > lastNotifId) announce(n)
      else if (lastNotifId === null && unread > 0 && !announcedAtLogin) {
        // À l'ouverture de la console : un son si des notifications ne sont pas lues
        announcedAtLogin = true
        ring(n.severity)
      }
      lastNotifId = Math.max(lastNotifId ?? 0, n.id)
    } else if (lastNotifId === null) {
      lastNotifId = 0
    }
  } catch (_) {}
}
let pollTimer = null
let announcedAtLogin = false
const allLinks = groups.flatMap((g) => g.links.map((l) => ({ ...l, group: g.name })))

const isLoginPage = computed(() => route.path === '/login')
// En dessous de 1100 px, le menu se réduit en rail d'icônes (comme WacePay) ;
// le menu complet s'ouvre alors en panneau superposé. Au-dessus, le choix
// (complet / réduit) est mémorisé par navigateur.
const NARROW = 1100
const narrow = ref(window.innerWidth < NARROW)
let savedMini = false
try { savedMini = localStorage.getItem('fp_admin_nav_mini') === '1' } catch (_) {}
const collapsed = ref(narrow.value || savedMini)
watch(collapsed, (c) => { if (!narrow.value) { try { localStorage.setItem('fp_admin_nav_mini', c ? '1' : '0') } catch (_) {} } })
function onResize() {
  const n = window.innerWidth < NARROW
  if (n !== narrow.value) {
    narrow.value = n
    collapsed.value = n || savedMiniNow()
  }
}
function savedMiniNow() {
  try { return localStorage.getItem('fp_admin_nav_mini') === '1' } catch (_) { return false }
}
// Groupes du menu repliables ; l'état est mémorisé par navigateur.
const openGroups = ref({})
try { openGroups.value = JSON.parse(localStorage.getItem('fp_admin_nav_groups') || '{}') } catch (_) {}
function groupHasActive(g) { return g.links.some((l) => isActive(l.to)) }
function isOpen(g) { return openGroups.value[g.name] ?? (g.name === 'Pilotage' || g.name === 'À traiter' || groupHasActive(g)) }
function toggleGroup(g) {
  openGroups.value = { ...openGroups.value, [g.name]: !isOpen(g) }
  try { localStorage.setItem('fp_admin_nav_groups', JSON.stringify(openGroups.value)) } catch (_) {}
}
function groupCount(g) { return g.links.reduce((a, l) => a + (l.badge ? Number(badges.value[l.badge] || 0) : 0), 0) }
const servicesOpen = ref(false)
const userOpen = ref(false)
const searchOpen = ref(false)
const query = ref('')
const cursor = ref(0)
const searchInput = ref(null)
const sandbox = ref(true)
const envs = ref([{ key: 'peex', name: 'PEEX', sandbox: true }])

const userName = computed(() => (auth.user?.full_name || 'Admin').split(' ')[0])
const roleLabel = computed(() => (auth.user?.roles || []).includes('super_admin') ? 'Super Admin' : 'Support')
const currentLabel = computed(() => {
  if (route.path.startsWith('/transactions/')) return 'Détail de transaction'
  if (route.path.startsWith('/clients/')) return 'Fiche client'
  if (route.path.startsWith('/agents/')) return 'Fiche agent'
  return allLinks.find((l) => l.to === route.path)?.label || ''
})
const results = computed(() => {
  const q = query.value.toLowerCase().trim()
  return allLinks.filter((l) => (l.label + ' ' + l.desc).toLowerCase().includes(q)).slice(0, 6)
})
watch(query, () => { cursor.value = 0 })

function isActive(to) {
  return to === '/' ? route.path === '/' : (route.path === to || route.path.startsWith(to + '/'))
}
function toggle(which) {
  const s = which === 'services'
  servicesOpen.value = s ? !servicesOpen.value : false
  userOpen.value = s ? false : !userOpen.value
}
// --- Rail d'icônes (menu réduit) : sous-menu flottant de la rubrique survolée
const flyout = ref(null)
let leaveTimer = null
function openFly(g, ev) {
  clearTimeout(leaveTimer)
  const r = ev.currentTarget.getBoundingClientRect()
  const h = 56 + g.links.length * 40
  const top = Math.max(64, Math.min(r.top - 6, window.innerHeight - h - 12))
  flyout.value = { name: g.name, links: g.links, top }
}
function toggleFly(g, ev) {
  if (flyout.value?.name === g.name) flyout.value = null
  else openFly(g, ev)
}
function leaveRail() {
  clearTimeout(leaveTimer)
  leaveTimer = setTimeout(() => { flyout.value = null }, 220)
}
function cancelLeave() { clearTimeout(leaveTimer) }
watch(() => route.path, () => { flyout.value = null })
watch(collapsed, (c) => { if (!c) flyout.value = null })

function closeMenus() {
  flyout.value = null
  servicesOpen.value = false
  userOpen.value = false
  searchOpen.value = false
}
function onNav() {
  if (narrow.value) collapsed.value = true
}
function move(d) {
  if (!results.value.length) return
  cursor.value = (cursor.value + d + results.value.length) % results.value.length
}
function go(r) {
  if (!r) return
  router.push(r.to)
  query.value = ''
  closeMenus()
  searchInput.value?.blur()
}
function logout() {
  auth.logout()
  closeMenus()
  router.push('/login')
}
function onKey(e) {
  if (e.altKey && (e.key === 's' || e.key === 'S')) {
    e.preventDefault()
    searchInput.value?.focus()
  }
}

async function loadEnv() {
  let token = null
  try { token = localStorage.getItem('flashpay_admin_token') } catch (_) {}
  if (!token || isLoginPage.value) return
  try {
    const { data } = await api.get('/peex/corridors')
    sandbox.value = !!data.sandbox
    envs.value = (data.environments || [{ key: 'peex', name: 'PEEX', sandbox: !!data.sandbox, enabled: true }]).filter((e) => e.enabled)
  } catch (_) { /* non bloquant */ }
  loadBadges()
}
watch(isLoginPage, (v) => { if (!v) loadEnv() })
watch(() => route.path, () => { if (!isLoginPage.value) loadBadges() })
// Ouvre automatiquement le groupe du menu de la page affichée
watch(() => route.path, (path) => {
  const g = groups.find((x) => x.links.some((l) => (l.to === '/' ? path === '/' : path.startsWith(l.to))))
  if (g && openGroups.value[g.name] === false) openGroups.value = { ...openGroups.value, [g.name]: true }
}, { immediate: true })

onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('resize', onResize)
  // Débloque le son à chaque interaction (le navigateur peut le re-suspendre)
  window.addEventListener('pointerdown', onFirstGesture)
  window.addEventListener('keydown', onFirstGesture)
  // Une page (ex. Notifications) demande de recompter les non lues
  window.addEventListener('fp:badges', loadBadges)
  loadEnv()
  // Vérifie les nouvelles notifications toutes les 15 secondes
  pollTimer = setInterval(() => { if (!isLoginPage.value) loadBadges() }, 15000)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('resize', onResize)
  window.removeEventListener('pointerdown', onFirstGesture)
  window.removeEventListener('keydown', onFirstGesture)
  window.removeEventListener('fp:badges', loadBadges)
  clearInterval(pollTimer)
})
</script>

<style>
/* ---------- Rail d'icônes (menu réduit, style WacePay) ---------- */
.siderail {
  position: fixed; top: var(--nav-h); bottom: 0; left: 0; width: var(--rail-w, 76px); z-index: 41;
  background: var(--surface, #fff); border-right: 1px solid var(--border, #e5e7eb);
  display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 16px 0; overflow-y: auto;
  animation: fpRailIn .18s ease-out;
}
.rail-btn, .rail-item {
  position: relative; flex: none; width: 52px; height: 52px; border-radius: 14px; border: 0; background: none;
  display: grid; place-items: center; cursor: pointer; color: var(--brand, #1e3a8a); transition: background .15s, color .15s, transform .15s;
}
.rail-btn svg, .rail-item svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
.rail-toggle { color: var(--text-2, #6b7280); margin-bottom: 6px; }
.rail-btn:hover, .rail-item:hover, .rail-item.hover { background: var(--surface-2, #f1f5f9); }
.rail-item.active { background: #fdece2; color: var(--accent, #ea7a1a); }
.rail-item:active { transform: scale(.94); }
.rail-dot {
  position: absolute; top: 5px; right: 4px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px;
  background: var(--accent, #e11d2a); color: #fff; font-size: 10px; font-weight: 800; display: grid; place-items: center; line-height: 1;
  box-shadow: 0 0 0 2px var(--surface, #fff);
}
.rail-fly {
  position: fixed; left: calc(var(--rail-w, 76px) + 8px); z-index: 60; width: 270px; max-height: calc(100vh - 80px); overflow-y: auto;
  background: #fff; border: 1px solid var(--border, #e5e7eb); border-radius: 14px; padding: 10px 8px;
  box-shadow: 0 18px 44px rgba(15, 23, 42, .18); animation: fpFlyIn .14s ease-out;
}
.rail-fly h4 { margin: 2px 10px 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--text-3, #9ca3af); }
.rail-fly a { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 9px; color: #334155; font-size: 13.5px; font-weight: 500; text-decoration: none; }
.rail-fly a svg { flex: none; width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; color: var(--text-3, #9ca3af); }
.rail-fly a:hover { background: var(--surface-2, #f1f5f9); color: var(--text, #111827); }
.rail-fly a.active { background: var(--soft, #eef2ff); color: var(--brand, #1e3a8a); font-weight: 650; box-shadow: inset 3px 0 0 var(--accent, #ea7a1a); }
.rail-fly a.active svg { color: var(--accent, #ea7a1a); }
.rail-fly a .count { margin-left: auto; background: var(--accent, #e11d2a); color: #fff; font-size: 11px; font-weight: 800; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; }
@keyframes fpRailIn { from { transform: translateX(-12px); opacity: 0; } to { transform: none; opacity: 1; } }
@keyframes fpFlyIn { from { transform: translateX(-6px); opacity: 0; } to { transform: none; opacity: 1; } }

.bell-count { background: var(--accent); color: #fff; font-size: 11px; font-weight: 800; border-radius: 10px; padding: 0 6px; margin-left: 2px; }
.fp-toast { position: fixed; top: 70px; right: 20px; z-index: 200; max-width: 380px; background: #fff; border-left: 5px solid var(--brand); border-radius: 12px; box-shadow: 0 12px 30px rgba(15, 23, 42, .18); padding: 12px 16px; display: grid; gap: 2px; color: var(--text); text-decoration: none; animation: fpToast .25s ease-out; }
.fp-sound-bar { position: fixed; bottom: 18px; left: 50%; transform: translateX(-50%); z-index: 210; background: var(--brand, #1e3a8a); color: #fff; border: 0; border-radius: 999px; padding: 12px 22px; font-size: 14px; font-weight: 600; cursor: pointer; box-shadow: 0 10px 28px rgba(15, 23, 42, .3); }
.fp-toast span { font-size: 13px; color: var(--text-2); }
.fp-toast.warning { border-left-color: #f59e0b; }
.fp-toast.critical { border-left-color: var(--err); }
.fp-toast.success { border-left-color: var(--ok); }
@keyframes fpToast { from { transform: translateY(-10px); opacity: 0; } to { transform: none; opacity: 1; } }
</style>
