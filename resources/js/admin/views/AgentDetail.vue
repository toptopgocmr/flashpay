<template>
  <div>
    <div class="page-header">
      <div>
        <router-link to="/agents" class="back">‹ Agents</router-link>
        <h1>{{ a?.name || 'Agent' }}</h1>
        <p v-if="a"><span class="mono">{{ $phone(a.phone) }}</span> · <Flag :iso="a.country" /> {{ a.city || '—' }}<template v-if="a.zone"> · {{ a.zone }}</template> · agent depuis le {{ d(a.created_at) }}</p>
      </div>
      <div v-if="a" class="actions">
        <IconAction icon="edit" label="Modifier l'agent" @click="editing = true" />
        <IconAction v-if="a.validation_status === 'approved'" icon="fund" tone="accent" label="Approvisionner le float" @click="funding = true" />
        <IconAction icon="key" label="Réinitialiser le mot de passe" @click="resetPwd = true" />
        <WalletAdjust :user-id="a.user_id" :name="a.name" :balance="a.float" :currency="a.currency" @done="(m) => { msg = { type: 'info', text: m }; load() }" />
        <IconAction icon="crown" :tone="a.is_super_agent ? 'accent' : ''" :label="a.is_super_agent ? 'Retirer le statut super-agent' : 'Nommer super-agent'" @click="toggleSuper" />
        <IconAction :icon="a.wallet_status === 'frozen' ? 'sun' : 'snow'" :label="a.wallet_status === 'frozen' ? 'Dégeler le float' : 'Geler le float'" @click="freeze(a.wallet_status !== 'frozen')" />
        <IconAction v-if="a.validation_status !== 'approved'" icon="check" tone="ok" label="Valider l'agrément" @click="decide('approved')" />
        <IconAction v-else icon="ban" tone="danger" label="Suspendre l'agrément" @click="decide('rejected')" />
        <IconAction icon="power" :tone="a.active ? 'danger' : 'ok'" :label="a.active ? 'Désactiver le compte' : 'Activer le compte'" @click="toggling = true" />
      </div>
    </div>

    <div v-if="msg" class="flash" :class="msg.type"><div>{{ msg.text }}</div></div>
    <div v-if="a && !a.active" class="flash err"><div><strong>Compte désactivé</strong><template v-if="a.status_changed_at"> le {{ dt(a.status_changed_at) }}</template><template v-if="a.status_reason"> — motif : {{ a.status_reason }}</template></div></div>
    <div v-if="a && a.validation_status !== 'approved'" class="flash warn"><div><strong>Agrément {{ a.validation_status === 'pending' ? 'en attente' : 'suspendu' }} :</strong> l'agent ne peut pas encaisser de dépôts ni remettre de retraits.</div></div>
    <div v-if="a && a.wallet_status === 'frozen'" class="flash warn"><div><strong>Float gelé :</strong> l'agent ne peut plus créditer de clients (dépôts).</div></div>

    <template v-if="a">
      <section class="container mb">
        <div class="container-head">
          <div><h3>Performance</h3><p>Du {{ d(data.period.from) }} au {{ d(data.period.to) }}</p></div>
          <label class="date-range">
            <span>Du</span><input v-model="from" type="date" :max="to" @change="load" />
            <span>au</span><input v-model="to" type="date" :min="from" :max="today" @change="load" />
          </label>
        </div>
        <div class="container-body">
          <div class="cards">
            <div class="card-kpi" v-go="'/float-requests'" title="Demandes de float"><span>Float disponible</span><b>{{ money(a.float, a.currency) }}</b><small><span class="status" :class="a.wallet_status === 'frozen' ? 'err' : 'ok'">{{ a.wallet_status === 'frozen' ? 'Gelé' : 'Actif' }}</span></small></div>
            <div class="card-kpi" v-go="'#agent-profile'" title="Voir le profil"><span>Identifiant agent</span><b class="mono">{{ a.agent_code || '—' }}</b><small>PV {{ a.pos_code }} · {{ a.is_super_agent ? `Super-agent (${a.sub_agents} sous-agents)` : (a.parent_name ? 'Rattaché à ' + a.parent_name : 'Agent') }}<span v-if="a.pending_float_requests"> · {{ a.pending_float_requests }} demande(s) de float</span></small></div>
            <div class="card-kpi" v-go="{ path: '/transactions', query: { user: a.user_id, channel: 'deposit_agent' } }" title="Voir les dépôts"><span>Dépôts cash</span><b>{{ n(p.deposits.count) }}</b><small>{{ money(p.deposits.volume, a.currency) }}</small></div>
            <div class="card-kpi" v-go="{ path: '/transactions', query: { user: a.user_id, channel: 'withdrawal_wallet' } }" title="Voir les retraits wallet"><span>Retraits wallet</span><b>{{ n(p.withdrawals_wallet.count) }}</b><small>{{ money(p.withdrawals_wallet.volume, a.currency) }}</small></div>
            <div class="card-kpi" v-go="{ path: '/transactions', query: { user: a.user_id, channel: 'withdrawal_qr' } }" title="Voir les retraits QR"><span>Retraits QR code</span><b>{{ n(p.withdrawals_qr.count) }}</b><small>{{ money(p.withdrawals_qr.volume, a.currency) }}</small></div>
            <div class="card-kpi" v-go="'#agent-ops'" title="Voir toutes les opérations"><span>Commissions gagnées</span><b>{{ money(p.commission, a.currency) }}</b><small>{{ n(p.clients_served) }} client(s) servi(s)</small></div>
            <div class="card-kpi" v-go="'/float-requests'" title="Voir les approvisionnements"><span>Approvisionnements</span><b>{{ n(p.float_topups.count) }}</b><small>{{ money(p.float_topups.volume, a.currency) }}</small></div>
          </div>
        </div>
      </section>

      <div class="two-col mb">
        <section id="agent-profile" class="container">
          <div class="container-head"><h3>Profil</h3></div>
          <div class="container-body flush">
            <table class="info"><tbody>
              <tr><td>Nom</td><td>{{ a.name }}</td></tr>
              <tr><td>Téléphone</td><td class="mono">{{ $phone(a.phone) }}</td></tr>
              <tr><td>E-mail</td><td>{{ a.email || '—' }}</td></tr>
              <tr><td>Localisation</td><td><Flag :iso="a.country" /> {{ a.city || '—' }} · {{ a.zone || '—' }}</td></tr>
              <tr><td>Agrément</td><td><span class="status" :class="{ approved: 'ok', pending: 'warn', rejected: 'err' }[a.validation_status]">{{ { approved: 'Validé', pending: 'En attente', rejected: 'Suspendu' }[a.validation_status] }}</span></td></tr>
              <tr><td>Compte</td><td><span class="status" :class="a.active ? 'ok' : 'err'">{{ a.active ? 'Actif' : 'Désactivé' }}</span></td></tr>
            </tbody></table>
          </div>
        </section>
        <section id="agent-ops" class="container">
          <div class="container-head"><h3>Toutes opérations</h3></div>
          <div class="container-body flush">
            <table class="info"><tbody>
              <tr><td>Opérations (total)</td><td>{{ n(s.count) }}</td></tr>
              <tr><td>Réussies / échouées</td><td>{{ n(s.successful) }} / {{ n(s.failed) }}</td></tr>
              <tr><td>Rejetées / en attente</td><td>{{ n(s.reversed) }} / {{ n(s.processing) }}</td></tr>
              <tr><td>Taux de réussite</td><td>{{ s.success_rate == null ? '—' : String(s.success_rate).replace('.', ',') + ' %' }}</td></tr>
              <tr><td>Dernière activité</td><td>{{ s.last_activity ? dt(s.last_activity) : '—' }}</td></tr>
            </tbody></table>
          </div>
        </section>
      </div>

      <section class="container">
        <div class="container-head">
          <h3>Dernières opérations <span class="counter">({{ recent.length }})</span></h3>
          <router-link class="btn-normal" :to="{ path: '/transactions', query: { user: a.user_id } }">Toutes ses transactions ›</router-link>
        </div>
        <div class="container-body flush" style="overflow-x: auto;">
          <table>
            <thead><tr><th>Référence</th><th>Opération</th><th class="num">Montant</th><th>Statut</th><th>Date</th></tr></thead>
            <tbody>
              <tr v-for="t in recent" :key="t.id" class="clickable" @click="$router.push('/transactions/' + t.id)">
                <td class="mono">{{ t.reference }}</td><td>{{ t.channel_label }}</td><td class="num">{{ money(t.amount, t.currency) }}</td>
                <td><span class="status" :class="{ successful: 'ok', failed: 'err', reversed: 'warn', processing: 'pending' }[t.status]">{{ { successful: 'Réussie', failed: 'Échouée', reversed: 'Rejetée', processing: 'En attente' }[t.status] }}</span></td>
                <td>{{ dt(t.created_at) }}</td>
              </tr>
              <tr v-if="!recent.length"><td colspan="5" class="stat-label">Aucune opération.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
    <div v-else-if="loading" class="skeleton" style="height: 300px;"></div>

    <PersonForm v-if="editing" title="Modifier l'agent" :subtitle="a.name" :endpoint="'/admin/agents/' + a.id" with-agent
      :initial="{ full_name: a.name, phone: a.phone, email: a.email, country: a.country, city: a.city, zone: a.zone }" @close="editing = false" @saved="(r) => { msg = { type: 'info', text: r.message }; load() }" />
    <AccountStatusModal v-if="toggling" :user-id="a.user_id" :name="a.name" :phone="a.phone" :active="!a.active" @close="toggling = false" @done="(m) => { msg = { type: 'info', text: m }; load() }" />
    <PasswordReset v-if="resetPwd" :user-id="a.user_id" :name="a.name" :phone="a.phone" @close="resetPwd = false" />
    <Modal v-if="funding" title="Approvisionner le float" :subtitle="a.name + ' · solde actuel ' + money(a.float, a.currency)" @close="funding = false">
      <div><label class="field">Montant</label><input v-model.number="fundAmount" type="number" min="100" step="100" placeholder="Ex. 500000" /></div>
      <div><label class="field">Référence du versement (facultatif)</label><input v-model.trim="fundNote" placeholder="Ex. reçu n° 1234" /></div>
      <template #foot>
        <button class="btn-normal" @click="funding = false">Annuler</button>
        <button class="btn" :disabled="!fundAmount || fundAmount < 100" @click="fund">Créditer</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { confirmBox } from '../utils/ui'
