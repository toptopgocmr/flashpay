<template>
  <div>
    <div class="page-header">
      <div>
        <router-link to="/clients" class="back">‹ Clients</router-link>
        <h1>{{ c?.full_name || 'Client' }}</h1>
        <p v-if="c">
          <span class="mono">{{ $phone(c.phone) }}</span><template v-if="c.email"> · {{ c.email }}</template> · client depuis le {{ d(c.created_at) }}
        </p>
      </div>
      <div v-if="c" class="actions">
        <IconAction icon="key" label="Réinitialiser le mot de passe" @click="resetPwd = true" />
        <WalletAdjust :user-id="c.id" :name="c.full_name" :balance="c.balance" :currency="c.currency" @done="(m) => { msg = { type: 'info', text: m }; load() }" />
        <IconAction :icon="c.wallet_status === 'frozen' ? 'sun' : 'snow'" :label="c.wallet_status === 'frozen' ? 'Dégeler le wallet' : 'Geler le wallet'" @click="freeze(c.wallet_status !== 'frozen')" />
        <IconAction icon="power" :tone="c.active ? 'danger' : 'ok'" :label="c.active ? 'Désactiver le compte' : 'Activer le compte'" @click="toggling = true" />
      </div>
    </div>

    <div v-if="msg" class="flash" :class="msg.type"><div>{{ msg.text }}</div></div>
    <div v-if="c && !c.active" class="flash err"><div><strong>Compte désactivé</strong><template v-if="c.status_changed_at"> le {{ dt(c.status_changed_at) }}</template><template v-if="c.status_changed_by"> par {{ c.status_changed_by }}</template><template v-if="c.status_reason"> — motif : {{ c.status_reason }}</template></div></div>
    <div v-if="c && c.wallet_status === 'frozen'" class="flash warn"><div><strong>Wallet gelé :</strong> le client peut recevoir de l'argent mais aucun débit (envoi, paiement, retrait) n'est possible.</div></div>

    <template v-if="c">
      <div class="cards mb">
        <div class="card-kpi"><span>Solde du wallet</span><b>{{ money(c.balance, c.currency) }}</b><small><span class="status" :class="c.wallet_status === 'frozen' ? 'err' : 'ok'">{{ c.wallet_status === 'frozen' ? 'Gelé' : 'Actif' }}</span></small></div>
        <div class="card-kpi"><span>Opérations</span><b>{{ n(s.count) }}</b><small>{{ n(s.successful) }} réussies · {{ n(s.failed) }} échouées · {{ n(s.reversed) }} rejetées · {{ n(s.processing) }} en attente</small></div>
        <div class="card-kpi"><span>Taux de réussite</span><b :class="rateCls(s.success_rate)">{{ pct(s.success_rate) }}</b><small>Frais payés : {{ money(s.fees_paid) }}</small></div>
        <div class="card-kpi"><span>Envoyé / reçu</span><b>{{ short(s.volume_out) }} / {{ short(s.volume_in) }}</b><small>Dernière activité : {{ s.last_activity ? dt(s.last_activity) : '—' }}</small></div>
      </div>

      <div class="two-col mb">
        <section class="container">
          <div class="container-head"><h3>Profil</h3></div>
          <div class="container-body flush">
            <table class="info">
              <tbody>
                <tr><td>Nom</td><td>{{ c.full_name }}</td></tr>
                <tr><td>Téléphone</td><td class="mono">{{ $phone(c.phone) }}</td></tr>
                <tr><td>E-mail</td><td>{{ c.email || '—' }}</td></tr>
                <tr><td>Pays / devise</td><td>{{ c.country || '—' }} · {{ c.currency }}</td></tr>
                <tr><td>Statut du compte</td><td><span class="status" :class="c.active ? 'ok' : 'err'">{{ c.active ? 'Actif' : 'Désactivé' }}</span></td></tr>
                <tr v-if="c.other_profiles?.length"><td>Autres profils</td><td>{{ c.other_profiles.map((r) => ROLES[r] || r).join(', ') }}</td></tr>
                <tr>
                  <td>KYC</td>
                  <td>
                    <span class="status" :class="KYC[c.kyc_status]?.cls">{{ KYC[c.kyc_status]?.label }}</span>
                    <span class="kyc-actions">
                      <IconAction v-if="c.kyc_status !== 'verified'" icon="check" tone="ok" label="Valider le KYC" @click="kyc('verified')" />
                      <IconAction v-if="c.kyc_status !== 'rejected'" icon="ban" tone="danger" label="Rejeter le KYC" @click="kyc('rejected')" />
                      <IconAction v-if="c.kyc_status === 'verified' || c.kyc_status === 'rejected'" icon="undo" label="Remettre en attente" @click="kyc('pending')" />
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="container">
          <div class="container-head"><h3>Utilisation par canal</h3></div>
          <div class="container-body flush">
            <table class="info">
              <thead><tr><th>Canal</th><th class="num">Nb</th><th class="num">Volume réussi</th></tr></thead>
              <tbody>
                <tr v-for="ch in s.by_channel" :key="ch.key">
                  <td><router-link :to="{ path: '/transactions', query: { user: c.id, channel: ch.key } }">{{ ch.label }}</router-link></td>
                  <td class="num">{{ n(ch.count) }}</td><td class="num">{{ money(ch.volume, c.currency) }}</td>
                </tr>
                <tr v-if="!s.by_channel.length"><td colspan="3" class="stat-label">Aucune opération pour l'instant.</td></tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <section class="container">
        <div class="container-head">
          <h3>Dernières transactions <span class="counter">({{ recent.length }})</span></h3>
          <router-link class="btn-normal" :to="{ path: '/transactions', query: { user: c.id } }">Toutes ses transactions ›</router-link>
        </div>
        <div class="container-body flush" style="overflow-x: auto;">
          <table>
            <thead><tr><th>Référence</th><th>Canal</th><th>Sens</th><th class="num">Montant</th><th>Statut</th><th>Date</th></tr></thead>
            <tbody>
              <tr v-for="t in recent" :key="t.id" class="clickable" @click="$router.push('/transactions/' + t.id)">
                <td class="mono">{{ t.reference }}</td>
                <td>{{ t.channel_label }}</td>
                <td><span :class="t.direction === 'out' ? 't-err' : 't-ok'">{{ t.direction === 'out' ? '↑ Débit' : '↓ Crédit' }}</span></td>
                <td class="num">{{ money(t.amount, t.currency) }}</td>
                <td><span class="status" :class="STATUS[t.status]?.cls">{{ STATUS[t.status]?.label || t.status }}</span></td>
                <td>{{ dt(t.created_at) }}</td>
              </tr>
              <tr v-if="!recent.length"><td colspan="6" class="stat-label">Aucune transaction.</td></tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>
    <div v-else-if="loading" class="skeleton" style="height: 300px;"></div>

    <AccountStatusModal v-if="toggling" :user-id="c.id" :name="c.full_name" :phone="c.phone" :active="!c.active" @close="toggling = false" @done="(m) => { msg = { type: 'info', text: m }; load() }" />
    <PasswordReset v-if="resetPwd" :user-id="c.id" :name="c.full_name" :phone="c.phone" @close="resetPwd = false" />
  </div>
