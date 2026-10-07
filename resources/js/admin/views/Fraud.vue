<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Anti-fraude</h1>
        <p>8 règles évaluées avant chaque opération sortante : alerte à vérifier ou blocage temporaire du compte. Seuils réglables dans l'onglet « Règles ».</p>
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
        <button :class="{ on: status === 'rules' }" @click="status = 'rules'; loadRules()">Règles <span class="n">{{ activeRules }}</span></button>
      </div>

      <!-- Réglages des règles -->
      <div v-if="status === 'rules'" class="container-body">
        <div v-if="!cfg" class="skeleton" style="height:120px"></div>
        <template v-else>
          <div class="fr-global">
            <label>Durée du blocage temporaire
              <span class="fr-in"><input type="number" min="5" v-model.number="cfg.block_minutes" /> min</span>
            </label>
            <small class="muted">Appliquée aux règles en mode « Bloquer » : l'opération est refusée et le compte suspendu pendant cette durée.</small>
          </div>
          <div class="fr-rules">
            <div v-for="(r, key) in cfg.rules" :key="key" class="fr-rule" :class="{ off: !r.enabled }">
              <div class="fr-head">
                <label class="switch"><input type="checkbox" v-model="r.enabled" /><span></span></label>
                <div class="fr-title"><strong>{{ RULE[key] || key }}</strong><small class="muted">{{ HELP[key] }}</small></div>
                <select v-model="r.action" :disabled="!r.enabled">
                  <option value="alert">Alerter</option>
                  <option value="block">Bloquer</option>
                </select>
              </div>
              <div class="fr-fields">
                <label v-for="f in fieldsOf(r)" :key="f">{{ FIELD[f] || f }}
                  <span class="fr-in"><input type="number" min="1" v-model.number="r[f]" :disabled="!r.enabled" /> {{ UNIT[f] || '' }}</span>
                </label>
                <label>Score
                  <span class="fr-in"><input type="number" min="0" max="100" v-model.number="r.score" :disabled="!r.enabled" /> /100</span>
                </label>
              </div>
            </div>
          </div>
          <div class="fr-save">
            <button class="btn-normal" @click="resetRules">Valeurs par défaut</button>
            <button class="btn" :disabled="saving" @click="saveRules">{{ saving ? 'Enregistrement…' : 'Enregistrer les règles' }}</button>
          </div>
        </template>
      </div>

      <div v-else class="container-body flush">
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
      <Pager v-if="status !== 'rules'" :meta="d" @go="load" />
    </section>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import DataChips from '../components/DataChips.vue'
import Pager from '../components/Pager.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import { date, money, errMsg } from '../utils/format'
import { confirmBox, promptBox, toast } from '../utils/ui'

const LBL = { open: 'Ouvertes', cleared: 'Levées', confirmed: 'Confirmées' }
const RULE = {
  velocity: 'Vélocité anormale', aml_threshold: 'Seuil LCB-FT dépassé', structuring: 'Fractionnement sous le seuil LCB-FT',
  new_beneficiaries: 'Trop de nouveaux bénéficiaires', new_device_large: 'Nouvel appareil + gros montant',
  pin_failures: 'Échecs de PIN puis opération', new_account_large: 'Compte récent + gros montant', card_mismatch: 'Carte non liée utilisée',
}
const HELP = {
  velocity: 'Un client lance trop d\'opérations en peu de temps.',
  aml_threshold: 'Opération unique au-dessus du seuil de déclaration (LCB-FT).',
  structuring: 'Plusieurs opérations « juste sous le seuil » dont le cumul le dépasse.',
  new_beneficiaries: 'Envois vers beaucoup de numéros différents en peu de temps.',
  new_device_large: 'Gros montant juste après la connexion d\'un nouvel appareil.',
  pin_failures: 'Opération après plusieurs codes PIN erronés.',
  new_account_large: 'Gros montant envoyé par un compte créé récemment.',
  card_mismatch: 'Recharge payée avec une autre carte que la carte liée au profil.',
}
const FIELD = {
  window_minutes: 'Fenêtre', max_operations: 'Opérations max', amount: 'Montant à partir de', hours: 'Période',
  min_operations: 'Opérations min', near_percent: '« Proche du seuil » dès', max_beneficiaries: 'Bénéficiaires max',
  device_minutes: 'Appareil connecté depuis moins de', failures: 'PIN erronés', account_days: 'Compte créé depuis moins de',
}
const UNIT = { window_minutes: 'min', amount: 'XAF', hours: 'h', near_percent: '% du seuil', device_minutes: 'min', account_days: 'jours' }
const fieldsOf = (r) => Object.keys(r).filter((k) => !['enabled', 'action', 'score'].includes(k))
const cfg = ref(null)
const defaults = ref(null)
const saving = ref(false)
const activeRules = computed(() => cfg.value ? Object.values(cfg.value.rules).filter((r) => r.enabled).length : 8)
async function loadRules() {
  try {
    const r = (await api.get('/admin/fraud-settings')).data
    cfg.value = JSON.parse(JSON.stringify(r.config)); defaults.value = r.defaults
  } catch (e) { toast(errMsg(e), 'err') }
}
async function saveRules() {
  saving.value = true
  try {
    cfg.value = JSON.parse(JSON.stringify((await api.put('/admin/fraud-settings', cfg.value)).data.config))
    toast('Règles anti-fraude enregistrées.')
  } catch (e) { toast(errMsg(e), 'err') } finally { saving.value = false }
}
async function resetRules() {
  if (!defaults.value || !(await confirmBox('Remettre toutes les règles à leurs valeurs par défaut ? (à enregistrer ensuite)'))) return
  cfg.value = JSON.parse(JSON.stringify(defaults.value))
}
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
onMounted(() => { load(); loadRules() })

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
.fr-global { display: flex; flex-direction: column; gap: 4px; margin-bottom: 16px; }
.fr-global label { display: flex; align-items: center; gap: 10px; font-weight: 600; }
.fr-rules { display: grid; grid-template-columns: repeat(auto-fill, minmax(420px, 1fr)); gap: 12px; }
.fr-rule { border: 1px solid var(--border, #e5e7eb); border-radius: 12px; padding: 14px; background: #fff; }
.fr-rule.off { opacity: .6; }
.fr-head { display: flex; align-items: flex-start; gap: 12px; }
.fr-title { flex: 1; display: flex; flex-direction: column; gap: 2px; }
.fr-head select { width: auto; }
.fr-fields { display: flex; flex-wrap: wrap; gap: 10px 18px; margin-top: 12px; padding-left: 48px; }
.fr-fields label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--text-2, #475569); }
.fr-in { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-3); }
.fr-in input { width: 110px; }
.fr-save { display: flex; justify-content: flex-end; gap: 10px; margin-top: 16px; }
@media (max-width: 640px) { .fr-rules { grid-template-columns: 1fr; } .fr-fields { padding-left: 0; } }
</style>
