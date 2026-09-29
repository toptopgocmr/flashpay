<template>
  <div>
    <div class="page-header">
      <div><h1>E-commerce & API</h1><p>Clés API actives des marchands, derniers paiements en ligne (payment intents) et webhooks en échec (§4.7). Documentation d'intégration : <a href="/docs/api-ecommerce" target="_blank">/docs/api-ecommerce</a>.</p></div>
      <div class="actions">
        <ExportButton label="Exporter les intégrations" filename="integrations-ecommerce" :columns="EXP_COLS" :fetch="expFetch" /><button class="btn-normal" @click="load">Actualiser</button></div>
    </div>
    <div class="grid grid-3 mb">
      <div class="card" v-go="'#eco-keys'"><div class="stat-label">Clés actives</div><div class="stat-value">{{ d?.keys.length ?? '—' }}</div></div>
      <div class="card" v-go="'#eco-webhooks'"><div class="stat-label">Webhooks en attente de nouvel essai</div><div class="stat-value">{{ d?.webhooks_pending ?? '—' }}</div></div>
      <div class="card" v-go="'#eco-webhooks'"><div class="stat-label">Webhooks en échec définitif</div><div class="stat-value">{{ d?.webhooks_failed.length ?? '—' }}</div></div>
    </div>
    <section class="container mb">
      <div class="container-head"><h3>Suivi des intégrations <span class="counter">({{ integ?.data.length ?? 0 }})</span></h3></div>
      <div class="container-body flush" style="overflow-x:auto;"><table>
        <thead><tr><th>Marchand</th><th>Progression (étapes obligatoires)</th><th>Statut</th><th>Dernier appel sandbox</th><th>Dernier webhook</th><th></th></tr></thead>
        <tbody><tr v-for="r in integ?.data || []" :key="r.merchant_id">
          <td><strong>{{ r.merchant }}</strong><br /><small class="mono" style="color:var(--text-2)">{{ $phone(r.phone) }}</small></td>
          <td style="min-width:260px">
            <div class="steps">
              <span v-for="st in r.steps.filter((x) => x.required)" :key="st.key" class="step" :class="{ done: st.done }" :data-tip="st.label">{{ st.done ? '✓' : '' }}</span>
              <b style="margin-left:8px">{{ r.progress.done }}/{{ r.progress.total }}</b>
            </div>
          </td>
          <td><span class="status" :class="TONE[r.status]">{{ r.status_label }}</span><br /><small v-if="r.validated_at" style="color:var(--text-2)">validée {{ r.validated_by === 'admin' ? 'manuellement' : 'automatiquement' }} · {{ date(r.validated_at) }}</small></td>
          <td>{{ date(r.sandbox_last_call) }}</td>
          <td><template v-if="r.last_webhook"><span class="status" :class="r.last_webhook.status === 'delivered' ? 'ok' : r.last_webhook.status === 'failed' ? 'err' : 'warn'">{{ r.last_webhook.status === 'delivered' ? 'Reçu' : r.last_webhook.status === 'failed' ? 'Échec' : 'Nouvel essai' }}</span><br /><small class="mono">{{ r.last_webhook.event }} · {{ r.last_webhook.code || r.last_webhook.error }}</small></template><span v-else>—</span></td>
          <td class="actions-cell">
            <IconAction icon="eye" label="Voir le détail de l'intégration" @click="openDetail(r)" />
            <IconAction v-if="!['sandbox_validated', 'live_pending', 'live'].includes(r.status)" icon="check" tone="ok" label="Valider manuellement l'intégration" @click="validate(r, 'validate')" />
          </td>
        </tr></tbody>
      </table>
      <div v-if="integ && !integ.data.length" class="empty"><strong>Aucune intégration en cours</strong>Dès qu'un marchand génère ses clés (app › Paiement en ligne), son intégration est suivie ici étape par étape.</div></div>
    </section>

    <Modal v-if="detail" :title="'Intégration · ' + detail.merchant" :subtitle="detail.status_label" @close="detail = null">
      <div class="checklist">
        <div v-for="st in detail.steps" :key="st.key" class="ck" :class="{ done: st.done }">
          <span class="step" :class="{ done: st.done }">{{ st.done ? '✓' : '' }}</span>
          <div><strong>{{ st.label }}</strong> <small v-if="!st.required">(recommandé)</small><div class="hint" style="margin:0">{{ st.help }}</div></div>
        </div>
      </div>
      <div><strong>Clés</strong>
        <div v-for="k in detail.keys" :key="k.id" class="hint" style="margin:2px 0">{{ k.environment }} · <span class="mono">{{ k.public_key }}</span> · sk_…{{ k.secret_last4 }} · {{ k.active ? 'active' : 'révoquée' }} · dernier appel {{ date(k.last_used_at) }}<br />webhook : <span class="mono">{{ k.webhook_url || 'non déclaré' }}</span></div>
      </div>
      <div><strong>Derniers webhooks</strong>
        <div v-if="!detail.recent_webhooks.length" class="hint">Aucun envoi.</div>
        <div v-for="w in detail.recent_webhooks" :key="w.id" class="hint" style="margin:2px 0"><span class="status" :class="w.status === 'delivered' ? 'ok' : w.status === 'failed' ? 'err' : 'warn'">{{ w.status }}</span> <span class="mono">{{ w.event }}</span> · {{ w.attempts }} essai(s) · {{ w.last_response_code || w.last_error || '' }} · {{ date(w.created_at) }}</div>
      </div>
      <div><strong>Derniers paiements</strong>
        <div v-if="!detail.recent_intents.length" class="hint">Aucun paiement créé.</div>
        <div v-for="p in detail.recent_intents" :key="p.id" class="hint" style="margin:2px 0">{{ p.environment }} · <span class="mono">{{ p.public_id }}</span> · {{ money(p.amount, p.currency) }} · <b>{{ p.status }}</b> {{ p.failure_reason || '' }} · {{ date(p.created_at) }}</div>
      </div>
      <template #foot>
        <button v-if="detail.validated_at" class="btn-normal" @click="validate(detail, 'revoke')">Annuler la validation</button>
        <button v-else class="btn" @click="validate(detail, 'validate')">Valider manuellement</button>
        <button class="btn-normal" @click="detail = null">Fermer</button>
      </template>
    </Modal>

    <section id="eco-keys" class="container mb">
      <div class="container-head"><h3>Clés API</h3></div>
      <div class="container-body flush"><table>
        <thead><tr><th>Marchand</th><th>Env.</th><th>Clé publique</th><th>Secrète</th><th>Webhook</th><th>Dernier appel</th><th></th></tr></thead>
        <tbody><tr v-for="k in d?.keys || []" :key="k.id">
          <td>{{ k.merchant }}</td><td><span class="status" :class="k.environment === 'live' ? 'ok' : 'warn'">{{ k.environment }}</span></td>
          <td class="mono">{{ k.public_key }}</td><td class="mono">sk_…{{ k.secret_last4 }}</td><td class="mono" style="font-size:12px">{{ k.webhook_url || '—' }}</td>
          <td>{{ date(k.last_used_at) }}</td><td class="actions-cell"><IconAction icon="ban" tone="danger" label="Révoquer la clé" @click="revoke(k)" /></td>
        </tr></tbody>
      </table></div>
    </section>
    <section class="container mb">
      <div class="container-head"><h3>Derniers paiements en ligne</h3></div>
      <div class="container-body flush"><table>
        <thead><tr><th>ID</th><th>Marchand</th><th>Commande</th><th class="num">Montant</th><th>Env.</th><th>Statut</th><th>Créé</th></tr></thead>
        <tbody><tr v-for="p in d?.intents || []" :key="p.id">
          <td class="mono">{{ p.public_id }}</td><td>{{ p.merchant?.business_name }}</td><td>{{ p.order_reference }}</td>
          <td class="num">{{ money(p.amount, p.currency) }}<small v-if="p.amount_refunded"><br />remb. {{ money(p.amount_refunded, p.currency) }}</small></td>
          <td>{{ p.environment }}</td><td><span class="status" :class="p.status === 'succeeded' ? 'ok' : ['failed', 'expired', 'canceled'].includes(p.status) ? 'err' : 'pending'">{{ p.status }}</span></td><td>{{ date(p.created_at) }}</td>
        </tr></tbody>
      </table></div>
    </section>
    <section id="eco-webhooks" class="container" v-if="d?.webhooks_failed.length">
      <div class="container-head"><h3>Webhooks en échec</h3></div>
      <div class="container-body flush"><table>
        <thead><tr><th>Événement</th><th>URL</th><th>Tentatives</th><th>Erreur</th><th></th></tr></thead>
        <tbody><tr v-for="w in d.webhooks_failed" :key="w.id"><td class="mono">{{ w.event }}</td><td class="mono" style="font-size:12px">{{ w.url }}</td><td>{{ w.attempts }}</td><td>{{ w.last_error }}</td><td class="actions-cell"><IconAction icon="refresh" label="Relancer le webhook" @click="retry(w)" /></td></tr></tbody>
      </table></div>
    </section>
  </div>