</template>

<script setup>
import IconAction from '../components/IconAction.vue'
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import AccountStatusModal from '../components/AccountStatusModal.vue'
import PasswordReset from '../components/PasswordReset.vue'
import WalletAdjust from '../components/WalletAdjust.vue'

const route = useRoute()
const data = ref(null)
const loading = ref(false)
const msg = ref(null)
const toggling = ref(false)
const resetPwd = ref(false)
const c = computed(() => data.value?.client)
const s = computed(() => data.value?.stats || {})
const recent = computed(() => data.value?.recent || [])

const ROLES = { merchant: 'Marchand', agent: 'Agent', support: 'Support', super_admin: 'Super Admin' }
const KYC = { pending: { label: 'Non fourni', cls: 'muted' }, submitted: { label: 'À vérifier', cls: 'pending' }, verified: { label: 'Validé', cls: 'ok' }, rejected: { label: 'Rejeté', cls: 'err' } }
const STATUS = { successful: { label: 'Réussie', cls: 'ok' }, failed: { label: 'Échouée', cls: 'err' }, reversed: { label: 'Rejetée', cls: 'warn' }, processing: { label: 'En attente', cls: 'pending' } }
const nf = new Intl.NumberFormat('fr-FR')
const n = (v) => (v == null ? '—' : nf.format(v))
const money = (v, cur) => nf.format(v || 0) + ' ' + (cur || 'XAF')
const short = (v) => (v == null ? '—' : v >= 1e6 ? (v / 1e6).toFixed(1).replace('.', ',') + ' M' : v >= 1e4 ? Math.round(v / 1e3) + ' k' : nf.format(v))
const pct = (v) => (v == null ? '—' : String(v).replace('.', ',') + ' %')
const rateCls = (v) => (v == null ? '' : v >= 90 ? 't-ok' : v >= 70 ? 't-warn' : 't-err')
const d = (s) => new Date(s).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' })
const dt = (s) => new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit' })

async function load() {
  loading.value = true
  try {
    data.value = (await api.get('/admin/clients/' + route.params.id)).data
  } catch (e) {
    msg.value = { type: 'err', text: e.response?.data?.message || e.message }
  } finally {
    loading.value = false
  }
}
async function act(fn) {
  try { const { data: r } = await fn(); msg.value = { type: 'info', text: r.message }; await load() } catch (e) { msg.value = { type: 'err', text: e.response?.data?.message || e.message } }
}
const kyc = (decision) => act(() => api.post(`/admin/clients/${c.value.id}/kyc`, { decision }))
function freeze(frozen) {
  if (frozen && !confirm('Geler le wallet ? Le client ne pourra plus envoyer, payer ni retirer.')) return
  act(() => api.post(`/admin/wallets/${c.value.id}/status`, { frozen }))
}

watch(() => route.params.id, (id) => { if (id) load() }, { immediate: true })
</script>

<style scoped>
.back { font-size: 13px; color: var(--link); text-decoration: none; }
.actions { display: flex; flex-wrap: wrap; gap: 8px; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
.card-kpi { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; box-shadow: var(--shadow); display: grid; gap: 4px; }
.card-kpi span { color: var(--text-2); font-size: 12.5px; }
.card-kpi b { font-size: 22px; }
.card-kpi small { color: var(--text-2); font-size: 12px; }
.two-col { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px; }
.two-col .container { margin: 0; }
.info { width: 100%; }
.info td:first-child { color: var(--text-2); width: 40%; }
.kyc-actions { display: inline-flex; gap: 6px; margin-left: 10px; }
tr.clickable { cursor: pointer; }
tr.clickable:hover td { background: var(--surface-2); }
.t-ok { color: var(--ok); } .t-warn { color: var(--warn); } .t-err { color: var(--err); }
@media (max-width: 520px) { .two-col { grid-template-columns: 1fr; } }
</style>
