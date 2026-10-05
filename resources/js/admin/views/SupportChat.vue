<template>
  <div>
    <div class="page-header">
      <div><h1>Chat support</h1><p>Discussions des clients avec « Support FlashPay » : messages, photos, vidéos, notes vocales et appels audio. Vous répondez au nom du support ; votre prénom s'affiche chez le client et chaque action est tracée.</p></div>
      <div class="actions"><button class="btn-normal" @click="loadList">Actualiser</button></div>
    </div>
    <div class="sc-grid">
      <!-- Boîte de réception -->
      <section class="container sc-list">
        <div class="sc-filters">
          <input v-model.trim="q" placeholder="Nom ou téléphone…" @keyup.enter="loadList" />
          <select v-model="status" @change="loadList"><option value="">Toutes</option><option value="open">Ouvertes</option><option value="closed">Clôturées</option></select>
        </div>
        <div v-if="!list.length" class="empty"><strong>Aucune discussion</strong></div>
        <button v-for="c in list" :key="c.id" class="sc-item" :class="{ on: c.id === currentId }" @click="select(c.id)">
          <div class="sc-row">
            <strong>{{ c.client?.name || 'Client' }}</strong>
            <span v-if="c.ringing" class="pill ring">📞 sonne</span>
            <span v-else-if="c.unread" class="pill">{{ c.unread }}</span>
          </div>
          <small class="mono">{{ $phone(c.client?.phone) }} · {{ (c.client?.language || 'fr').toUpperCase() }}</small>
          <small class="sc-last">{{ c.last ? (c.last.from_client ? '' : 'Vous : ') + preview(c.last) : '—' }}</small>
          <small class="muted">{{ date(c.updated_at) }}<span v-if="c.assigned"> · {{ c.assigned }}</span><span v-if="c.status === 'closed'"> · clôturée</span></small>
        </button>
      </section>

      <!-- Discussion -->
      <section class="container sc-thread">
        <div v-if="!current" class="empty"><strong>Choisissez une discussion</strong></div>
        <template v-else>
          <div class="sc-head">
            <div>
              <strong>{{ current.client?.name }}</strong>
              <router-link v-if="current.client" :to="`/clients/${current.client.id}`" class="muted" style="margin-left:8px;">Fiche client</router-link><br />
              <small class="mono">{{ $phone(current.client?.phone) }}</small>
            </div>
            <div style="display:flex; gap:8px;">
              <button class="btn" :disabled="call.phase !== 'idle'" @click="startCall(current.id, current.client?.name)">📞 Appeler</button>
              <button class="btn-normal" @click="setStatus(current.status === 'closed' ? 'open' : 'closed')">{{ current.status === 'closed' ? 'Rouvrir' : 'Clôturer' }}</button>
            </div>
          </div>
          <div ref="scroller" class="sc-msgs">
            <div v-for="m in messages" :key="m.id" class="sc-msg" :class="{ me: m.mine, call: m.type === 'call' }">
              <template v-if="m.type === 'call'">
                <small>📞 {{ callLabel(m) }} · {{ time(m.at) }}</small>
              </template>
              <div v-else class="bubble">
                <small v-if="m.mine && m.agent" class="who">{{ m.agent }}</small>
                <small v-if="m.forwarded" class="muted"><em>↪ Transféré</em></small>
                <div v-if="m.reply_to" class="quote">{{ m.reply_to.body || '[' + m.reply_to.type + ']' }}</div>
                <ChatMedia v-if="['image', 'audio', 'video'].includes(m.type)" :key="'m' + m.id" :type="m.type" :file-url="`/support/chat/messages/${m.id}/file`" :link-url="`/support/chat/messages/${m.id}/link`" />
                <div v-if="m.body" class="txt">{{ m.translation && !orig[m.id] ? m.translation : m.body }}</div>
                <a v-if="m.translation" href="#" class="tr" @click.prevent="orig[m.id] = !orig[m.id]">🌐 {{ orig[m.id] ? 'Voir la traduction' : `Traduit (${m.lang}) · voir l'original` }}</a>
                <small class="meta">{{ m.edited ? 'modifié · ' : '' }}{{ time(m.at) }}<span v-if="m.mine"> · {{ m.read ? 'lu' : 'envoyé' }}</span>
                  <a href="#" style="margin-left:8px;" @click.prevent="replyTo = m">Répondre</a></small>
              </div>
            </div>
          </div>
          <div v-if="replyTo" class="sc-reply">Réponse à : {{ replyTo.body || '[' + replyTo.type + ']' }} <a href="#" @click.prevent="replyTo = null">✕</a></div>
          <div v-if="error" class="flash err" style="margin:6px 0;"><div>{{ error }}</div></div>
          <div class="sc-compose">
            <label class="btn-normal" title="Photo ou vidéo">📎<input type="file" accept="image/*,video/mp4,video/webm" hidden @change="sendFile" /></label>
            <button v-if="!recording" class="btn-normal" title="Note vocale" @click="startRec">🎤</button>
            <button v-else class="btn rec" @click="stopRec(true)">⏹ {{ recSec }} s — Envoyer</button>
            <button v-if="recording" class="btn-normal" @click="stopRec(false)">Annuler</button>
            <textarea v-model="text" rows="2" placeholder="Écrire au client… (Entrée pour envoyer, Maj+Entrée pour aller à la ligne)" @keydown.enter.exact.prevent="sendText"></textarea>
            <button class="btn" :disabled="sending || !text.trim()" @click="sendText">Envoyer</button>
          </div>
        </template>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import api from '../services/api'