</template>

<script setup>
import ExportButton from '../components/ExportButton.vue'
import { fetchAllPages, fmtDate, fmtPhone } from '../utils/export'
import IconAction from '../components/IconAction.vue'
import { onMounted, ref } from 'vue'
import api from '../services/api'
import { money, date } from '../utils/format'
import Modal from '../components/Modal.vue'

const d = ref(null)
const integ = ref(null)
const detail = ref(null)
const TONE = { not_started: 'muted', in_progress: 'warn', sandbox_validated: 'ok', live_pending: 'pending', live: 'ok' }
async function load() {
  d.value = (await api.get('/admin/ecommerce')).data
  integ.value = (await api.get('/admin/ecommerce/integrations')).data
}
async function openDetail(r) { detail.value = (await api.get(`/admin/ecommerce/integrations/${r.merchant_id}`)).data }
async function validate(r, action) {
  const note = prompt(action === 'validate' ? 'Note (ex. intégration vérifiée avec le développeur du marchand)' : 'Motif de l\'annulation') ?? ''
  await api.post(`/admin/ecommerce/integrations/${r.merchant_id}`, { action, note })
  detail.value = null
  load()
}
async function revoke(k) {
  if (!confirm(`Révoquer la clé ${k.public_key} de ${k.merchant} ? Les appels API seront refusés immédiatement.`)) return
  await api.post(`/admin/api-keys/${k.id}/revoke`)
  load()
}
async function retry(w) { await api.post(`/admin/webhooks/${w.id}/retry`); load() }
onMounted(load)

