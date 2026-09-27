<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Caissiers</h1>
        <p>Sous-comptes créés par les marchands pour encaisser à leur place (QR dynamique, lien de paiement). Aucun accès au solde ni aux retraits. FlashPay peut révoquer un accès à tout moment.</p>
      </div>
      <div class="actions">
        <ExportButton filename="caissiers" :columns="EXP_COLS" :fetch="expFetch" />
        <button class="btn-normal" @click="load">Actualiser</button>
      </div>
    </div>

    <div v-if="msg" class="flash info"><div>{{ msg }}</div></div>

    <section class="container">
      <div class="tabs">
        <button v-for="t in TABS" :key="t.key" :class="{ on: status === t.key }" @click="status = t.key; load()">
          {{ t.label }} <span class="n">{{ t.key ? (counts[t.key] || 0) : total }}</span>
        </button>
      </div>
      <div class="toolbar">
        <div class="search">
          <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="7" cy="7" r="5"/><path d="M11 11l4 4"/></svg>
          <input v-model="q" type="search" placeholder="Caissier, téléphone ou marchand…" @keyup.enter="load" />
        </div>
        <span class="grow"></span>
        <span class="hint" style="margin:0;">{{ rows.length }} résultat(s)</span>
      </div>
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead>
            <tr><th>Caissier</th><th>Marchand · point de vente</th><th class="num">Encaissé aujourd'hui</th><th class="num">Total encaissé</th><th>Dernière connexion</th><th>Accès</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="c in rows" :key="c.id">
              <td>
                <div class="who">
                  <span class="ava">{{ initials(c.name) }}</span>
                  <div><strong>{{ c.name }}</strong><small class="mono">{{ $phone(c.phone) }}</small></div>
                </div>
              </td>
              <td><strong>{{ c.merchant || '—' }}</strong><br /><small style="color:var(--text-2)">{{ c.outlet || 'Tous points de vente' }}</small></td>
              <td class="num">{{ money(c.today_collected) }}</td>
              <td class="num">{{ money(c.total_collected) }}</td>
              <td>{{ date(c.last_login) }}</td>
              <td>
                <span class="status" :class="c.status === 'active' ? 'ok' : 'err'">{{ c.status === 'active' ? 'Actif' : 'Révoqué' }}</span>
                <div v-if="c.status !== 'active' && c.revoked_reason" class="hint">{{ c.revoked_reason }}</div>
              </td>
              <td class="actions-cell">
                <IconAction v-if="c.status === 'active'" icon="ban" tone="danger" label="Révoquer l'accès" @click="revoking = c; reason = ''" />
                <IconAction v-else icon="check" tone="ok" label="Réactiver l'accès" :disabled="busy === c.id" @click="act(c, 'reactivate')" />
              </td>
            </tr>
          </tbody>
        </table>
        <div v-if="!loading && !rows.length" class="empty">
          <strong>Aucun caissier</strong>
          Les marchands créent leurs caissiers depuis l'application (« Équipe caissiers »).
        </div>
      </div>
    </section>

    <Modal v-if="revoking" title="Révoquer l'accès caissier" :subtitle="revoking.name + ' · ' + (revoking.merchant || '')" @close="revoking = null">
      <div class="flash warn" style="margin:0;"><div>Le caissier est déconnecté immédiatement de tous ses appareils. Le marchand en est informé.</div></div>
      <div><label class="field">Motif</label><input v-model.trim="reason" placeholder="Ex. départ de l'employé, suspicion de fraude" /></div>
      <template #foot>
        <button class="btn-normal" @click="revoking = null">Annuler</button>
        <button class="btn accent" :disabled="busy === revoking.id" @click="act(revoking, 'revoke', reason)">Révoquer</button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../services/api'
import ExportButton from '../components/ExportButton.vue'
import IconAction from '../components/IconAction.vue'
import Modal from '../components/Modal.vue'
import { fmtDate, fmtPhone } from '../utils/export'
import { date, errMsg, money } from '../utils/format'

const TABS = [
  { key: '', label: 'Tous' },
  { key: 'active', label: 'Actifs' },
  { key: 'revoked', label: 'Révoqués' },
]
const rows = ref([])
const counts = ref({})
const status = ref('')
const q = ref('')
const loading = ref(false)
const busy = ref(null)
const msg = ref('')
const revoking = ref(null)
const reason = ref('')
const total = computed(() => Object.values(counts.value).reduce((a, b) => a + Number(b), 0))
const initials = (s) => (s || '?').split(/\s+/).map((w) => w[0]).slice(0, 2).join('').toUpperCase()

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/cashiers', { params: { status: status.value || undefined, q: q.value || undefined } })
    rows.value = data.data
    counts.value = data.counts || {}
  } catch (e) {
    msg.value = errMsg(e)
  } finally {
    loading.value = false
  }
}
async function act(c, action, why) {
  busy.value = c.id
  try {
    const { data } = await api.post(`/admin/cashiers/${c.id}/status`, { action, reason: why || undefined })
    msg.value = `${c.name} : ${data.message}`
    revoking.value = null
    await load()
  } catch (e) {
    msg.value = errMsg(e)
  } finally {
    busy.value = null
  }
}
onMounted(load)

const EXP_COLS = [
  { label: 'Caissier', value: (c) => c.name },
  { label: 'Téléphone', value: (c) => fmtPhone(c.phone) },
  { label: 'Marchand', value: (c) => c.merchant },
  { label: 'Point de vente', value: (c) => c.outlet },
  { label: "Encaissé aujourd'hui", value: (c) => c.today_collected },
  { label: 'Total encaissé', value: (c) => c.total_collected },
  { label: 'Accès', value: (c) => (c.status === 'active' ? 'Actif' : 'Révoqué') },
  { label: 'Dernière connexion', value: (c) => fmtDate(c.last_login) },
  { label: 'Créé le', value: (c) => fmtDate(c.created_at) },
]
// L'API renvoie la liste complète (300 max) : une seule « page »
const expFetch = async () => (await api.get('/admin/cashiers', { params: { status: status.value || undefined, q: q.value || undefined } })).data.data
</script>