import ChatMedia from '../components/ChatMedia.vue'
import { date } from '../utils/format'
import { call, startCall } from '../services/supportCall'

const route = useRoute()
const list = ref([])
const q = ref('')
const status = ref('')
const currentId = ref(null)
const messages = ref([])
const text = ref('')
const sending = ref(false)
const error = ref('')
const replyTo = ref(null)
const orig = reactive({})
const scroller = ref(null)
let since = null
let listTimer = null
let msgTimer = null

const current = computed(() => list.value.find((c) => c.id === currentId.value))
const time = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '')
const preview = (l) => ({ image: '📷 Photo', video: '🎬 Vidéo', audio: '🎤 Note vocale', call: '📞 Appel' }[l.type] || l.body || '')
function callLabel(m) {
  const d = m.duration ? ` · ${Math.floor(m.duration / 60)}:${String(m.duration % 60).padStart(2, '0')}` : ''
  return { missed: m.mine ? 'Appel sans réponse' : 'Appel manqué', cancelled: 'Appel annulé', rejected: 'Appel refusé', busy: 'Occupé' }[m.body] || 'Appel audio' + d
}

async function loadList() {
  try {
    const { data } = await api.get('/support/chat', { params: { q: q.value || undefined, status: status.value || undefined } })
    list.value = data.data
  } catch (_) {}
}
async function select(id) {
  currentId.value = id
  messages.value = []
  since = null
  replyTo.value = null
  await loadMessages(true)
}
function toBottom() {
  nextTick(() => { if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight })
}
async function loadMessages(initial = false) {
  if (!currentId.value) return
  const last = messages.value.length ? messages.value[messages.value.length - 1].id : null
  try {
    const { data } = await api.get(`/support/chat/${currentId.value}/messages`, { params: initial ? {} : { after: last || undefined, since: since || undefined } })
    since = data.now
    const known = new Set(messages.value.map((m) => m.id))
    const fresh = data.data.filter((m) => !known.has(m.id))
    for (const e of data.edits || []) {
      const i = messages.value.findIndex((m) => m.id === e.id)
      if (i >= 0) messages.value[i] = e
    }
    if (data.read_upto) messages.value.forEach((m) => { if (m.mine && m.id <= data.read_upto) m.read = true })
    if (fresh.length) {
      messages.value.push(...fresh)
      toBottom()
    }
  } catch (_) {}
}
async function post(form) {
  sending.value = true
  error.value = ''
  try {
    if (replyTo.value) form.append('reply_to_id', replyTo.value.id)
    const { data } = await api.post(`/support/chat/${currentId.value}/messages`, form)
    messages.value.push(data)
    replyTo.value = null
    toBottom()
    loadList()
    return true
  } catch (e) {
    error.value = e.response?.data?.message || e.message
    return false
  } finally {
    sending.value = false
  }
}
async function sendText() {
  const t = text.value.trim()
  if (!t || sending.value) return
  const f = new FormData()
  f.append('body', t)
  if (await post(f)) text.value = ''
}
async function sendFile(ev) {
  const file = ev.target.files?.[0]
  ev.target.value = ''
  if (!file) return
  const f = new FormData()
  f.append('file', file)
  if (text.value.trim()) f.append('body', text.value.trim())
  if (await post(f)) text.value = ''
}
async function setStatus(st) {
  await api.post(`/support/chat/${currentId.value}/status`, { status: st, assign_to_me: true }).catch(() => {})
  loadList()
}