// --- Export de la liste (tous les résultats filtrés)
const EXP_COLS = [
  { label: 'Marchand', value: (r) => r.merchant },
  { label: 'Téléphone', value: (r) => fmtPhone(r.phone) },
  { label: 'Statut', value: (r) => r.status_label },
  { label: 'Étapes obligatoires', value: (r) => r.progress.done + '/' + r.progress.total },
  { label: 'Étapes manquantes', value: (r) => r.steps.filter((s) => s.required && !s.done).map((s) => s.label).join(', ') },
  { label: 'Validée le', value: (r) => fmtDate(r.validated_at) },
  { label: 'Validation', value: (r) => (r.validated_by === 'admin' ? 'Manuelle' : r.validated_by === 'auto' ? 'Automatique' : '') },
  { label: 'En production le', value: (r) => fmtDate(r.live_at) },
  { label: 'Dernier appel sandbox', value: (r) => fmtDate(r.sandbox_last_call) },
  { label: 'Dernier appel production', value: (r) => fmtDate(r.live_last_call) },
  { label: 'URL webhook', value: (r) => r.webhook_url },
  { label: 'Paiements sandbox', value: (r) => r.intents.sandbox },
  { label: 'Paiements production', value: (r) => r.intents.live },
]
const expFetch = async () => (await api.get('/admin/ecommerce/integrations')).data.data
</script>

<style>
.steps { display: flex; align-items: center; gap: 4px; }
.step { width: 22px; height: 22px; border-radius: 50%; border: 2px solid var(--border); display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #fff; position: relative; flex: none; }
.step.done { background: var(--ok); border-color: var(--ok); }
.step[data-tip]:hover::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #0f172a; color: #fff; font-size: 12px; font-weight: 500; padding: 4px 8px; border-radius: 6px; white-space: nowrap; z-index: 20; }
.checklist { display: grid; gap: 8px; }
.ck { display: flex; gap: 10px; align-items: flex-start; }
</style>
