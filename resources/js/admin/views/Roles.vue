<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Rôles & habilitations</h1>
        <p>Qui fait quoi dans FlashPay : les six profils de l'application mobile (client, marchand, caissier, agent, sous-agent, super-agent) et les deux profils de la console. La matrice reflète les droits appliqués par l'API.</p>
      </div>
      <div class="actions"><button class="btn-normal" @click="load">Actualiser</button></div>
    </div>

    <div v-if="error" class="flash err"><div>{{ error }}</div></div>

    <!-- ===== Effectifs par rôle ===== -->
    <section class="container mb">
      <div class="container-head"><div><h3>Effectifs par rôle</h3><p>Cliquez sur un rôle pour ouvrir la liste correspondante</p></div></div>
      <div class="container-body">
        <div class="role-tiles">
          <router-link v-for="(r, key) in d?.roles || {}" :key="key" :to="LINKS[key] || '/'" class="role-tile">
            <span class="pastille lg" :class="{ blue: r.tone === 'blue' }">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="ICONS[key]"></svg>
            </span>
            <span class="l">{{ r.label }}</span>
            <span class="n">{{ num(d.counts[key]?.total) }}</span>
            <small>{{ num(d.counts[key]?.active) }} actif(s) · {{ r.space === 'app' ? 'App mobile' : 'Console' }}</small>
          </router-link>
        </div>
      </div>
    </section>

    <!-- ===== Matrice ===== -->
    <section class="container mb">
      <div class="container-head">
        <div><h3>Matrice des habilitations</h3><p>✓ autorisé · ◐ autorisé avec restriction (survolez pour le détail) · — non autorisé</p></div>
        <div class="tabs" style="border:0;padding:0;">
          <button :class="{ on: space === 'app' }" @click="space = 'app'">Application mobile</button>
          <button :class="{ on: space === 'console' }" @click="space = 'console'">Console</button>
        </div>
      </div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table class="matrix">
          <thead>
            <tr>
              <th>Habilitation</th>
              <th v-for="key in columns" :key="key" class="c">{{ d.roles[key].label }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(label, cap) in d?.capabilities?.[space] || {}" :key="cap">
              <td>{{ label }}</td>
              <td v-for="key in columns" :key="key" class="c">
                <span v-if="grant(key, cap) === 'yes'" class="g yes" title="Autorisé">✓</span>
                <span v-else-if="grant(key, cap)" class="g lim" :title="grant(key, cap)">◐</span>
                <span v-else class="g no">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ===== Accès par rôle ===== -->
    <div class="grid grid-2 mb">
      <section class="container">
        <div class="container-head"><div><h3>Création des comptes & connexion</h3></div></div>
        <div class="container-body flush">
          <table>
            <thead><tr><th>Rôle</th><th>Créé par</th><th>Connexion</th></tr></thead>
            <tbody>
              <tr v-for="(r, key) in d?.roles || {}" :key="key">
                <td><span class="role-chip" :class="{ red: r.tone === 'red' }">{{ r.label }}</span></td>
                <td>{{ r.created_by }}</td>
                <td>{{ r.login }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="container">
        <div class="container-head"><div><h3>Comptes de démonstration</h3><p>Environnement de test uniquement — non créés en production</p></div></div>
        <div class="container-body flush">
          <table v-if="d?.demo_accounts?.length">
            <thead><tr><th>Rôle</th><th>Titulaire</th><th>Numéro</th><th>Code</th></tr></thead>
            <tbody>
              <tr v-for="a in d.demo_accounts" :key="a.phone">
                <td><span class="role-chip" :class="{ red: d.roles[a.role]?.tone === 'red' }">{{ d.roles[a.role]?.label || a.role }}</span></td>
                <td>{{ a.name }}</td>
                <td class="mono">{{ $phone(a.phone) }}</td>
                <td class="mono"><strong>{{ a.code }}</strong></td>
              </tr>
            </tbody>
          </table>
          <div v-else class="empty"><strong>Aucun compte démo</strong>Lancez <code>php artisan db:seed --class=DemoAccountsSeeder</code>.</div>
        </div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import { errMsg, num } from '../utils/format'

const d = ref(null)
const error = ref('')
const space = ref('app')

const LINKS = {
  client: '/clients',
  merchant: '/merchants',
  cashier: '/cashiers',
  agent: '/agents?level=simple',
  sub_agent: '/agents?level=sub',
  super_agent: '/agents?level=super',
  support: '/users',
  super_admin: '/users',
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

const columns = computed(() => Object.keys(d.value?.roles || {}).filter((k) => d.value.roles[k].space === space.value))

/** 'yes' | texte de la restriction | null */
function grant(role, cap) {
  const g = d.value?.roles?.[role]?.grants?.[cap]
  if (!g) return null
  if (g === 'yes') return 'yes'
  if (Array.isArray(g)) return g[1] || 'Restreint'
  return String(g)
}

async function load() {
  error.value = ''
  try {
    const { data } = await api.get('/admin/roles')
    d.value = data
  } catch (e) {
    error.value = errMsg(e)
  }
}
onMounted(load)
</script>

<style scoped>
.matrix th.c, .matrix td.c { text-align: center; }
.matrix td:first-child { font-weight: 500; min-width: 260px; }
.g { display: inline-flex; width: 26px; height: 26px; border-radius: 50%; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; }
.g.yes { background: var(--ok-bg); color: var(--ok); }
.g.lim { background: var(--rose); color: var(--accent); cursor: help; }
.g.no { color: var(--text-3); }
</style>
