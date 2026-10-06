<template>
  <div class="wp">
    <div class="wp-head">
      <div>
        <h1>Transactions</h1>
        <p>{{ subtitle || 'Toutes les opérations FlashPay : collectes, versements, paiements et transferts' }}</p>
      </div>
      <div class="wp-hbtn">
        <button class="btn-normal" @click="showRates = true">Tarifs partenaires</button>
        <router-link class="btn-normal" to="/">‹ Tableau de bord</router-link>
      </div>
    </div>

    <!-- Devise des cartes de synthèse -->
    <div class="wp-cur" v-if="curList.length">
      <button v-for="c in curList" :key="c" :class="{ on: c === cur }" @click="cur = c">{{ c }}</button>
    </div>

    <!-- Cartes de synthèse -->
    <div class="wp-cards">
      <div class="wp-card" @click="quickStatus('successful')">
        <span class="ic ok"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M8 12.5l2.7 2.7L16 10"/></svg></span>
        <div><small>Total réussi</small><b>{{ n(S.successful?.amount) }} <i>{{ cur }}</i></b><em>{{ plural(S.successful?.count) }}</em></div>
      </div>
      <div class="wp-card" @click="quickStatus('processing')">
        <span class="ic pend"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
        <div><small>En attente</small><b>{{ n(S.pending?.amount) }} <i>{{ cur }}</i></b><em>{{ plural(S.pending?.count) }}</em></div>
      </div>
      <div class="wp-card" @click="quickStatus('failed')">
        <span class="ic ko"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg></span>
        <div><small>Échouées</small><b>{{ n(S.failed?.amount) }} <i>{{ cur }}</i></b><em>{{ plural(S.failed?.count) }}</em></div>
      </div>
      <div class="wp-card">
        <span class="ic fee"><svg viewBox="0 0 24 24"><path d="M12 3v18M16.5 7.5c0-1.7-2-3-4.5-3s-4.5 1.3-4.5 3 2 2.6 4.5 3 4.5 1.3 4.5 3-2 3-4.5 3-4.5-1.3-4.5-3"/></svg></span>
        <div><small>Frais facturés</small><b>{{ n(S.billed) }} <i>{{ cur }}</i></b><em :title="`Frais partenaires −${n(S.partner_fees)} · Gain FlashPay ${n(S.margin)}`">Partenaires −{{ n(S.partner_fees) }} · Gain <span :class="S.margin < 0 ? 'neg' : 'pos'">{{ n(S.margin) }}</span></em></div>
      </div>
      <div class="wp-card">
        <span class="ic rate"><svg viewBox="0 0 24 24"><path d="M6 18L18 6"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/></svg></span>
        <div><small>Taux de réussite</small><b>{{ S.success_rate == null ? '—' : S.success_rate + '%' }}</b><em>{{ plural(S.total) }} au total</em></div>
      </div>
    </div>

    <!-- Filtres -->
    <section class="wp-box wp-filters">
      <label><span>Statut</span>
        <select v-model="f.status" @change="apply">
          <option value="">Tous</option>
          <option value="successful">Réussies</option>
          <option value="processing">En attente</option>
          <option value="failed">Échouées</option>
          <option value="reversed">Remboursées</option>
        </select>
      </label>
      <label><span>Devise</span>
        <select v-model="f.currency" @change="apply">
          <option value="">Toutes</option>
          <option v-for="c in allCurrencies" :key="c" :value="c">{{ c }}</option>
        </select>
      </label>
      <label><span>Type d'opération</span>
        <select v-model="f.channel" @change="apply">
          <option value="">Toutes</option>
          <optgroup v-for="(fam, key) in FAMILIES" :key="key" :label="fam.label">
            <option :value="key">Toute la famille « {{ fam.label }} »</option>
            <option v-for="c in fam.channels" :key="c" :value="c">{{ CHANNELS[c] }}</option>
          </optgroup>
        </select>
      </label>
      <label><span>Date de début</span><input v-model="f.from" type="date" :max="f.to || today" @change="f.days = ''; apply()" /></label>
      <label><span>Date de fin</span><input v-model="f.to" type="date" :min="f.from" :max="today" @change="f.days = ''; apply()" /></label>
      <label class="wide"><span>Recherche</span>
        <input v-model="f.q" type="search" placeholder="Numéro de téléphone, référence FP-…" @keyup.enter="apply" />
      </label>
      <div class="wp-actions">
        <button class="wp-btn" title="Réinitialiser les filtres" :disabled="loading" @click="reset"><svg viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.3-5.7M4 4v4h4"/></svg></button>
        <button class="wp-btn" :class="{ spin: loading }" title="Actualiser" :disabled="loading" @click="refresh"><svg viewBox="0 0 24 24"><path d="M20 12a8 8 0 0 1-14 5.3M4 12A8 8 0 0 1 18 6.7M18 3v4h-4M6 21v-4h4"/></svg></button>
        <button class="wp-btn" :title="selected.size ? `Imprimer les reçus des ${selected.size} transactions cochées` : 'Imprimer les reçus de cette page'" :disabled="!transactions.length" @click="printReceipts">
          <svg viewBox="0 0 24 24"><path d="M6 9V3h12v6M6 18H4a1 1 0 0 1-1-1v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6a1 1 0 0 1-1 1h-2M6 14h12v7H6z"/></svg>
          Reçus<template v-if="selected.size"> ({{ selected.size }})</template>
        </button>
        <ExportButton class="wp-btn primary" filename="transactions" :columns="EXP_COLS" :fetch="expFetch" />
      </div>
    </section>

    <div class="wp-refresh">
      <span v-if="refreshedAt">Mis à jour à {{ refreshedAt }}<template v-if="meta"> · {{ meta.total }} résultat{{ meta.total > 1 ? 's' : '' }}</template></span>
      <label title="Recharge la liste toutes les 30 secondes"><input v-model="auto" type="checkbox" /> Actualisation auto (30 s)</label>
      <span v-if="selected.size" class="sel">{{ selected.size }} sélectionnée{{ selected.size > 1 ? 's' : '' }} · <a href="#" @click.prevent="selected.clear()">désélectionner</a></span>
    </div>

    <!-- Tableau -->
    <section class="wp-box flush">
      <div class="wp-table">
        <table>
          <colgroup>
            <col style="width:3%"><col style="width:12%"><col style="width:12%"><col style="width:12%"><col style="width:8%"><col style="width:6%"><col style="width:8%"><col style="width:7%">
            <col style="width:7%"><col style="width:10%"><col style="width:9%"><col style="width:6%">
          </colgroup>
          <thead>
            <tr><th class="c-chk" @click.stop><input type="checkbox" :checked="allChecked" :indeterminate.prop="selected.size > 0 && !allChecked" title="Tout cocher (page)" @change="toggleAll" /></th><th>ID</th><th>Expéditeur</th><th>Bénéficiaire</th><th class="num">Montant</th><th class="num">Frais</th><th class="num" title="Frais prélevés par les partenaires (WacePay / PEEX) ; « ~ » = estimé avec les tarifs partenaires">Frais part.</th><th class="num" title="Gain FlashPay = frais facturés − frais partenaires">Gain FP</th><th>Passerelle</th><th>Opérateurs</th><th>Date</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="t in transactions" :key="t.id" :class="{ sel: selected.has(t.id) }" @click="detail = t">
              <td class="c-chk" @click.stop><input type="checkbox" :checked="selected.has(t.id)" @change="toggle(t.id)" /></td>
              <td class="c-code"><b class="mono">{{ t.reference }}</b><small><span class="pill sm" :class="t.status">{{ STATUS[t.status] || t.status }}</span> {{ t.journal?.label }}</small></td>
              <td class="c-who"><b :title="t.sender?.name">{{ t.sender?.name || '—' }}</b><small>{{ phone(t.sender?.account) }}</small></td>
              <td class="c-who"><b :title="t.beneficiary?.name">{{ t.beneficiary?.name || '—' }}</b><small>{{ phone(t.beneficiary?.account) }}</small></td>
              <td class="num"><b>{{ money(t.amount, t.currency) }}</b></td>
              <td class="num">{{ money(t.fee + (t.merchant_fee || 0), t.currency) }}</td>
              <td class="num" :title="partnerTitle(t)">
                <span v-if="t.costs?.partner_total" class="neg"><small v-if="isEstimate(t)" class="est">~</small>{{ money(t.costs.partner_total, t.currency) }}</span>
                <span v-else-if="t.costs?.legs?.some((l) => l.fee === null && l.status !== 'failed')" class="unk" title="Frais non communiqués par la passerelle">?</span>
                <span v-else class="muted">0</span>
              </td>
              <td class="num" :title="t.status === 'successful' ? 'Facturé ' + money(t.costs?.billed, t.currency) + ' − partenaires ' + money(t.costs?.partner_total, t.currency) : 'Opération non réussie : aucun frais conservé'">
                <b v-if="t.costs" :class="t.costs.margin < 0 ? 'neg' : t.costs.margin > 0 ? 'pos' : 'muted'">{{ money(t.costs.margin, t.currency) }}</b>
              </td>
              <td class="c-svc">
                <em v-for="p in gateways(t)" :key="p" :class="'p-' + p.toLowerCase()">{{ p }}</em>
                <span v-if="!gateways(t).length" class="muted">Interne</span>
              </td>
              <td class="c-ops">{{ opName(t.gateway?.in) }} <span class="arr">→</span> {{ opName(t.gateway?.out) }}</td>
              <td class="c-dt">{{ dt(t.created_at) }}</td>
              <td class="c-eye" @click.stop>
                <button title="Voir les détails" @click="detail = t"><svg viewBox="0 0 24 24"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                <button title="Reçu (imprimer / PDF)" @click="openReceipt(t.id)"><svg viewBox="0 0 24 24"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4"/></svg></button>
              </td>
            </tr>
            <tr v-if="!loading && !transactions.length"><td colspan="12" class="empty">Aucune transaction pour ces filtres.</td></tr>
          </tbody>
        </table>
      </div>
      <div class="wp-pager" v-if="meta && meta.last_page > 1">
        <button :disabled="meta.current_page === 1" @click="load(1)">«</button>
        <button :disabled="!meta.prev_page_url" @click="load(meta.current_page - 1)">‹</button>
        <span>{{ meta.current_page }} / {{ meta.last_page }}</span>
        <button :disabled="!meta.next_page_url" @click="load(meta.current_page + 1)">›</button>
        <button :disabled="meta.current_page === meta.last_page" @click="load(meta.last_page)">»</button>
      </div>
    </section>

    <!-- Détails (même disposition que WacePay) -->
    <Teleport to="body">
      <div v-if="detail" class="wp-modal-bg" @mousedown.self="detail = null">
        <div class="wp-modal" role="dialog">
          <div class="wp-mh">
            <span class="ic send"><svg viewBox="0 0 24 24"><path d="M21 3L10 14M21 3l-7 18-4-7-7-4 18-7z"/></svg></span>
            <div><b>Détails de la transaction</b><small class="mono">{{ detail.reference }}</small></div>
            <button class="x" @click="detail = null" title="Fermer">×</button>
          </div>

          <div class="wp-datebox">
            <div><small>Date</small><b>{{ dtLong(detail.created_at) }}</b></div>
            <span class="pill" :class="detail.status">{{ STATUS[detail.status] || detail.status }}</span>
          </div>

          <div class="wp-sec">
            <h4>Expéditeur</h4>
            <div class="kv"><span>Nom</span><b>{{ detail.sender?.name || '—' }}</b></div>
            <div class="kv"><span>Compte</span><b>{{ phone(detail.sender?.account) || '—' }}</b></div>
            <div class="kv"><span>Service</span><b>{{ detail.gateway?.in.operator || '—' }}<template v-if="detail.gateway?.in.partner"> · {{ detail.gateway.in.partner }}</template></b></div>
          </div>

          <div class="wp-sec">
            <h4>Bénéficiaire</h4>
            <div class="kv"><span>Nom</span><b>{{ detail.beneficiary?.name || '—' }}</b></div>
            <div class="kv"><span>Compte</span><b>{{ phone(detail.beneficiary?.account) || '—' }}</b></div>
            <div class="kv"><span>Service</span><b>{{ detail.gateway?.out.operator || '—' }}<template v-if="detail.gateway?.out.partner"> · {{ detail.gateway.out.partner }}</template></b></div>
            <div class="kv" v-if="detail.gateway?.out.country"><span>Pays</span><b>{{ detail.gateway.out.country }}</b></div>
          </div>

          <div class="wp-sec">
            <h4>Montants</h4>
            <div class="kv"><span>Montant</span><b>{{ money(detail.amount, detail.currency) }}</b></div>
            <div class="kv"><span>Frais facturés (FlashPay)</span><b>{{ money(detail.costs?.billed ?? detail.fee, detail.currency) }}</b></div>
            <div class="kv" v-for="l in detail.costs?.legs || []" :key="l.kind + l.reference">
              <span>Frais {{ l.partner }} · {{ l.kind === 'collect' ? 'collecte' : l.kind === 'payout' ? 'versement' : 'remboursement' }}<small v-if="l.country"> ({{ l.country }})</small><small v-if="l.reference" class="mono"> {{ l.reference }}</small></span>
              <b class="neg" v-if="l.fee !== null">−{{ money(l.fee, l.currency) }}<small v-if="l.fee_source === 'estimate'"> (estimé)</small></b>
              <b class="unk" v-else>non communiqués</b>
            </div>
            <div class="kv tot"><span>Total frais partenaires</span><b class="neg">−{{ money(detail.costs?.partner_total ?? 0, detail.currency) }}<small v-if="isEstimate(detail)"> (estimé)</small></b></div>
            <div class="kv tot"><span>Gain FlashPay</span><b :class="(detail.costs?.margin ?? 0) < 0 ? 'neg' : 'pos'">{{ money(detail.costs?.margin ?? 0, detail.currency) }}</b></div>
            <div class="kv"><span>Net reçu par le bénéficiaire</span><b class="net">{{ money(netOf(detail), detail.destination_currency || detail.currency) }}</b></div>
          </div>

          <div class="wp-sec" v-if="detail.failure_reason">
            <h4>Motif d'échec</h4>
            <p class="reason">{{ detail.failure_reason }}</p>
          </div>

          <div class="wp-sec" v-if="check">
            <h4>Vérification auprès des passerelles</h4>
            <p v-if="check.loading" class="muted">Interrogation de WacePay / PEEX…</p>
            <p v-else-if="check.error" class="reason">{{ check.error }}</p>
            <template v-else>
              <div v-for="r in check.requests" :key="r.reference" class="chk">
                <div class="kv"><span>{{ r.partner }} · {{ r.operation }} <small class="mono">{{ r.reference }}</small></span>
                  <b><span class="pill sm" :class="r.status === 'successful' || r.status === 'paid' ? 'successful' : r.status === 'failed' ? 'failed' : 'processing'">{{ r.raw_status || r.status }}</span></b></div>
                <p v-if="r.message" class="chk-msg">{{ r.message }}</p>
                <details v-if="r.response"><summary>Réponse brute</summary><pre>{{ JSON.stringify(r.response, null, 2) }}</pre></details>
              </div>
              <p v-if="!check.requests.length" class="muted">Aucune demande partenaire pour cette opération.</p>
            </template>
          </div>

          <div class="wp-mf">
            <button class="wp-btn" title="Imprimer ou enregistrer en PDF (format A4)" @click="openReceipt(detail.id)">
              <svg viewBox="0 0 24 24"><path d="M6 2h12v20l-3-2-3 2-3-2-3 2zM9 7h6M9 11h6M9 15h4"/></svg> Reçu
            </button>
            <button class="wp-btn" title="Format imprimante thermique 58/80 mm" @click="openReceipt(detail.id, { ticket: true })">Ticket</button>
            <button v-if="detail.status === 'processing' || detail.status === 'failed'" class="wp-btn" :disabled="check?.loading" @click="checkStatus(detail)">Vérifier le statut</button>
            <button v-if="detail.refundable > 0" class="wp-btn" @click="refundTx = detail.id; detail = null">Rembourser via PEEX</button>
            <router-link class="wp-btn primary" :to="'/transactions/' + detail.id">Fiche complète ›</router-link>
          </div>
        </div>
      </div>
    </Teleport>

    <Modal v-if="showRates" title="Tarifs partenaires" subtitle="Appliqués quand PEEX / WacePay ne renvoient pas leurs frais (affichés « estimé ») ; les frais communiqués par le partenaire restent prioritaires. Un tarif par pays remplace le tarif général pour ce pays." @close="showRates = false">
      <div class="rate-list">
        <div v-for="(r, k) in rates" :key="k" class="rate-block">
          <div class="rate-row">
            <label>{{ r.label }}</label>
            <span><input v-model.number="r.pct" type="number" min="0" max="50" step="0.01" /> %</span>
            <span>+ <input v-model.number="r.fixed" type="number" min="0" step="1" /> fixe</span>
            <span class="rate-hint">{{ r.countryList.length ? 'autres pays' : 'tous pays' }}</span>
          </div>
          <div v-for="(c, i) in r.countryList" :key="i" class="rate-row sub">
            <select v-model="c.country">
              <option v-for="(name, iso) in COUNTRIES" :key="iso" :value="iso">{{ name }}</option>
            </select>
            <span><input v-model.number="c.pct" type="number" min="0" max="50" step="0.01" /> %</span>
            <span>+ <input v-model.number="c.fixed" type="number" min="0" step="1" /> fixe</span>
            <button class="rate-x" title="Retirer ce pays" @click="r.countryList.splice(i, 1)">×</button>
          </div>
          <button class="rate-add" @click="r.countryList.push({ country: 'CG', pct: 0, fixed: 0 })">+ Tarif pour un pays</button>
        </div>
      </div>
      <template #foot>
        <button class="btn-normal" style="margin-right:auto" title="Remet les tarifs WacePay de l'offre commerciale (PayIn 3,5 % ; PayOut 1 500 FCFA CG/CM/TD/CF, 2,85 % GA, 1,75 % CD)" @click="resetWacepay">Offre WacePay</button>
        <button class="btn-normal" @click="showRates = false">Annuler</button>
        <button class="btn" :disabled="savingRates" @click="saveRates">{{ savingRates ? 'Enregistrement…' : 'Enregistrer' }}</button>
      </template>
    </Modal>

    <PeexRefund v-if="refundTx" :transaction-id="refundTx" @close="refundTx = null" @done="load(meta?.current_page || 1)" />
  </div>
