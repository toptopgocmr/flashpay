<template>
  <div class="td">
    <div v-if="!t" class="td-loading">
      <p v-if="error" class="err">{{ error }}</p>
      <p v-else>Chargement de la transaction…</p>
    </div>

    <template v-else>
      <!-- En-tête : référence, statut, montant, actions -->
      <section class="td-hero" :class="'s-' + t.status">
        <div class="td-hero-main">
          <span class="td-ic" :class="'s-' + t.status">
            <svg v-if="t.status === 'successful'" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
            <svg v-else-if="t.status === 'failed'" viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17"/></svg>
            <svg v-else-if="t.status === 'reversed'" viewBox="0 0 24 24"><path d="M9 14L4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3"/></svg>
            <svg v-else viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/></svg>
          </span>
          <div class="td-id">
            <div class="td-ref">
              <b class="mono">{{ t.reference }}</b>
              <button class="td-copy" :title="copied ? 'Copié' : 'Copier la référence'" @click="copy(t.reference)">
                <svg v-if="!copied" viewBox="0 0 24 24"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/></svg>
                <svg v-else viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
              </button>
              <span class="pill" :class="t.status">{{ t.status_label || ST[t.status] || t.status }}</span>
            </div>
            <small>{{ t.type_label }}<template v-if="t.channel_label && t.channel_label !== t.type_label"> · {{ t.channel_label }}</template> · {{ dtLong(t.created_at) }}</small>
          </div>
          <div class="td-amount">
            <small>Montant</small>
            <b>{{ money(t.amount, t.currency) }}</b>
            <em v-if="t.destination_currency && t.destination_currency !== t.currency">≈ {{ money(t.destination_amount, t.destination_currency) }} reçus</em>
          </div>
        </div>

        <div class="td-actions">
          <button class="td-btn primary" title="Imprimer ou enregistrer en PDF" @click="openReceipt(t.id)">
            <svg viewBox="0 0 24 24"><path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2M6 14h12v7H6z"/></svg> Reçu
          </button>
          <button class="td-btn" title="Format imprimante thermique 58/80 mm" @click="openReceipt(t.id, { ticket: true })">
            <svg viewBox="0 0 24 24"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4"/></svg> Ticket
          </button>
          <button v-if="t.status === 'processing' || t.status === 'failed'" class="td-btn" :disabled="checking" @click="checkStatus">
            <svg viewBox="0 0 24 24" :class="{ spin: checking }"><path d="M20 12a8 8 0 0 1-14 5.3M4 12A8 8 0 0 1 18 6.7M18 3v4h-4M6 21v-4h4"/></svg> Vérifier le statut
          </button>
          <button class="td-btn" @click="showRefund = true">Rembourser via PEEX</button>
          <span class="grow"></span>
          <button class="td-btn ghost" title="Recharger" :disabled="loading" @click="load">
            <svg viewBox="0 0 24 24" :class="{ spin: loading }"><path d="M20 12a8 8 0 0 1-14 5.3M4 12A8 8 0 0 1 18 6.7M18 3v4h-4M6 21v-4h4"/></svg>
          </button>
          <router-link class="td-btn ghost" to="/transactions">‹ Transactions</router-link>
        </div>

        <p v-if="t.failure_reason" class="td-fail"><b>Motif :</b> {{ t.failure_reason }}</p>
        <p v-if="flash" class="td-flash" :class="flash.type">{{ flash.text }}</p>
      </section>

      <!-- Parties -->
      <div class="td-parties">
        <component :is="sender.userId ? 'router-link' : 'div'" class="td-party" :to="sender.userId ? { path: '/transactions', query: { user: sender.userId } } : undefined" :title="sender.userId ? 'Voir ses opérations' : ''">
          <span class="av out">{{ initials(sender.name) }}</span>
          <div>
            <small>Expéditeur</small>
            <b>{{ sender.name || '—' }}</b>
            <span class="sub">{{ phone(sender.account) || '—' }}</span>
            <span class="rail">{{ railLabel(t.gateway?.in, t.source_rail) }}</span>
          </div>
        </component>
        <div class="td-arrow">
          <svg viewBox="0 0 24 24"><path d="M4 12h16M14 6l6 6-6 6"/></svg>
        </div>
        <component :is="beneficiary.userId ? 'router-link' : 'div'" class="td-party" :to="beneficiary.userId ? { path: '/transactions', query: { user: beneficiary.userId } } : undefined" :title="beneficiary.userId ? 'Voir ses opérations' : ''">
          <span class="av in">{{ initials(beneficiary.name) }}</span>
          <div>
            <small>Bénéficiaire</small>
            <b>{{ beneficiary.name || '—' }}</b>
            <span class="sub">{{ phone(beneficiary.account) || '—' }}</span>
            <span class="rail">{{ railLabel(t.gateway?.out, t.destination_rail) }}</span>
          </div>
        </component>
      </div>

      <!-- Chiffres clés -->
      <div class="td-kpis">
        <div><small>Montant</small><b>{{ money(t.amount, t.currency) }}</b></div>
        <div><small>Frais client</small><b>{{ money((t.fee || 0) + (t.merchant_fee || 0), t.currency) }}</b></div>
        <div v-if="t.costs"><small>Frais partenaires</small><b class="neg">−{{ money(t.costs.partner_total || 0, t.currency) }}</b></div>
        <div v-if="t.costs"><small>Marge FlashPay</small><b :class="t.costs.margin < 0 ? 'neg' : 'pos'">{{ money(t.costs.margin, t.currency) }}</b></div>
        <div><small>Net bénéficiaire</small><b class="pos">{{ money(net, t.destination_currency || t.currency) }}</b></div>
      </div>

      <div class="td-grid">
        <!-- Colonne principale -->
        <div class="td-col">
          <section v-if="t.flow" class="td-box">
            <header><h3>Parcours des fonds</h3><small>Collecte → compte principal FlashPay → décaissement</small></header>
            <ol class="td-flow">
              <li v-for="(s, i) in t.flow" :key="s.step" :class="'st-' + (s.status || 'none')">
                <span class="dot">
                  <svg v-if="s.status === 'successful'" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
                  <svg v-else-if="s.status === 'failed'" viewBox="0 0 24 24"><path d="M7 7l10 10M17 7L7 17"/></svg>
                  <template v-else>{{ i + 1 }}</template>
                </span>
                <div>
                  <b>{{ s.label }}</b>
                  <span>{{ s.account }}</span>
                  <small>{{ s.partner }} · {{ ST[s.status] || '—' }}</small>
                </div>
              </li>
            </ol>
          </section>

          <section v-if="t.costs" class="td-box flush">
            <header><h3>Frais &amp; marge</h3></header>
            <table class="td-table">
              <thead><tr><th>Ligne</th><th>Partenaire</th><th>Référence</th><th>Statut</th><th class="num">Montant</th><th class="num">Frais</th></tr></thead>
              <tbody>
                <tr>
                  <td><b>Facturé par FlashPay</b><small>frais client{{ t.costs.merchant_fee ? ' + commission marchand' : '' }}</small></td>
                  <td>FlashPay</td><td class="mono">{{ t.reference }}</td><td><span class="pill sm" :class="t.status">{{ ST[t.status] || t.status }}</span></td><td></td>
                  <td class="num pos"><b>+{{ n(t.costs.billed) }} {{ t.costs.currency }}</b></td>
                </tr>
                <tr v-for="l in t.costs.legs" :key="l.kind + l.reference">
                  <td>{{ l.label }}</td><td>{{ l.partner }}</td><td class="mono">{{ l.reference || '—' }}</td>
                  <td><span class="pill sm" :class="pillOf(l.status)">{{ ST[l.status] || '—' }}</span></td>
                  <td class="num">{{ n(l.amount) }} {{ l.currency }}</td>
                  <td class="num neg">
                    <template v-if="l.fee !== null">−{{ n(l.fee) }} {{ l.currency }} <small v-if="l.fee_source === 'estimate'" title="Tarif saisi dans Transactions › Tarifs partenaires">estimé</small></template>
                    <small v-else class="unk">non communiqués</small>
                  </td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="5"><b>Marge FlashPay</b> <small>= facturé − frais partenaires</small></td>
                  <td class="num" :class="t.costs.margin < 0 ? 'neg' : 'pos'"><b>{{ n(t.costs.margin) }} {{ t.costs.currency }}</b></td>
                </tr>
              </tfoot>
            </table>
          </section>

          <section id="tx-ledger" class="td-box flush">
            <header>
              <h3>Écritures du grand livre</h3>
              <span class="bal" :class="balanced ? 'ok' : 'ko'">{{ balanced ? '✓ Équilibré' : '⚠ Déséquilibré' }} · {{ t.ledger_entries?.length || 0 }} écritures</span>
            </header>
            <table class="td-table">
              <thead><tr><th>Compte</th><th class="num">Débit</th><th class="num">Crédit</th></tr></thead>
              <tbody>
                <tr v-for="e in t.ledger_entries" :key="e.id">
                  <td><b>{{ accountLabel(e.account).name }}</b><small class="mono">{{ e.account }}</small></td>
                  <td class="num neg">{{ e.type === 'debit' ? money(e.amount, e.currency || t.currency) : '' }}</td>
                  <td class="num pos">{{ e.type === 'credit' ? money(e.amount, e.currency || t.currency) : '' }}</td>
                </tr>
                <tr v-if="!t.ledger_entries?.length"><td colspan="3" class="empty">Aucune écriture.</td></tr>
              </tbody>
              <tfoot v-if="t.ledger_entries?.length">
                <tr><td><b>Total</b></td><td class="num"><b>{{ money(totals.debit, t.currency) }}</b></td><td class="num"><b>{{ money(totals.credit, t.currency) }}</b></td></tr>
              </tfoot>
            </table>
          </section>
        </div>

        <!-- Colonne latérale -->
        <aside class="td-col">
          <section class="td-box">
            <header><h3>Informations</h3></header>
            <dl class="td-kv">
              <template v-for="r in infoRows" :key="r[0]">
                <dt>{{ r[0] }}</dt><dd :class="{ mono: r[2] }">{{ r[1] }}</dd>
              </template>
            </dl>
          </section>

          <section class="td-box">
            <header><h3>Notes support</h3><small>{{ t.notes?.length || 0 }}</small></header>
            <ul v-if="t.notes?.length" class="td-notes">
              <li v-for="nt in t.notes" :key="nt.id">
                <span class="av sm">{{ initials(nt.author?.full_name) }}</span>
                <div><b>{{ nt.author?.full_name || 'Équipe' }}</b> <small>{{ dtLong(nt.created_at) }}</small><p>{{ nt.note }}</p></div>
              </li>
            </ul>
            <p v-else class="muted">Aucune note pour le moment.</p>
            <form class="td-form" @submit.prevent="addNote">
              <textarea v-model="noteText" rows="2" placeholder="Ajouter une note interne…" @keydown.ctrl.enter="addNote" />
              <button class="td-btn primary" type="submit" :disabled="!noteText.trim() || saving">Ajouter</button>
            </form>
          </section>

          <section class="td-box">
            <header><h3>Escalader vers un partenaire</h3></header>
            <form class="td-form" @submit.prevent="escalate">
              <select v-model="escalatePartner">
                <option value="peex">PEEX</option>
              </select>
              <textarea v-model="escalateMessage" rows="2" placeholder="Décrivez le problème pour le partenaire…" />
              <button class="td-btn" type="submit" :disabled="!escalateMessage.trim() || saving">Escalader</button>
            </form>
          </section>
        </aside>
      </div>

      <PeexRefund v-if="showRefund" :transaction-id="t.id" @close="showRefund = false" @done="load" />
    </template>
  </div>
