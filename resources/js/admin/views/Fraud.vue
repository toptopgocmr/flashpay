<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Anti-fraude</h1>
        <p>Alertes levées par les règles de vélocité et de seuil LCB-FT ; un compte en alerte est bloqué temporairement.</p>
      </div>
      <div class="actions">
        <ExportButton filename="alertes-fraude" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn-normal" @click="load(1)">Actualiser</button>
      </div>
    </div>

    <section class="container">
      <div class="tabs">
        <button v-for="t in ['open', 'cleared', 'confirmed']" :key="t" :class="{ on: status === t }" @click="status = t; load(1)">
          {{ LBL[t] }} <span class="n">{{ d?.counts?.[t] || 0 }}</span>
        </button>
      </div>
      <div class="container-body flush">
        <table>
          <thead><tr><th>Règle</th><th>Utilisateur</th><th>Score</th><th>Détails</th><th>Opération</th><th>Bloqué jusqu'au</th><th>Date</th><th></th></tr></thead>
          <tbody>
            <tr v-if="!d"><td colspan="8"><div class="skeleton" style="height:18px"></div></td></tr>
            <tr v-for="a in d?.data || []" :key="a.id">
              <td><strong>{{ RULE[a.rule] || a.rule }}</strong></td>
              <td>
                <div class="who"><span class="ava">{{ initials(a.user?.full_name) }}</span>
                  <div><strong>{{ a.user?.full_name || '—' }}</strong><small class="mono">{{ $phone(a.user?.phone) }}</small></div>
                </div>
              </td>
              <td>
                <div class="fr-score" :class="a.score >= 80 ? 'hi' : a.score >= 50 ? 'mid' : 'lo'">
                  <b>{{ a.score }}</b><span class="meter"><span :style="{ width: Math.min(100, a.score) + '%' }"></span></span>
                </div>
                <small class="muted">profil {{ a.user?.risk_score ?? '—' }}</small>
              </td>
              <td><DataChips :data="a.details" :max="5" /></td>
              <td>
                <router-link v-if="a.transaction" :to="'/transactions/' + a.transaction.id" class="mono">{{ a.transaction.reference }}</router-link>
                <small v-if="a.transaction" class="muted" style="display:block">{{ money(a.transaction.amount, a.transaction.currency) }}</small>
                <span v-else class="muted">—</span>
              </td>
              <td>
                <span v-if="a.user?.blocked_until && new Date(a.user.blocked_until) > new Date()" class="status err">{{ date(a.user.blocked_until) }}</span>
                <span v-else class="muted">—</span>
              </td>
              <td>{{ date(a.created_at) }}</td>
              <td class="actions-cell">
                <template v-if="a.status === 'open'">
                  <IconAction icon="unlock" tone="ok" label="Faux positif · débloquer le compte" @click="review(a, 'cleared', true)" />
                  <IconAction icon="alert" tone="danger" label="Confirmer la fraude" @click="review(a, 'confirmed', false)" />
                </template>
              </td>
            </tr>
            <tr v-if="d && !d.data.length">
              <td colspan="8"><div class="empty"><strong>{{ status === 'open' ? 'Aucune alerte ouverte' : 'Aucune alerte' }}</strong>{{ status === 'open' ? 'Tout est calme.' : '' }}</div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <Pager :meta="d" @go="load" />
    </section>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import DataChips from '../components/DataChips.vue'
import Pager from '../components/Pager.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import { date, money, errMsg } from '../utils/format'
import { promptBox, toast } from '../utils/ui'

const LBL = { open: 'Ouvertes', cleared: 'Levées', confirmed: 'Confirmées' }
const RULE = { velocity: 'Vélocité anormale', aml_threshold: 'Seuil LCB-FT dépassé' }
const d = ref(null)
const status = ref('open')
const initials = (s) => String(s || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('')

async function load(page = 1) {
  d.value = (await api.get('/admin/fraud-alerts', { params: { status: status.value, page } })).data
}
async function review(a, decision, unblock) {
  const cleared = decision === 'cleared'
  const note = await promptBox('Note de revue', {
    title: cleared ? 'Faux positif : débloquer le compte ?' : 'Confirmer la fraude ?',
    message: cleared ? `${a.user?.full_name || 'Le compte'} sera débloqué et son score de risque réduit.` : `${a.user?.full_name || 'Le compte'} reste bloqué ; l'alerte est classée « fraude confirmée ».`,
    placeholder: 'Facultatif : ce qui a été vérifié', confirmLabel: cleared ? 'Débloquer' : 'Confirmer la fraude', danger: !cleared,
  })
  if (note === null) return
  try {
    await api.post(`/admin/fraud-alerts/${a.id}`, { decision, unblock, note })
    toast(cleared ? 'Alerte levée, compte débloqué.' : 'Fraude confirmée.')
    load(d.value?.current_page || 1)
  } catch (e) { toast(errMsg(e), 'err') }
}
onMounted(() => load())

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Règle', value: (a) => RULE[a.rule] || a.rule },
  { label: 'Utilisateur', value: (a) => a.user?.full_name },
  { label: 'Téléphone', value: (a) => fmtPhone(a.user?.phone) },
  { label: 'Score', value: (a) => a.score },
  { label: 'Risque profil', value: (a) => a.user?.risk_score },
  { label: 'Statut', value: (a) => LBL[a.status] || a.status },
  { label: 'Opération', value: (a) => a.transaction?.reference },
  { label: 'Détails', value: (a) => JSON.stringify(a.details || {}) },
  { label: 'Date', value: (a) => fmtDate(a.created_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/fraud-alerts', { status: status.value }, onP)
</script>

<style scoped>
.fr-score { display: flex; align-items: center; gap: 8px; }
.fr-score b { min-width: 26px; font-variant-numeric: tabular-nums; }
.fr-score .meter { flex: 1; min-width: 60px; }
.fr-score.hi b { color: var(--err); } .fr-score.hi .meter > span { background: var(--err); }
.fr-score.mid b { color: var(--warn); } .fr-score.mid .meter > span { background: #f59e0b; }
.muted { color: var(--text-3); font-size: 12px; }
</style>
