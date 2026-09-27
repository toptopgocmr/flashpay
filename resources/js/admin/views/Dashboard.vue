<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Tableau de bord</h1>
        <p>Activité de la plateforme FlashPay — du {{ fmtDate(d?.period.from) }} au {{ fmtDate(d?.period.to) }}</p>
      </div>
      <div class="actions">
        <select v-model="days" @change="onPeriod" aria-label="Période">
          <option :value="1">Aujourd'hui</option>
          <option :value="7">7 derniers jours</option>
          <option :value="14">14 derniers jours</option>
          <option :value="30">30 derniers jours</option>
          <option :value="90">90 derniers jours</option>
          <option value="custom">Période personnalisée…</option>
        </select>
        <label v-if="days === 'custom'" class="date-range">
          <span>Du</span>
          <input v-model="from" type="date" :max="to || today" @change="load" aria-label="Date de début" />
          <span>au</span>
          <input v-model="to" type="date" :min="from" :max="today" @change="load" aria-label="Date de fin" />
        </label>
        <button class="btn-normal" @click="load" :disabled="loading">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" :class="{ rot: loading }"><path d="M14 8a6 6 0 1 1-2-4.5M14 2v4h-4"/></svg>
          Actualiser
        </button>
        <router-link class="btn accent" to="/peex">Tester un paiement</router-link>
      </div>
    </div>

    <div v-if="error" class="flash err"><div><strong>Impossible de charger le tableau de bord.</strong><br />{{ error }}</div></div>

    <div v-if="d && (d.pending.merchants || d.pending.agents)" class="flash info">
      <div>
        <strong>Validations en attente :</strong>
        <router-link v-if="d.pending.merchants" to="/merchants"> {{ d.pending.merchants }} marchand(s)</router-link>
        <span v-if="d.pending.merchants && d.pending.agents"> · </span>
        <router-link v-if="d.pending.agents" to="/agents">{{ d.pending.agents }} agent(s)</router-link>
      </div>
    </div>

    <!-- ===== Réseau par rôle (maquettes v2) ===== -->
    <section v-if="net" class="container mb">
      <div class="container-head">
        <div><h3>Réseau par rôle</h3><p>Comptes des six profils de l'application mobile</p></div>
        <router-link to="/roles" class="btn-link">Rôles & habilitations →</router-link>
      </div>
      <div class="container-body">
        <div class="role-tiles">
          <router-link v-for="r in NET_ROLES" :key="r.key" :to="r.to" class="role-tile">
            <span class="pastille" :class="{ blue: r.blue }">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" v-html="r.icon"></svg>
            </span>
            <span class="l">{{ r.label }}</span>
            <span class="n">{{ n(net.counts?.[r.key]?.total) }}</span>
            <small>{{ n(net.counts?.[r.key]?.active) }} actif(s)</small>
          </router-link>
        </div>
      </div>
    </section>

    <!-- ===== Vue d'ensemble ===== -->
    <section class="container mb">
      <div class="container-head">
        <div><h3>Vue d'ensemble</h3><p>Toutes opérations confondues, sur la période sélectionnée</p></div>
        <span class="status" :class="d ? 'ok' : 'pending'">{{ d ? 'À jour ' + updatedAt : 'Chargement' }}</span>
      </div>
      <div class="container-body">
        <div class="tiles">
          <router-link class="tile" :to="tx()">
            <div class="k">Nombre total</div><div class="v" :class="{ skeleton: !d }">{{ n(k?.count) }}</div><div class="sub">Aujourd'hui : {{ n(k?.today_transactions) }}</div>
          </router-link>
          <router-link class="tile" :to="tx()">
            <div class="k">Volume</div><div class="v" :class="{ skeleton: !d }">{{ short(k?.volume) }} <small>XAF</small></div><div class="sub">dont réussi : {{ xaf(k?.volume_successful) }}</div>
          </router-link>
          <router-link v-for="s in STATUS_TILES" :key="s.key" class="tile" :class="'st-' + s.cls" :to="tx(null, s.key)">
            <div class="k"><span><i class="dot"></i>{{ s.label }}</span></div>
            <div class="v" :class="{ skeleton: !d }">{{ n(k?.[s.key]) }}</div>
            <div class="sub">{{ pct0(k?.[s.key], k?.count) }} du total</div>
          </router-link>
        </div>
        <div class="mini-kpis">
          <router-link to="/tariffs"><span>Frais perçus</span><b>{{ xaf(k?.fees) }}</b></router-link>
          <router-link :to="tx(null, 'failed')"><span>Taux de réussite</span><b>{{ k?.success_rate == null ? '—' : String(k.success_rate).replace('.', ',') + ' %' }}</b></router-link>
          <router-link to="/accounts"><span>Solde des wallets</span><b>{{ xaf(k?.wallets_balance) }}</b></router-link>
          <router-link to="/accounts"><span>Comptes</span><b>{{ n(d?.users.active) }} actifs</b><em v-if="d?.users.inactive" class="bad">· {{ n(d.users.inactive) }} désactivé(s)</em></router-link>
        </div>
      </div>
    </section>

    <!-- ===== Retraits · Recharges · Paiements ===== -->
    <div class="fam-grid mb">
      <section v-for="f in families" :key="f.key" class="container fam-card" :class="'fam-' + f.key">
        <div class="container-head">
          <div>
            <h3>{{ f.label }}</h3>
            <p>{{ n(f.count) }} opération(s) · {{ xaf(f.volume) }}</p>
          </div>
          <router-link class="btn-link" :to="tx(f.key)">Voir ›</router-link>
        </div>
        <div class="container-body">
          <div class="stack" :title="stackTitle(f)">
            <span v-for="s in STATUS_TILES" :key="s.key" :class="'s-' + s.cls" :style="{ width: (f.count ? f[s.key] * 100 / f.count : 0) + '%' }"></span>
          </div>
          <div class="st-row">
            <router-link v-for="s in STATUS_TILES" :key="s.key" :to="tx(f.key, s.key)" class="st" :class="'st-' + s.cls">
              <i class="dot"></i><b>{{ n(f[s.key]) }}</b><span>{{ s.label }}</span>
            </router-link>
          </div>
          <div class="ch-list">
            <router-link v-for="c in f.channels" :key="c.key" :to="tx(c.key)" class="ch">
              <span class="ch-label">{{ c.label }}</span>
              <span class="ch-num"><b>{{ n(c.count) }}</b> · {{ xaf(c.volume) }}</span>
            </router-link>
          </div>
        </div>
      </section>
    </div>

    <!-- ===== Indicateurs de performance ===== -->
    <div class="perf-head">
      <h2>Indicateurs de performance</h2>
      <span v-if="perf">Comparaison avec la période précédente : du {{ fmtDate(perf.previous.from) }} au {{ fmtDate(perf.previous.to) }}</span>
    </div>
    <div class="perf-grid mb">
      <section class="container">
        <div class="container-head"><h3>Période vs précédente</h3></div>
        <div class="container-body flush">
          <table class="mini">
            <thead><tr><th>Indicateur</th><th class="num">Période</th><th class="num">Précédente</th><th class="num">Évol.</th></tr></thead>
            <tbody>
              <tr v-for="r in KPI_ROWS" :key="r.key">
                <td>{{ r.label }}</td>
                <td class="num"><b>{{ r.fmt(perf?.current[r.key]) }}</b></td>
                <td class="num muted">{{ r.fmt(perf?.prev[r.key]) }}</td>
                <td class="num"><span class="evo" :class="evoClass(perf?.current[r.key], perf?.prev[r.key], r.lowerIsBetter, r.points)">{{ evo(perf?.current[r.key], perf?.prev[r.key], r.points) }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="container">
        <div class="container-head"><h3>Performance par activité</h3></div>
        <div class="container-body flush">
          <table class="mini">
            <thead><tr><th>Activité</th><th class="num">Panier moyen</th><th style="width: 30%;">Réussite</th><th class="num">Volume</th><th class="num">Évol.</th></tr></thead>
            <tbody>
              <tr v-for="r in perf?.by_family || []" :key="r.key">
                <td><router-link :to="tx(r.key)">{{ r.label }}</router-link></td>
                <td class="num">{{ r.avg_ticket == null ? '—' : short(r.avg_ticket) }}</td>
                <td><div class="rate"><div class="meter"><span :class="rateClass(r.success_rate)" :style="{ width: (r.success_rate || 0) + '%' }"></span></div><b :class="'t-' + rateClass(r.success_rate)">{{ pctTxt(r.success_rate) }}</b></div></td>
                <td class="num">{{ short(r.volume) }}</td>
                <td class="num"><span class="evo" :class="evoClass(r.volume, r.prev_volume)">{{ evo(r.volume, r.prev_volume) }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="container">
        <div class="container-head"><h3>Top 5 marchands</h3><router-link class="btn-link" to="/merchants">Tous ›</router-link></div>
        <div class="container-body flush">
          <table class="mini">
            <thead><tr><th>#</th><th>Marchand</th><th class="num">Paiements</th><th class="num">Volume</th><th class="num">Réussite</th></tr></thead>
            <tbody>
              <tr v-for="(m, i) in perf?.top_merchants || []" :key="m.id">
                <td class="rank">{{ i + 1 }}</td><td>{{ m.name }}</td><td class="num">{{ n(m.count) }}</td><td class="num"><b>{{ short(m.volume) }}</b></td>
                <td class="num"><span :class="'t-' + rateClass(m.success_rate)">{{ pctTxt(m.success_rate) }}</span></td>
              </tr>
              <tr v-if="perf && !perf.top_merchants.length"><td colspan="5" class="stat-label">Aucun paiement marchand sur la période.</td></tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="container">
        <div class="container-head"><h3>Top 5 agents</h3><router-link class="btn-link" to="/agents">Tous ›</router-link></div>
        <div class="container-body flush">
          <table class="mini">
            <thead><tr><th>#</th><th>Agent</th><th class="num">Dépôts</th><th class="num" title="Retraits au comptoir (cash-out wallet)">Retraits wallet</th><th class="num" title="Retraits par QR code / bon de retrait">Retraits QR code</th><th class="num">Volume</th></tr></thead>
            <tbody>
              <tr v-for="(a, i) in perf?.top_agents || []" :key="a.id">
                <td class="rank">{{ i + 1 }}</td><td>{{ a.name }}<div v-if="a.zone" class="hint">{{ a.zone }}</div></td>
                <td class="num">{{ n(a.deposits) }}</td><td class="num">{{ n(a.withdrawals) }}</td><td class="num">{{ n(a.qr_withdrawals) }}</td><td class="num"><b>{{ short(a.volume) }}</b></td>
              </tr>
              <tr v-if="perf && !perf.top_agents.length"><td colspan="6" class="stat-label">Aucune opération agent sur la période.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </div>

    <!-- ===== Détail par opération ===== -->
    <section class="container mb">
      <div class="container-head">
        <div><h3>Détail par opération</h3><p>Cliquez sur un chiffre pour ouvrir les transactions correspondantes</p></div>
      </div>
      <div class="container-body flush" style="overflow-x: auto;">
        <table class="matrix">
          <thead>
            <tr>
              <th>Opération</th><th class="num">Nombre</th><th class="num">Volume (XAF)</th>
              <th class="num">Réussies</th><th class="num">Échouées</th><th class="num">Rejetées</th><th class="num">En attente</th><th class="num">Taux</th>
            </tr>
          </thead>
          <tbody v-for="f in families" :key="f.key">
            <tr class="fam-row">
              <td><router-link :to="tx(f.key)">{{ f.label }}</router-link></td>
              <td class="num"><router-link :to="tx(f.key)">{{ n(f.count) }}</router-link></td>
              <td class="num">{{ n(f.volume) }}</td>
              <td class="num c-ok"><router-link :to="tx(f.key, 'successful')">{{ n(f.successful) }}</router-link></td>
              <td class="num c-err"><router-link :to="tx(f.key, 'failed')">{{ n(f.failed) }}</router-link></td>
              <td class="num c-warn"><router-link :to="tx(f.key, 'reversed')">{{ n(f.reversed) }}</router-link></td>
              <td class="num c-pend"><router-link :to="tx(f.key, 'processing')">{{ n(f.processing) }}</router-link></td>
              <td class="num">{{ rate(f) }}</td>
            </tr>
            <tr v-for="c in f.channels" :key="c.key" class="ch-row" :class="{ zero: !c.count }">
              <td><router-link :to="tx(c.key)">{{ c.label }}</router-link></td>
              <td class="num"><router-link :to="tx(c.key)">{{ n(c.count) }}</router-link></td>
              <td class="num">{{ n(c.volume) }}</td>
              <td class="num c-ok"><router-link :to="tx(c.key, 'successful')">{{ n(c.successful) }}</router-link></td>
              <td class="num c-err"><router-link :to="tx(c.key, 'failed')">{{ n(c.failed) }}</router-link></td>
              <td class="num c-warn"><router-link :to="tx(c.key, 'reversed')">{{ n(c.reversed) }}</router-link></td>
              <td class="num c-pend"><router-link :to="tx(c.key, 'processing')">{{ n(c.processing) }}</router-link></td>
              <td class="num">{{ rate(c) }}</td>
            </tr>
          </tbody>
          <tfoot v-if="d">
            <tr class="fam-row">
              <td>Total</td><td class="num">{{ n(k.count) }}</td><td class="num">{{ n(k.volume) }}</td>
              <td class="num c-ok">{{ n(k.successful) }}</td><td class="num c-err">{{ n(k.failed) }}</td>
              <td class="num c-warn">{{ n(k.reversed) }}</td><td class="num c-pend">{{ n(k.processing) }}</td><td class="num">{{ rate(k) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <!-- ===== Activité + État des services ===== -->
    <div class="grid grid-2-1 mb">
      <section class="container">
        <div class="container-head">
          <div><h3>Activité</h3><p>Volume réussi (barres) et nombre de transactions (courbe) par jour</p></div>
        </div>
        <div class="container-body" style="height: 320px;">
          <Bar v-if="chartData" :data="chartData" :options="chartOptions" />
          <div v-else class="skeleton" style="height: 100%;"></div>
        </div>
      </section>

      <section class="container">
        <div class="container-head"><h3>État des services</h3></div>
        <div class="container-body flush">
          <table>
            <tbody>
              <tr><td>API FlashPay</td><td><span class="status" :class="error ? 'err' : 'ok'">{{ error ? 'Indisponible' : 'Opérationnelle' }}</span></td></tr>
              <tr><td>PEEX ({{ d?.peex.sandbox ? 'sandbox' : 'production' }})</td><td><span class="status" :class="d?.peex.last_error ? 'warn' : 'ok'">{{ d?.peex.last_error ? 'Erreurs récentes' : 'Normal' }}</span></td></tr>
              <tr><td>Demandes PEEX en attente</td><td><span class="status" :class="d?.peex.awaiting ? 'pending' : 'muted'">{{ n(d?.peex.awaiting) }}</span></td></tr>
              <tr><td>Mobile money (MTN, Airtel, Orange, Moov…)</td><td><span class="status ok">Via PEEX</span></td></tr>
              <tr><td>Réseau d'agents (dépôt / cash pickup)</td><td><span class="status ok">Actif</span></td></tr>
              <tr><td>Cartes Visa / Mastercard</td><td><span class="status" :class="d?.peex.sandbox ? 'warn' : 'muted'">{{ d?.peex.sandbox ? 'Sandbox (simulée)' : 'Bientôt' }}</span></td></tr>
              <tr><td>Paiement sans contact (NFC / TPE)</td><td><span class="status ok">Actif</span></td></tr>
              <tr><td>GAB / virement bancaire</td><td><span class="status muted">Bientôt</span></td></tr>
            </tbody>
          </table>
          <p v-if="d?.peex.last_error" class="hint" style="padding: 8px 16px 12px; margin: 0;">Dernière erreur PEEX : {{ d.peex.last_error }}</p>
        </div>
        <div class="container-foot"><router-link to="/peex">Ouvrir la console PEEX</router-link></div>
      </section>
    </div>

    <!-- ===== Transactions récentes ===== -->
    <section class="container">
      <div class="container-head">
        <h3>Transactions récentes <span class="counter">({{ d?.recent.length ?? 0 }})</span></h3>
        <router-link class="btn-normal" to="/transactions">Voir tout</router-link>
      </div>
      <div class="container-body flush" style="overflow-x: auto;">
        <table>
          <thead><tr><th>Référence</th><th>Type</th><th>Expéditeur</th><th>Bénéficiaire</th><th class="num">Montant</th><th>Statut</th><th>Date</th></tr></thead>
          <tbody>
            <tr v-for="t in d?.recent || []" :key="t.id" style="cursor: pointer;" @click="$router.push('/transactions/' + t.id)">
              <td><router-link :to="'/transactions/' + t.id" class="mono" @click.stop>{{ t.reference }}</router-link></td>
              <td>{{ label(t.type) }}</td>
              <td><strong>{{ t.sender?.name || '—' }}</strong><br /><small class="mono" style="color:var(--text-2)">{{ t.sender?.account || label(t.source_rail) }}</small></td>
              <td><strong>{{ t.beneficiary?.name || '—' }}</strong><br /><small class="mono" style="color:var(--text-2)">{{ t.beneficiary?.account || label(t.destination_rail) }}</small></td>
              <td class="num">{{ xaf(t.amount) }}</td>
              <td><span class="status" :class="statusClass(t.status)">{{ label(t.status) }}</span></td>
              <td>{{ fmtDateTime(t.created_at) }}</td>
            </tr>
            <tr v-if="d && !d.recent.length"><td colspan="7" class="stat-label">Aucune transaction pour l'instant — lancez un test depuis la console PEEX.</td></tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>

<script setup>
import { computed, onMounted, onBeforeUnmount, ref } from 'vue'
import api from '../services/api'
import { Bar } from 'vue-chartjs'
import {
  Chart as ChartJS, Tooltip, Legend, BarElement, BarController, LineElement, LineController,
  PointElement, CategoryScale, LinearScale,
} from 'chart.js'

ChartJS.register(Tooltip, Legend, BarElement, BarController, LineElement, LineController, PointElement, CategoryScale, LinearScale)

const d = ref(null)
const days = ref(14)
const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const from = ref('')
const to = ref('')
// Paramètres de période envoyés à l'API et repris dans les liens vers /transactions
const periodQuery = () => (days.value === 'custom'
  ? { ...(from.value ? { from: from.value } : {}), ...(to.value ? { to: to.value } : {}) }
  : { days: days.value })
function onPeriod() {
  if (days.value === 'custom') {
    if (!from.value) from.value = d.value?.period.from || today
    if (!to.value) to.value = today
  }
  load()
}
const loading = ref(false)
const error = ref('')
const updatedAt = ref('')
const families = computed(() => d.value?.families || [])
const k = computed(() => d.value?.kpi)
const perf = computed(() => d.value?.performance)
const pctTxt = (v) => (v == null ? '—' : String(v).replace('.', ',') + ' %')
const dur = (s) => (s == null ? '—' : s < 60 ? s + ' s' : s < 3600 ? Math.round(s / 60) + ' min' : (s / 3600).toFixed(1).replace('.', ',') + ' h')
const KPI_ROWS = [
  { key: 'transactions', label: 'Transactions', fmt: (v) => n(v) },
  { key: 'volume', label: 'Volume réussi (XAF)', fmt: (v) => short(v) },
  { key: 'success_rate', label: 'Taux de réussite', fmt: (v) => pctTxt(v), points: true },
  { key: 'avg_ticket', label: 'Panier moyen (XAF)', fmt: (v) => (v == null ? '—' : n(v)) },
  { key: 'fees', label: 'Frais perçus (XAF)', fmt: (v) => short(v) },
  { key: 'active_users', label: 'Utilisateurs actifs', fmt: (v) => n(v) },
  { key: 'new_accounts', label: 'Nouveaux comptes', fmt: (v) => n(v) },
  { key: 'avg_delay', label: 'Délai moyen de traitement', fmt: dur, lowerIsBetter: true },
]
// Évolution : en % (ou en points pour un taux)
const evo = (c, p, points = false) => {
  if (c == null || p == null) return '—'
  if (points) { const dlt = Math.round((c - p) * 10) / 10; return (dlt > 0 ? '▲ +' : dlt < 0 ? '▼ ' : '= ') + String(dlt).replace('.', ',') + ' pt' }
  if (!p) return c ? '▲ nouveau' : '='
  const r = Math.round(((c - p) * 100) / p)
  return (r > 0 ? '▲ +' : r < 0 ? '▼ ' : '= ') + r + ' %'
}
const evoClass = (c, p, lowerIsBetter = false) => {
  if (c == null || p == null || c === p) return 'flat'
  return (c > p) !== lowerIsBetter ? 'up' : 'down'
}
const rateClass = (v) => (v == null ? 'muted' : v >= 90 ? 'ok' : v >= 70 ? 'warn' : 'err')
const pct0 = (a, b) => (b ? Math.round((a * 100) / b) + ' %' : '—')
const STATUS_TILES = [
  { key: 'successful', label: 'Réussies', cls: 'ok' },
  { key: 'failed', label: 'Échouées', cls: 'err' },
  { key: 'reversed', label: 'Rejetées', cls: 'warn' },
  { key: 'processing', label: 'En attente', cls: 'pend' },
]
// Lien vers la liste des transactions filtrée (famille / canal, statut, période)
const tx = (channel, status) => ({ path: '/transactions', query: { ...periodQuery(), ...(channel ? { channel } : {}), ...(status ? { status } : {}) } })
const rate = (r) => { const done = r.successful + r.failed + r.reversed; return done ? Math.round((r.successful * 100) / done) + ' %' : '—' }
const stackTitle = (f) => STATUS_TILES.map((s) => `${s.label} : ${f[s.key]}`).join(' · ')
let timer = null

const LABELS = {
  p2p: 'Transfert P2P', merchant_payment: 'Paiement marchand', cash_in: 'Cash-in', cash_out: 'Cash-out',
  collection: 'Encaissement USSD', withdrawal: 'Retrait mobile money', cash_pickup: 'Retrait cash (agent)', atm_withdrawal: 'Retrait GAB', atm: 'GAB', cash: 'Cash', qr_payment: 'Paiement QR', nfc_payment: 'Paiement NFC', manual_payment: 'Paiement manuel',
  peex: 'PEEX', wallet: 'Wallet',
  successful: 'Réussie', processing: 'En attente', failed: 'Échouée', reversed: 'Rejetée',
}
const label = (k) => LABELS[k] || k
const statusClass = (s) => ({ successful: 'ok', processing: 'pending', failed: 'err', reversed: 'warn' }[s] || 'muted')

const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => (v == null ? '—' : nf.format(v))
const xaf = (v) => (v == null ? '—' : nf.format(v) + ' XAF')
const short = (v) => {
  if (v == null) return '—'
  if (Math.abs(v) >= 1e9) return (v / 1e9).toFixed(1).replace('.', ',') + ' Md'
  if (Math.abs(v) >= 1e6) return (v / 1e6).toFixed(1).replace('.', ',') + ' M'
  if (Math.abs(v) >= 1e4) return (v / 1e3).toFixed(0) + ' k'
  return nf.format(v)
}
const pct = (c, t) => (t ? Math.max(2, Math.round((c * 100) / t)) : 0)
const fmtDate = (s) => (s ? new Date(s).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }) : '…')
const fmtDateTime = (s) => new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })

const chartData = computed(() => {
  if (!d.value) return null
  const s = d.value.series
  return {
    labels: s.map((r) => new Date(r.date).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' })),
    datasets: [
      { type: 'bar', label: 'Volume réussi (XAF)', data: s.map((r) => r.volume), backgroundColor: '#1c2aa5', borderRadius: 3, yAxisID: 'y', order: 2 },
      { type: 'line', label: 'Transactions', data: s.map((r) => r.count), borderColor: '#ff0000', backgroundColor: '#ff0000', pointRadius: 3, tension: .3, yAxisID: 'y1', order: 1 },
    ],
  }
})

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
    tooltip: { callbacks: { label: (c) => `${c.dataset.label} : ${nf.format(c.parsed.y)}` } },
  },
  scales: {
    x: { grid: { display: false } },
    y: { beginAtZero: true, grid: { color: '#e9ebed' }, ticks: { callback: (v) => short(v) } },
    y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { precision: 0 } },
  },
}

// Effectifs par rôle (client, marchand, caissier, agent, sous-agent, super-agent)
const net = ref(null)
const NET_ROLES = [
  { key: 'client', label: 'Clients', to: '/clients', blue: true, icon: '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/>' },
  { key: 'merchant', label: 'Marchands', to: '/merchants', icon: '<path d="M3 9l1.5-5h15L21 9"/><path d="M3 9h18v2a3 3 0 0 1-6 0 3 3 0 0 1-6 0 3 3 0 0 1-6 0z"/><path d="M5 13v7h14v-7"/>' },
  { key: 'cashier', label: 'Caissiers', to: '/cashiers', blue: true, icon: '<rect x="4" y="3" width="12" height="7" rx="1.5"/><path d="M3 21h18l-2-9H5z"/>' },
  { key: 'agent', label: 'Agents', to: '/agents?level=simple', icon: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 13h18"/>' },
  { key: 'sub_agent', label: 'Sous-agents', to: '/agents?level=sub', blue: true, icon: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M12 11v6M9 14h6"/>' },
  { key: 'super_agent', label: 'Super-agents', to: '/agents?level=super', icon: '<path d="M3 18h18M4 18l-1-10 5 4 4-7 4 7 5-4-1 10"/>' },
]
function loadNet() {
  api.get('/admin/roles').then(({ data }) => { net.value = data }).catch(() => {})
}

async function load() {
  loadNet()
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/dashboard', { params: periodQuery() })
    d.value = data
    updatedAt.value = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })
  } catch (e) {
    error.value = e.response?.status === 403
      ? 'Tableau de bord réservé aux Super Admins.'
      : (e.response?.data?.message || e.message)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
  timer = setInterval(load, 60000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<style scoped>
.rot { animation: spin 1s linear infinite; }
.date-range { display: inline-flex; align-items: center; gap: 6px; }
.date-range span { color: var(--text-2); font-size: 13px; }
.date-range input { padding: 7px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
.v.skeleton { min-width: 80px; }

/* Tuiles cliquables */
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
.fam-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 18px; }
.tile {
  display: block; padding: 14px 16px; border: 1px solid var(--border); border-radius: 10px;
  color: inherit; text-decoration: none; background: var(--surface); cursor: pointer;
  transition: border-color .15s, box-shadow .15s, transform .15s;
}
.tile:hover, .tile:focus-visible { border-color: var(--link); box-shadow: 0 4px 14px rgba(9, 114, 211, .15); transform: translateY(-2px); }
.tile:active { transform: translateY(0); }
.tile .k { color: var(--text-2); font-size: 14px; margin-bottom: 4px; display: flex; justify-content: space-between; }
.tile .k::after { content: '›'; color: var(--link); font-weight: 700; }
.tile .v { font-size: 28px; line-height: 36px; font-weight: 700; white-space: nowrap; }
.tile .v small { font-size: 14px; font-weight: 400; color: var(--text-2); }
.tile .sub { font-size: 12px; color: var(--text-2); }
.tile .sub.bad { color: var(--err); }
.mini-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 0; margin-top: 14px; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.mini-kpis a { display: flex; flex-direction: column; padding: 10px 16px; color: inherit; text-decoration: none; border-right: 1px solid var(--border); }
.mini-kpis a:last-child { border-right: 0; }
.mini-kpis a:hover { background: var(--info-bg); }
.mini-kpis span { font-size: 12px; color: var(--text-2); }
.mini-kpis b { font-size: 16px; }
.mini-kpis em { font-style: normal; font-size: 12px; }
.bad { color: var(--err); }

/* Couleurs des statuts */
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; background: currentColor; }
.st-ok .dot, .s-ok { color: var(--ok); background: var(--ok); }
.st-err .dot, .s-err { color: var(--err); background: var(--err); }
.st-warn .dot, .s-warn { color: var(--warn); background: var(--warn); }
.st-pend .dot, .s-pend { color: var(--link); background: var(--link); }
.tile.st-ok .v { color: var(--ok); }
.tile.st-err .v { color: var(--err); }
.tile.st-warn .v { color: var(--warn); }
.tile.st-pend .v { color: var(--link); }
.c-ok a { color: var(--ok); } .c-err a { color: var(--err); } .c-warn a { color: var(--warn); } .c-pend a { color: var(--link); }

/* Cartes famille */
.fam-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; }
.fam-card { margin: 0; border-top: 3px solid var(--primary); }
.fam-withdrawals { border-top-color: #b91c1c; }
.fam-deposits { border-top-color: #047857; }
.fam-payments { border-top-color: #1c2aa5; }
.fam-transfers { border-top-color: #b45309; }
.stack { display: flex; height: 8px; border-radius: 99px; overflow: hidden; background: #eef0f4; margin-bottom: 12px; }
.stack span { display: block; height: 100%; }
.st-row { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; margin-bottom: 12px; }
.st { display: flex; align-items: baseline; gap: 6px; padding: 6px 10px; border-radius: 8px; background: var(--surface-2, #f7f8fa); color: inherit; text-decoration: none; min-width: 0; }
.st:hover { background: var(--info-bg); }
.st b { font-size: 18px; line-height: 22px; }
.st span { font-size: 12px; color: var(--text-2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.st .dot { margin: 0; align-self: center; }
.ch-list { display: grid; border-top: 1px solid var(--border); }
.ch { display: flex; justify-content: space-between; gap: 10px; padding: 8px 4px; border-bottom: 1px solid var(--border); color: inherit; text-decoration: none; font-size: 13.5px; }
.ch:last-child { border-bottom: 0; }
.ch:hover { background: var(--info-bg); }
.ch-num { color: var(--text-2); white-space: nowrap; }
.ch-num b { color: var(--text); }

/* Tableau détaillé */
.matrix td a { color: inherit; text-decoration: none; }
.matrix td a:hover { text-decoration: underline; }
.matrix .fam-row td { font-weight: 700; background: var(--surface-2, #f7f8fa); }
.matrix .ch-row td:first-child { padding-left: 28px; }
.matrix .ch-row.zero td { color: var(--text-3, #9aa3b2); }
.matrix .ch-row.zero td a { color: inherit; }
.matrix tfoot td { border-top: 2px solid var(--border-strong); }

/* Indicateurs de performance */
.perf-head { display: flex; align-items: baseline; justify-content: space-between; flex-wrap: wrap; gap: 6px; margin: 4px 0 12px; }
.perf-head h2 { font-size: 18px; margin: 0; }
.perf-head span { font-size: 13px; color: var(--text-2); }
.perf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 16px; }
.perf-grid .container { margin: 0; }
.mini { font-size: 13px; }
.mini th, .mini td { padding-top: 8px; padding-bottom: 8px; }
.mini td a { color: inherit; }
.mini .muted { color: var(--text-2); }
.mini .rank { color: var(--text-2); font-weight: 700; width: 28px; }
.evo { display: inline-block; font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 99px; white-space: nowrap; }
.evo.up { color: var(--ok); background: var(--ok-bg); }
.evo.down { color: var(--err); background: var(--err-bg); }
.evo.flat { color: var(--text-2); background: #f1f5f9; }
.rate { display: flex; align-items: center; gap: 8px; }
.rate .meter { flex: 1; min-width: 50px; }
.rate b { white-space: nowrap; min-width: 48px; text-align: right; font-size: 12.5px; }
.mini td { vertical-align: middle; }
.rate .meter span.ok { background: var(--ok); } .rate .meter span.warn { background: var(--warn); } .rate .meter span.err { background: var(--err); }
.t-ok { color: var(--ok); } .t-warn { color: var(--warn); } .t-err { color: var(--err); } .t-muted { color: var(--text-2); }
@media (max-width: 520px) { .perf-grid { grid-template-columns: 1fr; } }
</style>