</template>

<script setup>
import { computed, ref, onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import PeexRefund from '../components/PeexRefund.vue'
import { openReceipt } from '../utils/receipt'

const route = useRoute()
const t = ref(null)
const error = ref('')
const loading = ref(false)
const saving = ref(false)
const checking = ref(false)
const showRefund = ref(false)
const copied = ref(false)
const flash = ref(null)
const noteText = ref('')
const escalatePartner = ref('peex')
const escalateMessage = ref('')

const ST = { successful: 'Réussi', pending: 'En cours', failed: 'Échoué', processing: 'En cours', reversed: 'Remboursé', paid: 'Réussi' }
const RAILS = { wallet: 'Wallet FlashPay', peex: 'Mobile money (PEEX)', digitwace: 'Mobile money (WacePay)', card: 'Carte bancaire', bank: 'Banque', cash: 'Espèces', atm: 'GAB', treasury: 'Trésorerie FlashPay' }
const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(v || 0)
const money = (v, c) => nf.format(v || 0) + ' ' + (c || 'XAF')
const dtLong = (d) => (d ? new Date(d).toLocaleString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—')
const phone = (p) => (p && /^\+?\d{8,}$/.test(String(p).replace(/\s/g, '')) ? '+' + String(p).replace(/\D/g, '') : p || '')
const initials = (name) => (name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0].toUpperCase()).join('') || '?'
const pillOf = (s) => (s === 'paid' ? 'successful' : s === 'pending' ? 'processing' : s || 'none')

function railLabel(side, rail) {
  if (side?.operator) return [side.operator, side.partner ? 'via ' + side.partner : null, side.country].filter(Boolean).join(' · ')
  return RAILS[rail] || rail || '—'
}

function notify(text, type = 'ok') {
  flash.value = { text, type }
  setTimeout(() => { if (flash.value?.text === text) flash.value = null }, 5000)
}

async function load() {
  loading.value = true
  try {
    const { data } = await api.get(`/support/transactions/${route.params.id}`)
    t.value = data
    error.value = ''
  } catch (e) {
    error.value = e.response?.status === 404 ? 'Transaction introuvable.' : (e.response?.data?.message || 'Impossible de charger la transaction.')
  } finally {
    loading.value = false
  }
}

async function copy(text) {
  try { await navigator.clipboard.writeText(text) } catch { /* navigateur sans presse-papiers */ }
  copied.value = true
  setTimeout(() => (copied.value = false), 1500)
}

async function addNote() {
  if (!noteText.value.trim() || saving.value) return
  saving.value = true
  try {
    await api.post(`/support/transactions/${route.params.id}/notes`, { note: noteText.value.trim() })
    noteText.value = ''
    await load()
  } catch (e) {
    notify(e.response?.data?.message || "La note n'a pas pu être ajoutée.", 'ko')
  } finally { saving.value = false }
}

async function escalate() {
  if (!escalateMessage.value.trim() || saving.value) return
  saving.value = true
  try {
    await api.post(`/support/transactions/${route.params.id}/escalate`, { partner: escalatePartner.value, message: escalateMessage.value.trim() })
    escalateMessage.value = ''
    notify('Incident escaladé au partenaire.')
    await load()
  } catch (e) {
    notify(e.response?.data?.message || "L'escalade a échoué.", 'ko')
  } finally { saving.value = false }
}

async function checkStatus() {
  checking.value = true
  try {
    const { data } = await api.post(`/admin/transactions/${t.value.id}/check-status`)
    const before = t.value.status
    await load()
    notify(data.status && data.status !== before ? `Statut mis à jour : ${ST[data.status] || data.status}.` : 'Statut confirmé auprès des passerelles, aucun changement.')
  } catch (e) {
    notify(e.response?.data?.message || 'Vérification impossible.', 'ko')
  } finally { checking.value = false }
}

onMounted(load)
watch(() => route.params.id, (id) => { if (id) { t.value = null; load() } })

// Expéditeur / bénéficiaire lisibles (compte FlashPay, sinon informations saisies sur l'opération)
const sender = computed(() => {
  const x = t.value || {}, m = x.meta || {}, u = x.source_wallet?.user, p = x.parties || {}
  return { userId: u?.id, name: u?.full_name || m.sender_name || m.payer_name || p.sender_name || (x.source_rail === 'treasury' ? 'FlashPay (trésorerie)' : null), account: x.source_account || u?.phone || p.sender_phone }
})
const beneficiary = computed(() => {
  const x = t.value || {}, m = x.meta || {}, u = x.destination_wallet?.user, p = x.parties || {}
  return { userId: u?.id, name: m.merchant_name || u?.full_name || m.beneficiary_name || m.client_name || p.beneficiary_name || (x.destination_rail === 'treasury' ? 'FlashPay (trésorerie)' : null), account: x.destination_account || u?.phone || p.beneficiary_phone || m.bank_name }
})
const net = computed(() => Math.max(0, (t.value?.destination_amount ?? t.value?.amount ?? 0) - (t.value?.merchant_fee || 0)))

// Grand livre : libellés lisibles et contrôle débit = crédit
function accountLabel(acc) {
  const [kind, rest] = String(acc).split(/:(.*)/s)
  const x = t.value || {}
  if (kind === 'wallet') {
    const id = Number(rest)
    if (id === x.source_wallet_id) return { name: `Wallet de ${sender.value.name || 'l\'expéditeur'}` }
    if (id === x.destination_wallet_id) return { name: `Wallet de ${beneficiary.value.name || 'du bénéficiaire'}` }
    return { name: `Wallet n° ${rest}` }
  }
  const FP = { suspense: "Compte d'attente FlashPay", fees: 'Revenus de frais FlashPay', treasury: 'Trésorerie FlashPay', commissions: 'Commissions agents' }
  if (kind === 'flashpay') return { name: FP[rest] || 'FlashPay · ' + rest }
  if (kind === 'digitwace') return { name: 'WacePay · ' + phone(rest) }
  if (kind === 'peex') return { name: 'PEEX · ' + phone(rest) }
  return { name: acc }
}
const totals = computed(() => (t.value?.ledger_entries || []).reduce((a, e) => { a[e.type] = (a[e.type] || 0) + Number(e.amount || 0); return a }, { debit: 0, credit: 0 }))
const balanced = computed(() => totals.value.debit === totals.value.credit)

const infoRows = computed(() => {
  const x = t.value || {}, m = x.meta || {}
  const dur = x.completed_at && x.created_at ? Math.round((new Date(x.completed_at) - new Date(x.created_at)) / 1000) : null
  const durTxt = dur === null ? null : dur < 60 ? `${dur} s` : dur < 3600 ? `${Math.round(dur / 60)} min` : `${(dur / 3600).toFixed(1)} h`
  return [
    ['ID interne', '#' + x.id],
    ['Type', x.type_label || x.type],
    ['Canal', x.channel_label],
    ['Étape', x.stage],
    ['Créée le', dtLong(x.created_at)],
    ['Finalisée le', x.completed_at ? dtLong(x.completed_at) : null],
    ['Durée', durTxt],
    ['Réf. source', x.source_external_ref, true],
    ['Réf. destination', x.destination_external_ref, true],
    ['Track ID PEEX', (x.gateway?.track_ids || []).join(' ') || null, true],
    ['Initiée par', x.initiator?.full_name],
    ['Motif', m.note || m.purpose_label || m.description],
  ].filter((r) => r[1] !== null && r[1] !== undefined && r[1] !== '')
})
</script>

<style scoped>
.td { --line: #eceef2; --soft: #f7f8fa; --t2: #6b7280; --t3: #9ca3af; --navy: #1e3a8a; color: #111827; font-size: 13px; }
.td-loading { padding: 60px; text-align: center; color: var(--t2); } .td-loading .err { color: #b91c1c; }
.mono { font-family: ui-monospace, Menlo, Consolas, monospace; }
.muted { color: var(--t3); margin: 4px 0 10px; }
.pos { color: #16a34a; } .neg { color: #dc2626; } .unk { color: #b45309; }

/* En-tête */
.td-hero { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px; margin-bottom: 12px; border-top: 4px solid #2563eb; }
.td-hero.s-successful { border-top-color: #16a34a; } .td-hero.s-failed { border-top-color: #dc2626; } .td-hero.s-reversed { border-top-color: #6366f1; } .td-hero.s-processing { border-top-color: #ca8a04; }
.td-hero-main { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.td-ic { flex: none; width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; background: #fef9c3; color: #ca8a04; }
.td-ic.s-successful { background: #dcfce7; color: #16a34a; } .td-ic.s-failed { background: #fee2e2; color: #dc2626; } .td-ic.s-reversed { background: #e0e7ff; color: #4f46e5; }
.td-ic svg { width: 24px; height: 24px; fill: none; stroke: currentColor; stroke-width: 2.4; stroke-linecap: round; stroke-linejoin: round; }
.td-id { flex: 1; min-width: 240px; }
.td-ref { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.td-ref b { font-size: 20px; letter-spacing: .3px; }
.td-id small { display: block; color: var(--t2); margin-top: 3px; font-size: 12.5px; }
.td-copy { border: 0; background: var(--soft); border-radius: 6px; padding: 4px; cursor: pointer; line-height: 0; color: var(--t2); }
.td-copy:hover { color: #2563eb; } .td-copy svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; }
.td-amount { text-align: right; }
.td-amount small { display: block; color: var(--t2); font-size: 11.5px; }
.td-amount b { font-size: 28px; font-weight: 800; letter-spacing: -.5px; }
.td-amount em { display: block; font-style: normal; color: var(--t2); font-size: 12px; }
.td-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--line); }
.td-actions .grow { flex: 1; }
.td-btn { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px; border: 1px solid #dfe3e8; border-radius: 8px; background: #fff; font: inherit; font-weight: 600; font-size: 12.5px; color: #374151; cursor: pointer; text-decoration: none; white-space: nowrap; }
.td-btn:hover:not(:disabled) { background: var(--soft); }
.td-btn:disabled { opacity: .5; cursor: default; }
.td-btn.primary { background: var(--navy); border-color: var(--navy); color: #fff; } .td-btn.primary:hover:not(:disabled) { background: #172e6e; }
.td-btn svg { width: 15px; height: 15px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.spin { animation: td-spin .8s linear infinite; } @keyframes td-spin { to { transform: rotate(360deg); } }
.td-fail { margin: 12px 0 0; padding: 9px 12px; border-radius: 8px; background: #fef2f2; color: #991b1b; }
.td-flash { margin: 10px 0 0; padding: 8px 12px; border-radius: 8px; background: #f0fdf4; color: #166534; font-weight: 600; } .td-flash.ko { background: #fef2f2; color: #991b1b; }

/* Parties */
.td-parties { display: grid; grid-template-columns: 1fr auto 1fr; gap: 10px; align-items: stretch; margin-bottom: 12px; }
.td-party { display: flex; gap: 12px; align-items: flex-start; background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; color: inherit; text-decoration: none; min-width: 0; transition: border-color .15s; }
a.td-party:hover { border-color: #c7d2fe; }
.td-party > div { min-width: 0; }
.td-party small { display: block; color: var(--t2); font-size: 11px; text-transform: uppercase; letter-spacing: .4px; font-weight: 600; }
.td-party b { display: block; font-size: 15.5px; margin: 2px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.td-party .sub { display: block; font-family: ui-monospace, Menlo, Consolas, monospace; color: var(--t2); font-size: 12px; }
.td-party .rail { display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 5px; background: #eff6ff; color: #1e40af; font-size: 11px; font-weight: 600; }
.av { flex: none; width: 40px; height: 40px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 14px; background: #fee2e2; color: #b91c1c; }
.av.in { background: #dbeafe; color: #1e40af; } .av.sm { width: 28px; height: 28px; font-size: 11px; background: #eef2ff; color: #3730a3; }
.td-arrow { display: grid; place-items: center; color: var(--t3); }
.td-arrow svg { width: 26px; height: 26px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

/* Chiffres clés */
.td-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1px; background: var(--line); border: 1px solid var(--line); border-radius: 12px; overflow: hidden; margin-bottom: 12px; }
.td-kpis > div { background: #fff; padding: 10px 14px; }
.td-kpis small { display: block; color: var(--t2); font-size: 11.5px; } .td-kpis b { font-size: 16px; font-weight: 700; }

/* Grille */
.td-grid { display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr); gap: 12px; align-items: start; }
.td-col { display: flex; flex-direction: column; gap: 12px; min-width: 0; }
.td-box { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px; }
.td-box.flush { padding: 0; overflow: hidden; }
.td-box header { display: flex; align-items: baseline; gap: 10px; justify-content: space-between; margin-bottom: 10px; }
.td-box.flush header { padding: 14px 16px 0; }
.td-box h3 { margin: 0; font-size: 14px; font-weight: 700; } .td-box header small { color: var(--t2); font-size: 12px; }
.bal { font-size: 11.5px; font-weight: 600; } .bal.ok { color: #16a34a; } .bal.ko { color: #dc2626; }

/* Parcours */
.td-flow { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; counter-reset: s; }
.td-flow li { position: relative; display: flex; gap: 10px; padding: 10px 12px; border-radius: 10px; background: var(--soft); }
.td-flow .dot { flex: none; width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; background: #e5e7eb; color: #4b5563; font-weight: 700; font-size: 12px; }
.td-flow .dot svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 3; stroke-linecap: round; stroke-linejoin: round; }
.td-flow .st-successful .dot { background: #16a34a; color: #fff; } .td-flow .st-failed .dot { background: #dc2626; color: #fff; } .td-flow .st-pending .dot, .td-flow .st-processing .dot { background: #ca8a04; color: #fff; }
.td-flow b { display: block; font-size: 12.5px; } .td-flow span { display: block; font-size: 12.5px; margin: 2px 0; word-break: break-word; } .td-flow small { color: var(--t2); font-size: 11.5px; }

/* Tableaux */
.td-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.td-table th { text-align: left; background: var(--soft); color: var(--t2); font-size: 10.5px; text-transform: uppercase; letter-spacing: .4px; font-weight: 600; padding: 8px 16px; border-bottom: 1px solid var(--line); }
.td-table td { padding: 8px 16px; border-bottom: 1px solid var(--line); vertical-align: middle; }
.td-table td small { display: block; color: var(--t3); font-size: 11px; }
.td-table td.num small { display: inline; }
.td-table tfoot td { background: var(--soft); border-bottom: 0; }
.td-table .num { text-align: right; white-space: nowrap; }
.td-table .empty { text-align: center; color: var(--t2); padding: 18px; }
.pill { display: inline-block; padding: 2px 8px; border-radius: 5px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; background: #f3f4f6; color: #4b5563; }
.pill.sm { padding: 1px 6px; font-size: 9.5px; }
.pill.successful { background: #dcfce7; color: #15803d; } .pill.processing { background: #fef9c3; color: #a16207; }
.pill.failed { background: #fee2e2; color: #b91c1c; } .pill.reversed { background: #e0e7ff; color: #3730a3; }

/* Infos */
.td-kv { display: grid; grid-template-columns: auto 1fr; gap: 7px 12px; margin: 0; }
.td-kv dt { color: var(--t2); } .td-kv dd { margin: 0; text-align: right; font-weight: 600; word-break: break-word; } .td-kv dd.mono { font-size: 12px; }

/* Notes */
.td-notes { list-style: none; margin: 0 0 10px; padding: 0; display: flex; flex-direction: column; gap: 10px; max-height: 320px; overflow: auto; }
.td-notes li { display: flex; gap: 8px; } .td-notes small { color: var(--t3); font-size: 11px; } .td-notes p { margin: 3px 0 0; white-space: pre-wrap; background: var(--soft); padding: 6px 10px; border-radius: 0 10px 10px 10px; }
.td-form { display: flex; flex-direction: column; gap: 6px; }
.td-form textarea, .td-form select { font: inherit; font-size: 13px; padding: 8px 10px; border: 1px solid #dfe3e8; border-radius: 8px; resize: vertical; background: #fff; }
.td-form button { align-self: flex-end; }

@media (max-width: 1000px) { .td-grid { grid-template-columns: 1fr; } }
@media (max-width: 700px) {
  .td-parties { grid-template-columns: 1fr; } .td-arrow { transform: rotate(90deg); }
  .td-amount { text-align: left; width: 100%; }
}
</style>