</template>

<script setup>
import PeexRefund from '../components/PeexRefund.vue'
import ExportButton from '../components/ExportButton.vue'
import Modal from '../components/Modal.vue'
import { fetchAllPages, fmtDate } from '../utils/export'
import { openReceipt, openReceipts } from '../utils/receipt'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../services/api'

// Même classement que le tableau de bord (App\Support\TransactionChannels)
const FAMILIES = {
  withdrawals: { label: 'Retraits', channels: ['withdrawal_qr', 'withdrawal_wallet', 'bank_transfer'] },
  deposits: { label: 'Recharges', channels: ['deposit_agent', 'deposit_card', 'deposit_mm'] },
  payments: { label: 'Paiements', channels: ['payment_wallet', 'payment_mm', 'payment_bank'] },
  transfers: { label: 'Transferts & autres', channels: ['transfer_wallet', 'transfer_to_mm', 'interop', 'card_transfer', 'agent_float'] },
}
const CHANNELS = {
  withdrawal_qr: 'Retrait QR code / bon de retrait', withdrawal_wallet: 'Retrait wallet', bank_transfer: 'Virement bancaire',
  deposit_agent: 'Recharge cash agent', deposit_card: 'Recharge carte prépayée / bancaire', deposit_mm: 'Recharge mobile money → wallet',
  payment_wallet: 'Paiement wallet', payment_mm: 'Paiement mobile money', payment_bank: 'Paiement banque / carte',
  transfer_wallet: 'Wallet → wallet', transfer_to_mm: 'Wallet → mobile money', interop: 'Interopérabilité', card_transfer: 'Envoi payé par carte', agent_float: 'Float agent',
}
const STATUS = { successful: 'Réussie', processing: 'En attente', failed: 'Échouée', reversed: 'Remboursée' }

