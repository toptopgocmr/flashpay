<template>
  <div>
    <div class="page-header">
      <div><h1>Supervision des échanges</h1><p>Supervision de toutes les discussions et appels entre utilisateurs (conformité).</p></div>
      <div class="actions"><button class="btn-normal" @click="load(1)">Actualiser</button></div>
    </div>
    <div class="tabs mb">
      <button :class="{ on: tab === 'conv' }" @click="tab = 'conv'">Discussions</button>
      <button :class="{ on: tab === 'calls' }" @click="tab = 'calls'; loadCalls(1)">Journal des appels</button>
    </div>

    <div v-if="tab === 'conv'" class="cm-grid">
      <section class="container cm-list">
        <div class="cm-filters">
          <input v-model.trim="q" placeholder="Nom ou téléphone d'un participant…" @keyup.enter="load(1)" />
          <select v-model="kind" @change="load(1)"><option value="">Toutes</option><option value="direct">Entre utilisateurs</option><option value="support">Support</option></select>
          <label style="white-space:nowrap;"><input v-model="withMedia" type="checkbox" @change="load(1)" /> avec médias</label>
        </div>
        <div v-if="d && !d.data.length" class="empty"><strong>Aucune discussion</strong></div>
        <button v-for="c in d?.data || []" :key="c.id" class="cm-item" :class="{ on: c.id === currentId }" @click="open(c.id)">
          <div><strong>{{ who(c.participants[0]) }}</strong> ⇄ <strong>{{ who(c.participants[1]) }}</strong></div>
          <small class="muted">{{ c.kind === 'support' ? 'Support · ' : '' }}{{ c.messages }} message(s) · {{ c.calls }} appel(s) · {{ date(c.last_message_at) }}</small>
        </button>
        <div v-if="d && d.last_page > 1" class="cm-pages">
          <button class="btn-normal" :disabled="d.current_page <= 1" @click="load(d.current_page - 1)">‹</button>
          <span>{{ d.current_page }} / {{ d.last_page }}</span>
          <button class="btn-normal" :disabled="d.current_page >= d.last_page" @click="load(d.current_page + 1)">›</button>
        </div>
      </section>

      <section class="container cm-thread">
        <div v-if="!thread" class="empty"><strong>Choisissez une discussion</strong></div>
        <template v-else>
          <div class="cm-head">
            <span v-for="p in thread.conversation.participants" :key="p?.id" class="cm-part">
              <strong>{{ who(p) }}</strong><br /><small class="mono">{{ p?.phone ? $phone(p.phone) : '' }}</small>
              <router-link v-if="p && !p.support" :to="`/clients/${p.id}`" style="margin-left:6px;font-size:12px;">fiche</router-link>
            </span>
          </div>
          <button v-if="thread.data.length >= 100" class="btn-normal" style="align-self:center;margin:6px;" @click="older">Messages plus anciens</button>
          <div class="cm-msgs">
            <div v-for="m in thread.data" :key="m.id" class="cm-msg" :class="{ right: m.sender?.id === thread.conversation.participants[1]?.id, call: m.type === 'call' }">
              <small v-if="m.type === 'call'">📞 {{ m.sender?.name }} → {{ callLabel(m) }} · {{ time(m.at) }}</small>
              <div v-else class="bubble">
                <small class="who">{{ who(m.sender) }}<span v-if="m.agent"> ({{ m.agent }})</span></small>
                <small v-if="m.forwarded" class="muted"><em>↪ Transféré</em></small>
                <div v-if="m.reply_to" class="quote">{{ m.reply_to.body || '[' + m.reply_to.type + ']' }}</div>
                <ChatMedia v-if="['image', 'audio', 'video'].includes(m.type)" :key="'f' + m.id" :type="m.type" :file-url="`/admin/chat-monitor/messages/${m.id}/file`" />
                <div v-if="m.body" class="txt">{{ m.body }}</div>
                <small class="meta">{{ time(m.at) }}<span v-if="m.lang"> · {{ m.lang }}</span><span v-if="m.edited_at"> · modifié</span><span v-if="m.read_at"> · lu</span></small>
              </div>
            </div>
          </div>
        </template>
      </section>
    </div>

    <section v-else class="container">
      <div class="container-body flush" style="overflow-x:auto;">
        <table>
          <thead><tr><th>Date</th><th>Appelant</th><th>Appelé</th><th>Statut</th><th>Durée</th><th>Agent</th><th></th></tr></thead>
          <tbody>
            <tr v-for="c in calls?.data || []" :key="c.id">
              <td>{{ date(c.at) }}</td>
              <td>{{ who(c.caller) }}<br /><small class="mono">{{ c.caller?.phone ? $phone(c.caller.phone) : '' }}</small></td>
              <td>{{ who(c.callee) }}<br /><small class="mono">{{ c.callee?.phone ? $phone(c.callee.phone) : '' }}</small></td>
              <td>{{ STATUS[c.status] || c.status }}</td>
              <td>{{ c.seconds ? `${Math.floor(c.seconds / 60)}:${String(c.seconds % 60).padStart(2, '0')}` : '—' }}</td>
              <td>{{ c.agent || '—' }}</td>
              <td><button class="btn-normal" @click="tab = 'conv'; open(c.conversation_id)">Discussion</button></td>
            </tr>
          </tbody>
        </table>
        <div v-if="calls && calls.last_page > 1" class="cm-pages">
          <button class="btn-normal" :disabled="calls.current_page <= 1" @click="loadCalls(calls.current_page - 1)">‹</button>
          <span>{{ calls.current_page }} / {{ calls.last_page }}</span>
          <button class="btn-normal" :disabled="calls.current_page >= calls.last_page" @click="loadCalls(calls.current_page + 1)">›</button>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import api from '../services/api'