import IconAction from '../components/IconAction.vue'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import Flag from '../components/Flag.vue'
import Modal from '../components/Modal.vue'
import PersonForm from '../components/PersonForm.vue'
import AccountStatusModal from '../components/AccountStatusModal.vue'
import PasswordReset from '../components/PasswordReset.vue'
import WalletAdjust from '../components/WalletAdjust.vue'

const route = useRoute()
const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
const data = ref(null)
const loading = ref(false)
const msg = ref(null)
const editing = ref(false)
const toggling = ref(false)
const resetPwd = ref(false)
const funding = ref(false)
const fundAmount = ref(null)
const fundNote = ref('')
const from = ref('')
const to = ref('')
const a = computed(() => data.value?.agent)
const p = computed(() => data.value?.performance)
const s = computed(() => data.value?.stats || {})
const recent = computed(() => data.value?.recent || [])

const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => (v == null ? '—' : nf.format(v))
const money = (v, cur) => nf.format(v || 0) + ' ' + (cur || 'XAF')
const d = (x) => (x ? new Date(x.length === 10 ? x + 'T00:00:00' : x).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }) : '—')
const dt = (x) => new Date(x).toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' })

async function toggleSuper() {
  act(() => api.post(`/admin/agents/${a.value.id}/hierarchy`, { is_super_agent: !a.value.is_super_agent }), a.value.is_super_agent ? 'Statut super-agent retiré.' : 'Agent nommé super-agent : il peut approvisionner ses sous-agents.')
}
async function load() {
  loading.value = true
  try {
    const params = { ...(from.value ? { from: from.value } : {}), ...(to.value ? { to: to.value } : {}) }
    data.value = (await api.get('/admin/agents/' + route.params.id, { params })).data
    from.value = data.value.period.from
    to.value = data.value.period.to
  } catch (e) {
    msg.value = { type: 'err', text: e.response?.data?.message || e.message }
  } finally {
    loading.value = false
  }
}
async function act(fn, ok) {
  try { const { data: r } = await fn(); msg.value = { type: 'info', text: r?.message || ok }; await load() } catch (e) { msg.value = { type: 'err', text: e.response?.data?.message || e.message } }
}
async function decide(decision) {
  if (decision === 'rejected' && !(await confirmBox("L'agent ne pourra plus faire de dépôts ni de retraits tant que l'agrément est suspendu.", { title: "Suspendre l'agrément ?", confirmLabel: 'Suspendre', danger: true }))) return
  act(() => api.post(`/admin/agents/${a.value.id}/validate`, { decision }), decision === 'approved' ? 'Agrément validé.' : 'Agrément suspendu.')
}
async function freeze(frozen) {
  if (frozen && !(await confirmBox('Le float sera bloqué : aucune opération ne sera possible jusqu\'au dégel.', { title: 'Geler le float de cet agent ?', confirmLabel: 'Geler', danger: true }))) return
  act(() => api.post(`/admin/wallets/${a.value.user_id}/status`, { frozen }))
}
async function fund() {
  await act(() => api.post(`/admin/agents/${a.value.id}/float`, { amount: fundAmount.value, note: fundNote.value || undefined }), 'Float approvisionné.')
  funding.value = false; fundAmount.value = null; fundNote.value = ''
}

watch(() => route.params.id, (id) => { if (id) { from.value = ''; to.value = ''; load() } }, { immediate: true })
</script>

<style scoped>
.back { font-size: 13px; color: var(--link); text-decoration: none; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; }
.card-kpi { border: 1px solid var(--border); border-radius: 10px; padding: 12px 14px; display: grid; gap: 4px; }
.card-kpi span { color: var(--text-2); font-size: 12.5px; }
.card-kpi b { font-size: 20px; }
.card-kpi small { color: var(--text-2); font-size: 12px; }
.two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px; }
.two-col .container { margin: 0; }
.info { width: 100%; }
.info td:first-child { color: var(--text-2); width: 45%; }
.date-range { display: inline-flex; align-items: center; gap: 6px; }
.date-range span { color: var(--text-2); font-size: 13px; }
.date-range input { padding: 7px 10px; border: 1px solid var(--border-strong); border-radius: 8px; font: inherit; }
tr.clickable { cursor: pointer; }
tr.clickable:hover td { background: var(--surface-2); }
@media (max-width: 520px) { .two-col { grid-template-columns: 1fr; } }
</style>