const route = useRoute()
const router = useRouter()
const transactions = ref([])
const meta = ref(null)
const loading = ref(false)
const refundTx = ref(null)
const detail = ref(null)
const cur = ref('XAF')
const seenCurrencies = ref(['XAF'])

const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => nf.format(v || 0)
const money = (v, c) => nf.format(v || 0) + ' ' + (c || 'XAF')
const plural = (c) => `${c || 0} transaction${(c || 0) > 1 ? 's' : ''}`
const dt = (d) => new Date(d).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }).replace(',', '')
const dtLong = (d) => new Date(d).toLocaleString('fr-FR', { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' })
const phone = (p) => (p && /^\+?\d{8,}$/.test(String(p).replace(/\s/g, '')) ? '+' + String(p).replace(/\D/g, '') : p || '')

// Net = ce que reçoit le bénéficiaire (après change et commission marchand)
const netOf = (t) => Math.max(0, (t.destination_amount ?? t.amount) - (t.merchant_fee || 0))
const partnerOf = (t) => {
  const p = t.gateway?.out.partner || t.gateway?.in.partner
  return p ? p.replace(/ \(.*\)/, '').replace('Carte (3-D Secure)', '3DS') : null
}
// Service : opérateur crédité (ou débité pour une recharge), avec le pays
const svc = (t) => {
  const g = t.gateway
  if (!g) return '—'
  const side = g.out.rail === 'wallet' && g.in.rail !== 'wallet' ? g.in : g.out
  const op = (side.operator || '—').replace(/ Mobile Money| Money/g, '').replace('Wallet FlashPay', 'Wallet')
  return side.country ? `${op} (${side.country})` : op
}
// Passerelles utilisées (collecte et/ou versement) et opérateurs débité → crédité
const gateways = (t) => [...new Set([t.gateway?.in.partner, t.gateway?.out.partner].filter(Boolean).map((p) => p.replace(/ \(.*\)/, '').replace('Carte (3-D Secure)', '3DS')))]
const opName = (side) => {
  if (!side) return '—'
  const op = (side.operator || '—').replace(/ Mobile Money| Money/g, '').replace('Wallet FlashPay', 'Wallet').replace('Carte Visa / Mastercard', 'Carte')
  return side.country ? `${op} (${side.country})` : op
}
const isEstimate = (t) => (t.costs?.legs || []).some((l) => l.fee_source === 'estimate' && l.status !== 'failed')
const legsTxt = (t, f) => (t.costs?.legs || []).map(f).filter(Boolean).join(' | ')
const partnerTitle = (t) => (t.costs?.legs || []).map((l) => `${l.partner} ${l.kind === 'collect' ? 'collecte' : l.kind === 'payout' ? 'versement' : 'remboursement'}${l.country ? ' (' + l.country + ')' : ''} : ${l.fee === null ? 'non communiqués' : n(l.fee) + ' ' + l.currency + (l.fee_source === 'estimate' ? ' (estimé)' : '')}`).join('\n')

const showRates = ref(false)
const rates = ref({})
const savingRates = ref(false)
const COUNTRIES = {
  CG: 'Congo', CM: 'Cameroun', GA: 'Gabon', TD: 'Tchad', CF: 'Centrafrique', GQ: 'Guinée équatoriale', CD: 'RD Congo',
  SN: 'Sénégal', CI: "Côte d'Ivoire", ML: 'Mali', BF: 'Burkina Faso', BJ: 'Bénin', TG: 'Togo', NE: 'Niger', GW: 'Guinée-Bissau', GN: 'Guinée',
}
// Tarifs par pays : objet {ISO: {pct, fixed}} côté API, liste éditable côté console
const withLists = (data) => Object.fromEntries(Object.entries(data).map(([k, r]) => [k, {
  ...r, countryList: Object.entries(r.countries || {}).map(([country, c]) => ({ country, pct: c.pct, fixed: c.fixed })),
}]))
const toPayload = () => Object.fromEntries(Object.entries(rates.value).map(([k, r]) => [k, {
  pct: r.pct || 0, fixed: r.fixed || 0, countries: r.countryList.map((c) => ({ country: c.country, pct: c.pct || 0, fixed: c.fixed || 0 })),
}]))
async function resetWacepay() {
  const { data } = await api.get('/admin/settings/partner-fees/defaults')
  for (const [k, d] of Object.entries(data)) {
    if (!rates.value[k]) continue
    rates.value[k].pct = d.pct
    rates.value[k].fixed = d.fixed
    rates.value[k].countryList = Object.entries(d.countries || {}).map(([country, c]) => ({ country, pct: c.pct, fixed: c.fixed }))
  }
}
watch(showRates, async (v) => { if (v) rates.value = withLists((await api.get('/admin/settings/partner-fees')).data) })
async function saveRates() {
  savingRates.value = true
  try {
    rates.value = withLists((await api.post('/admin/settings/partner-fees', { rates: toPayload() })).data)
    showRates.value = false
    await load(meta.value?.current_page || 1)
  } finally { savingRates.value = false }
}

// « Vérifier le statut » : interroge WacePay / PEEX et applique le résultat
const check = ref(null)
watch(detail, () => { check.value = null })
async function checkStatus(t) {
  check.value = { loading: true, requests: [] }
  try {
    const { data } = await api.post(`/admin/transactions/${t.id}/check-status`)
    check.value = { requests: data.requests || [] }
    if (data.status !== t.status) {
      await load(meta.value?.current_page || 1)
      detail.value = transactions.value.find((x) => x.id === t.id) || detail.value
      check.value = { requests: data.requests || [] }
    }
  } catch (e) {
    check.value = { error: e.response?.data?.message || e.message, requests: [] }
  }
}

const summary = computed(() => meta.value?.summary || {})
const curList = computed(() => Object.keys(summary.value))
const S = computed(() => summary.value[cur.value] || {})
const allCurrencies = computed(() => [...new Set([...seenCurrencies.value, ...curList.value])])

const f = reactive({ channel: '', status: '', days: '', from: '', to: '', q: '', user: '', currency: '' })
const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const frDate = (s) => new Date(s + 'T00:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })
const subtitle = computed(() => {
  const parts = []
  if (f.user) parts.push(meta.value?.user_name ? `Compte : ${meta.value.user_name}` : `Compte n° ${f.user}`)
  if (f.channel) parts.push(FAMILIES[f.channel]?.label || CHANNELS[f.channel] || f.channel)
  if (f.from && f.to) parts.push(`du ${frDate(f.from)} au ${frDate(f.to)}`)
  else if (f.from) parts.push(`depuis le ${frDate(f.from)}`)
  else if (f.to) parts.push(`jusqu'au ${frDate(f.to)}`)
  else if (f.days) parts.push(f.days === '1' ? "aujourd'hui" : `${f.days} derniers jours`)
  return parts.join(' · ')
})

function syncFromRoute() {
  for (const k of Object.keys(f)) f[k] = route.query[k] ? String(route.query[k]) : ''
}
function apply() {
  router.replace({ path: '/transactions', query: Object.fromEntries(Object.entries(f).filter(([, v]) => v)) })
}
function reset() {
  for (const k of Object.keys(f)) f[k] = ''
  selected.value.clear()
  // Déjà sans filtre : la route ne change pas, on recharge directement
  if (Object.keys(route.query).length) router.replace({ path: '/transactions' })
  else load(1)
}

// --- Actualisation (bouton + automatique toutes les 30 s)
const refreshedAt = ref('')
const auto = ref(false)
let timer = null
function refresh() { return load(meta.value?.current_page || 1) }
watch(auto, (on) => {
  clearInterval(timer)
  timer = on ? setInterval(() => { if (!loading.value && !detail.value && !document.hidden) refresh() }, 30000) : null
})
onBeforeUnmount(() => clearInterval(timer))

// --- Sélection & reçus
const selected = ref(new Set())
const allChecked = computed(() => transactions.value.length > 0 && transactions.value.every((t) => selected.value.has(t.id)))
function toggle(id) {
  const s = new Set(selected.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selected.value = s
}
function toggleAll() {
  const s = new Set(selected.value)
  if (allChecked.value) transactions.value.forEach((t) => s.delete(t.id))
  else transactions.value.forEach((t) => s.add(t.id))
  selected.value = s
}
function printReceipts() {
  const ids = selected.value.size ? [...selected.value] : transactions.value.map((t) => t.id)
  if (ids.length > 100 && !window.confirm('Seuls les 100 premiers reçus seront imprimés. Continuer ?')) return
  openReceipts(ids)
}
function quickStatus(s) {
  f.status = f.status === s ? '' : s
  apply()
}

async function load(page = 1) {
  loading.value = true
  try {
    const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v))
    const { data } = await api.get('/admin/transactions', { params: { ...params, page } })
    transactions.value = data.data
    meta.value = data
    const keys = Object.keys(data.summary || {})
    seenCurrencies.value = [...new Set([...seenCurrencies.value, ...keys])]
    if (f.currency) cur.value = f.currency
    else if (keys.length && !keys.includes(cur.value)) cur.value = keys[0]
    // Fenêtre Détails ouverte : affiche la version à jour
    if (detail.value) detail.value = data.data.find((x) => x.id === detail.value.id) || detail.value
    refreshedAt.value = new Date().toLocaleTimeString('fr-FR')
  } catch (e) {
    window.alert(e.response?.data?.message || 'Impossible de charger les transactions.')
  } finally {
    loading.value = false
  }
}

watch(() => route.query, () => { syncFromRoute(); load() }, { immediate: true })

// --- Export de la liste (tous les résultats filtrés)
const legFee = (t, p) => (t.costs?.legs || []).filter((l) => l.partner === p).reduce((a, l) => a + (l.fee_tx ?? l.fee ?? 0), 0)
const legKind = (l) => (l.kind === 'collect' ? 'collecte' : l.kind === 'payout' ? 'versement' : 'remboursement')
const EXP_COLS = [
  { label: 'ID interne', value: (t) => t.id },
  { label: 'Référence', value: (t) => t.reference },
  { label: 'Statut', value: (t) => STATUS[t.status] || t.status },
  { label: 'Étape', value: (t) => t.stage },
  { label: 'Opération', value: (t) => t.journal?.label },
  { label: 'Type', value: (t) => t.type },
  { label: 'Canal', value: (t) => t.channel_label },
  { label: 'Expéditeur', value: (t) => t.sender?.name },
  { label: 'Compte expéditeur', value: (t) => t.sender?.account || t.source_rail },
  { label: 'Moyen débité', value: (t) => t.source_rail },
  { label: 'Opérateur débité', value: (t) => opName(t.gateway?.in) },
  { label: 'Bénéficiaire', value: (t) => t.beneficiary?.name },
  { label: 'Compte bénéficiaire', value: (t) => t.beneficiary?.account || t.destination_rail },
  { label: 'Moyen crédité', value: (t) => t.destination_rail },
  { label: 'Opérateur crédité', value: (t) => opName(t.gateway?.out) },
  { label: 'Service', value: (t) => svc(t) },
  { label: 'Passerelle', value: (t) => gateways(t).join(' + ') || 'Interne FlashPay' },
  { label: 'Track ID PEEX', value: (t) => (t.gateway?.track_ids || []).join(' ') },
  { label: 'Réf. externe source', value: (t) => t.source_external_ref },
  { label: 'Réf. externe destination', value: (t) => t.destination_external_ref },
  { label: 'Montant', value: (t) => t.amount },
  { label: 'Devise', value: (t) => t.currency },
  { label: 'Frais client', value: (t) => t.fee || 0 },
  { label: 'Commission marchand', value: (t) => t.merchant_fee || 0 },
  { label: 'Frais facturés FlashPay', value: (t) => t.costs?.billed ?? t.fee },
  { label: 'Frais PEEX', value: (t) => legFee(t, 'PEEX') },
  { label: 'Frais WacePay', value: (t) => legFee(t, 'WacePay') },
  { label: 'Frais partenaires (total)', value: (t) => t.costs?.partner_total ?? 0 },
  { label: 'Origine frais partenaires', value: (t) => (!t.costs?.legs?.length ? 'Aucun (interne)' : isEstimate(t) ? 'Estimé (tarifs partenaires)' : t.costs.complete ? 'Communiqué par le partenaire' : 'Partiel') },
  { label: 'Détail frais partenaires', value: (t) => legsTxt(t, (l) => `${l.partner} ${legKind(l)}${l.country ? ' ' + l.country : ''} : ${l.fee === null ? 'n.c.' : l.fee + ' ' + l.currency}${l.fee_source === 'estimate' ? ' (estimé)' : ''}`) },
  { label: 'Gain FlashPay', value: (t) => t.costs?.margin ?? '' },
  { label: 'Montant reçu', value: (t) => t.destination_amount ?? t.amount },
  { label: 'Net bénéficiaire', value: (t) => netOf(t) },
  { label: 'Devise reçue', value: (t) => t.destination_currency || t.currency },
  { label: 'Motif échec', value: (t) => t.failure_reason },
  { label: 'Date', value: (t) => fmtDate(t.created_at) },
  { label: 'Finalisée le', value: (t) => fmtDate(t.completed_at) },
]
const expFetch = (onP) => fetchAllPages('/admin/transactions', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), onP)
</script>

<style scoped>
/* Style 2026 : dense, lisible, sans défilement horizontal */
.wp, .wp-modal-bg { --line: #eceef2; --soft: #f7f8fa; --t2: #6b7280; --t3: #9ca3af; --accent: #ea7a1a; color: #111827; font-size: 13px; }
.wp-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; }
.wp-head h1 { margin: 0; font-size: 18px; font-weight: 700; letter-spacing: -.2px; }
.wp-head p { margin: 2px 0 0; color: var(--t2); font-size: 12.5px; }
.wp-hbtn { display: flex; gap: 6px; }
.wp-hbtn :is(a, button) { font-size: 12.5px; padding: 6px 12px; }
.wp-cur { display: flex; gap: 4px; margin-bottom: 8px; }
.wp-cur button { border: 0; background: none; font-weight: 700; font-size: 12px; color: var(--t2); cursor: pointer; padding: 2px 4px; }
.wp-cur button.on { color: #111827; box-shadow: inset 0 -2px 0 var(--accent); }

/* Cartes de synthèse : une seule ligne */
.wp-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; margin-bottom: 12px; }
.wp-card { display: flex; gap: 10px; align-items: center; background: #fff; border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; min-width: 0; transition: border-color .15s; }
.wp-card:nth-child(-n+3) { cursor: pointer; } .wp-card:nth-child(-n+3):hover { border-color: #c7d2fe; }
.wp-card > div { min-width: 0; }
.wp-card small { display: block; color: var(--t2); font-size: 11.5px; }
.wp-card b { display: block; font-size: 17px; font-weight: 700; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; letter-spacing: -.2px; }
.wp-card b i { font-style: normal; font-size: 11px; font-weight: 500; color: var(--t2); }
.wp-card em { display: block; font-style: normal; color: var(--t3); font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ic { flex: none; width: 32px; height: 32px; border-radius: 9px; display: grid; place-items: center; }
.ic svg { width: 17px; height: 17px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.ic.ok { background: #dcfce7; color: #16a34a; } .ic.pend { background: #fef9c3; color: #ca8a04; } .ic.ko { background: #fee2e2; color: #dc2626; }
.ic.fee { background: #ffedd5; color: var(--accent); } .ic.rate { background: #dbeafe; color: #2563eb; } .ic.send { background: #eff6ff; color: #2563eb; }

/* Filtres : une ligne compacte */
.wp-box { background: #fff; border: 1px solid var(--line); border-radius: 12px; margin-bottom: 12px; }
.wp-box.flush { overflow: hidden; }
.wp-filters { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) minmax(0, 2fr) auto; gap: 8px; padding: 10px 12px; align-items: end; }
.wp-filters label { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.wp-filters label span { font-size: 11px; font-weight: 600; color: var(--t2); text-transform: uppercase; letter-spacing: .3px; }
.wp-filters select, .wp-filters input { height: 32px; padding: 0 8px; border: 1px solid #dfe3e8; border-radius: 7px; font: inherit; font-size: 12.5px; background: #fff; min-width: 0; width: 100%; }
.wp-filters .wide { grid-column: auto; }
.wp-actions { display: flex; gap: 6px; }
.wp-btn { display: inline-flex; align-items: center; gap: 5px; height: 32px; padding: 0 10px; border: 1px solid #dfe3e8; border-radius: 7px; background: #fff; font: inherit; font-weight: 600; font-size: 12px; color: #374151; cursor: pointer; text-decoration: none; white-space: nowrap; }
.wp-btn:hover { background: var(--soft); }
.wp-btn svg { width: 14px; height: 14px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.wp-btn.primary { border-color: #2563eb; color: #2563eb; }
.wp-btn:disabled { opacity: .55; cursor: default; }
.wp-btn.spin svg { animation: wp-spin .8s linear infinite; }
@keyframes wp-spin { to { transform: rotate(360deg); } }
.wp-refresh { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; margin: -4px 2px 8px; font-size: 11.5px; color: var(--t2); }
.wp-refresh label { display: inline-flex; gap: 5px; align-items: center; cursor: pointer; }
.wp-refresh .sel { color: #2563eb; font-weight: 600; } .wp-refresh .sel a { color: inherit; }
.c-chk { text-align: center; padding-left: 4px !important; padding-right: 0 !important; }
.c-chk input { cursor: pointer; vertical-align: middle; }
.wp-table tbody tr.sel td { background: #eff6ff; }

/* Tableau : largeur fixe, en-tête collant, défilement vertical interne */
.wp-table { max-height: calc(100vh - 330px); min-height: 260px; overflow: auto; }
.wp-table table { width: 100%; table-layout: fixed; border-collapse: separate; border-spacing: 0; font-size: 12.5px; }
.wp-table th { position: sticky; top: 0; z-index: 1; background: var(--soft); text-align: left; font-weight: 600; font-size: 11px; color: var(--t2); text-transform: uppercase; letter-spacing: .3px; padding: 8px 8px; border-bottom: 1px solid var(--line); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wp-table td { padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: middle; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wp-table tbody tr { cursor: pointer; }
.wp-table tbody tr:hover td { background: #f9fafb; }
.num { text-align: right; }
.muted { color: var(--t3); }
.est { color: var(--t3); font-size: 10px; margin-right: 1px; }
.kv.tot { border-top: 1px dashed var(--line); margin-top: 2px; padding-top: 7px; }
.net { color: #16a34a; } .pos { color: #16a34a; } .neg { color: #dc2626; } .unk { color: #b45309; }
.c-code b, .c-who b { display: block; overflow: hidden; text-overflow: ellipsis; font-weight: 600; font-size: 12.5px; }
.c-code b { font-size: 12px; letter-spacing: .2px; }
.c-code small, .c-who small { display: block; color: var(--t2); font-size: 11px; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
.c-svc em { font-style: normal; font-size: 10px; font-weight: 700; margin-right: 3px; padding: 1px 5px; border-radius: 4px; background: #e0e7ff; color: #1e3a8a; }
.c-svc em.p-wacepay { background: #ffedd5; color: #c2410c; }
.c-ops { font-size: 12px; } .c-ops .arr { color: var(--t3); margin: 0 2px; }
.c-dt { color: var(--t2); font-size: 11.5px; }
.c-eye { text-align: center; padding-left: 0 !important; padding-right: 0 !important; }
.c-eye { white-space: nowrap; }
.c-eye button { border: 0; background: none; padding: 3px; border-radius: 6px; cursor: pointer; line-height: 0; }
.c-eye button:hover { background: #eff6ff; }
.c-eye svg { width: 17px; height: 17px; fill: none; stroke: #2563eb; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; vertical-align: middle; }
.pill { display: inline-block; padding: 3px 8px; border-radius: 5px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; white-space: nowrap; }
.pill.sm { padding: 0 5px; font-size: 9.5px; border-radius: 4px; margin-right: 4px; line-height: 15px; }
.pill.successful { background: #dcfce7; color: #15803d; } .pill.processing { background: #fef9c3; color: #a16207; }
.pill.failed { background: #fee2e2; color: #b91c1c; } .pill.reversed { background: #e0e7ff; color: #3730a3; }
.empty { text-align: center; color: var(--t2); padding: 24px; }
.wp-pager { display: flex; justify-content: center; align-items: center; gap: 12px; padding: 8px; border-top: 1px solid var(--line); font-size: 12px; }
.wp-pager button { border: 0; background: none; font-size: 15px; color: #4b5563; cursor: pointer; padding: 2px 6px; border-radius: 6px; }
.wp-pager button:hover:not(:disabled) { background: var(--soft); }
.wp-pager button:disabled { opacity: .3; cursor: default; }

/* Fenêtre Détails */
.wp-modal-bg { position: fixed; inset: 0; background: rgba(17, 24, 39, .4); backdrop-filter: blur(2px); z-index: 200; display: flex; justify-content: center; align-items: flex-start; padding: 5vh 16px 16px; overflow-y: auto; }
.wp-modal { width: 100%; max-width: 520px; background: #fff; border-radius: 14px; padding: 18px 20px 16px; box-shadow: 0 20px 50px rgba(0, 0, 0, .2); font-size: 13px; }
.wp-mh { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.wp-mh .ic { width: 34px; height: 34px; }
.wp-mh b { display: block; font-size: 15px; } .wp-mh small { color: var(--t2); font-size: 11.5px; }
.wp-mh .x { margin-left: auto; border: 0; background: none; font-size: 22px; line-height: 1; color: var(--t2); cursor: pointer; align-self: flex-start; }
.wp-datebox { display: flex; justify-content: space-between; align-items: center; background: var(--soft); border-radius: 10px; padding: 10px 14px; margin-bottom: 10px; }
.wp-datebox small { display: block; color: var(--t2); font-size: 11px; } .wp-datebox b { font-size: 13.5px; font-weight: 600; }
.wp-sec { border: 1px solid var(--line); border-radius: 10px; padding: 10px 14px 4px; margin-bottom: 10px; }
.wp-sec h4 { margin: 0 0 4px; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--t2); }
.kv { display: flex; justify-content: space-between; gap: 12px; padding: 5px 0; font-size: 13px; }
.kv span { color: var(--t2); } .kv span small { color: var(--t3); font-size: 10.5px; } .kv b { font-weight: 600; text-align: right; }
.chk { border-top: 1px dashed var(--line); padding-top: 4px; } .chk:first-of-type { border-top: 0; }
.chk-msg { margin: 0 0 6px; color: #b45309; font-size: 12px; word-break: break-word; }
.chk details { margin-bottom: 6px; } .chk summary { cursor: pointer; color: #2563eb; font-size: 12px; }
.chk pre { max-height: 220px; overflow: auto; background: #0f172a; color: #e2e8f0; padding: 8px; border-radius: 8px; font-size: 11px; }
.reason { margin: 0 0 8px; color: #b91c1c; font-size: 12.5px; }
.wp-mf { display: flex; justify-content: flex-end; gap: 6px; }
.rate-list { display: flex; flex-direction: column; gap: 10px; font-size: 13px; max-height: 60vh; overflow: auto; }
.rate-block { border: 1px solid #eceef2; border-radius: 10px; padding: 8px 10px; }
.rate-row { display: grid; grid-template-columns: minmax(200px, 1fr) auto auto 90px; gap: 8px 12px; align-items: center; padding: 3px 0; }
.rate-row.sub { padding-left: 18px; }
.rate-row.sub select { padding: 5px 6px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.rate-row input { width: 80px !important; padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; }
.rate-hint { color: #9ca3af; font-size: 11.5px; }
.rate-x { border: 0; background: none; color: #dc2626; font-size: 18px; cursor: pointer; justify-self: start; }
.rate-add { margin: 4px 0 2px 18px; border: 0; background: none; color: #2563eb; font: inherit; font-size: 12px; cursor: pointer; padding: 0; }

@media (max-width: 1100px) { .wp-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); } .wp-filters .wide { grid-column: span 2; } .wp-actions { grid-column: span 3; justify-content: flex-end; } }
@media (max-width: 900px) { .wp-table table { width: 1040px; } }
@media (max-width: 640px) { .wp-filters { grid-template-columns: 1fr 1fr; } .wp-filters .wide, .wp-actions { grid-column: span 2; } }
</style>