import ChatMedia from '../components/ChatMedia.vue'
import { date } from '../utils/format'

const STATUS = { ended: 'Terminé', missed: 'Manqué', rejected: 'Refusé', busy: 'Occupé', cancelled: 'Annulé', ringing: 'Sonne', accepted: 'En cours' }
const tab = ref('conv')
const d = ref(null)
const q = ref('')
const kind = ref('')
const withMedia = ref(false)
const currentId = ref(null)
const thread = ref(null)
const calls = ref(null)
const who = (p) => (p ? p.name : '—')
const time = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: '2-digit', hour: '2-digit', minute: '2-digit' }) : '')
function callLabel(m) {
  const s = m.duration ? ` (${Math.floor(m.duration / 60)}:${String(m.duration % 60).padStart(2, '0')})` : ''
  return ({ missed: 'appel manqué', cancelled: 'appel annulé', rejected: 'appel refusé', busy: 'occupé' }[m.body] || 'appel audio') + s
}

async function load(page = 1) {
  const { data } = await api.get('/admin/chat-monitor', { params: { page, q: q.value || undefined, kind: kind.value || undefined, with_media: withMedia.value ? 1 : undefined } })
  d.value = data
}
async function open(id) {
  currentId.value = id
  const { data } = await api.get(`/admin/chat-monitor/${id}/messages`)
  thread.value = data
}
async function older() {
  const first = thread.value.data[0]?.id
  const { data } = await api.get(`/admin/chat-monitor/${currentId.value}/messages`, { params: { before: first } })
  thread.value.data = [...data.data, ...thread.value.data]
}
async function loadCalls(page = 1) {
  const { data } = await api.get('/admin/chat-monitor/calls', { params: { page } })
  calls.value = data
}
onMounted(() => load(1))
</script>

<style scoped>
.cm-grid > * { min-width: 0; }
.cm-item { overflow-wrap: anywhere; }
.cm-grid { display: grid; grid-template-columns: 360px minmax(0, 1fr); gap: 16px; align-items: start; }
@media (max-width: 900px) { .cm-grid { grid-template-columns: 1fr; } }
.cm-list { max-height: 74vh; overflow-y: auto; padding: 8px; }
.cm-filters { display: flex; gap: 6px; margin-bottom: 8px; flex-wrap: wrap; align-items: center; }
.cm-filters input[type=text], .cm-filters input:not([type]) { flex: 1; min-width: 0; }
.cm-filters select { width: auto; min-width: 0; }
.cm-item { display: block; width: 100%; text-align: left; background: none; border: 0; border-bottom: 1px solid #e5e7eb; padding: 10px 8px; cursor: pointer; border-radius: 8px; }
.cm-item.on { background: #e6edfb; }
.cm-pages { display: flex; gap: 8px; justify-content: center; align-items: center; padding: 8px; }
.cm-thread { display: flex; flex-direction: column; height: 74vh; padding: 12px; }
.cm-head { display: flex; justify-content: space-between; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; }
.cm-msgs { flex: 1; overflow-y: auto; padding: 12px 4px; background: #f4f5f7; border-radius: 10px; margin-top: 8px; }
.cm-msg { display: flex; margin: 4px 0; }
.cm-msg.right { justify-content: flex-end; }
.cm-msg.call { justify-content: center; color: #6b7280; }
.bubble { max-width: 70%; background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 8px 10px; display: flex; flex-direction: column; gap: 4px; }
.right .bubble { background: #e6edfb; }
.who { font-weight: 700; color: #1e3a8a; }
.txt { white-space: pre-wrap; word-break: break-word; }
.quote { border-left: 3px solid #e11d2a; padding: 2px 8px; font-size: 13px; background: rgba(0,0,0,.04); border-radius: 6px; }
.meta { font-size: 11px; color: #6b7280; }
</style>