// Note vocale (MediaRecorder -> webm/opus)
const recording = ref(false)
const recSec = ref(0)
let recorder = null
let chunks = []
let recTimer = null
let keep = false
async function startRec() {
  error.value = ''
  try {
    const stream = await navigator.mediaDevices.getUserMedia({ audio: true })
    recorder = new MediaRecorder(stream)
    chunks = []
    recorder.ondataavailable = (e) => e.data.size && chunks.push(e.data)
    recorder.onstop = async () => {
      stream.getTracks().forEach((t) => t.stop())
      clearInterval(recTimer)
      recording.value = false
      if (!keep || recSec.value < 1) return
      const type = recorder.mimeType || 'audio/webm'
      const blob = new Blob(chunks, { type })
      const f = new FormData()
      f.append('file', blob, type.includes('ogg') ? 'note-vocale.ogg' : 'note-vocale.webm')
      f.append('kind', 'audio')
      f.append('duration', recSec.value)
      await post(f)
    }
    recorder.start()
    recSec.value = 0
    recording.value = true
    recTimer = setInterval(() => { recSec.value++; if (recSec.value >= 300) stopRec(true) }, 1000)
  } catch (e) {
    error.value = 'Micro refusé : autorisez le micro pour ce site dans le navigateur.'
  }
}
function stopRec(send) {
  keep = send
  try { recorder?.stop() } catch (_) {}
}

watch(() => call.phase, (p) => { if (p === 'ended' || p === 'idle') { loadMessages(); loadList() } })

onMounted(async () => {
  await loadList()
  const c = Number(route.query.c)
  if (c) select(c)
  listTimer = setInterval(loadList, 8000)
  msgTimer = setInterval(() => loadMessages(), 4000)
})
onBeforeUnmount(() => { clearInterval(listTimer); clearInterval(msgTimer) })
</script>

<style scoped>
.sc-grid { display: grid; grid-template-columns: 320px 1fr; gap: 16px; align-items: start; }
@media (max-width: 900px) { .sc-grid { grid-template-columns: 1fr; } }
.sc-list { max-height: 72vh; overflow-y: auto; padding: 8px; }
.sc-filters { display: flex; gap: 6px; margin-bottom: 8px; }
.sc-filters input { flex: 1; }
.sc-item { display: flex; flex-direction: column; gap: 2px; width: 100%; text-align: left; background: none; border: 0; border-bottom: 1px solid var(--line, #e5e7eb); padding: 10px 8px; cursor: pointer; border-radius: 8px; }
.sc-item.on { background: #e6edfb; }
.sc-row { display: flex; justify-content: space-between; align-items: center; }
.sc-last { color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.pill { background: #e11d2a; color: #fff; border-radius: 999px; padding: 1px 8px; font-size: 12px; font-weight: 700; }
.pill.ring { background: #16a34a; }
.sc-thread { display: flex; flex-direction: column; height: 72vh; padding: 12px; }
.sc-head { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; padding-bottom: 8px; }
.sc-msgs { flex: 1; overflow-y: auto; padding: 12px 4px; background: #f4f5f7; border-radius: 10px; margin: 8px 0; }
.sc-msg { display: flex; margin: 4px 0; }
.sc-msg.me { justify-content: flex-end; }
.sc-msg.call { justify-content: center; color: #6b7280; }
.bubble { max-width: 70%; background: #fff; border: 1px solid #e5e7eb; border-radius: 14px; padding: 8px 10px; display: flex; flex-direction: column; gap: 4px; }
.me .bubble { background: #1e3a8a; color: #fff; border: 0; }
.me .bubble .meta, .me .bubble .who, .me .bubble a { color: #dbeafe; }
.who { font-weight: 700; }
.txt { white-space: pre-wrap; word-break: break-word; }
.quote { border-left: 3px solid #e11d2a; padding: 2px 8px; font-size: 13px; opacity: .85; background: rgba(0,0,0,.05); border-radius: 6px; }
.tr { font-size: 12px; }
.meta { font-size: 11px; color: #6b7280; }
.sc-reply { font-size: 13px; background: #e6edfb; padding: 6px 10px; border-radius: 8px; }
.sc-compose { display: flex; gap: 6px; align-items: flex-end; }
.sc-compose textarea { flex: 1; resize: vertical; }
.rec { background: #dc2626 !important; }
</style>
