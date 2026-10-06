// Appels audio du support (console) — WebRTC dans le navigateur.
// Même signalisation que l'app mobile : offre / réponse SDP « non-trickle »
// échangées via l'API ; la voix passe directement entre le navigateur et le téléphone.
import { reactive } from 'vue'
import api from './api'

export const call = reactive({
  phase: 'idle', // idle | incoming | calling | connecting | connected | ended
  id: null,
  conversationId: null,
  name: '',
  muted: false,
  seconds: 0,
  message: '',
})

let pc = null
let local = null
let remoteAudio = null
let pollTimer = null
let statusTimer = null
let clockTimer = null
let ringTimer = null
let incomingOffer = null
let audioCtx = null
const seen = new Set()

// SDP : chaque ligne doit finir par CRLF, la dernière aussi (sinon « Failed to parse SessionDescription »).
const sdp = (t) => String(t || '').split(/\r\n|\r|\n/).map((l) => l.trim()).filter(Boolean).join('\r\n') + '\r\n'

async function iceServers() {
  try {
    const { data } = await api.get('/support/chat/calls/config')
    if (data.ice_servers?.length) return data.ice_servers
  } catch (_) {}
  return [{ urls: ['stun:stun.l.google.com:19302'] }]
}

function beep() {
  try {
    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)()
    for (const [i, f] of [[0, 880], [0.25, 660]]) {
      const o = audioCtx.createOscillator()
      const g = audioCtx.createGain()
      o.frequency.value = f
      g.gain.value = 0.15
      o.connect(g).connect(audioCtx.destination)
      o.start(audioCtx.currentTime + i)
      o.stop(audioCtx.currentTime + i + 0.2)
    }
  } catch (_) {}
}
function startRinging() {
  stopRinging()
  beep()
  ringTimer = setInterval(beep, 1500)
}
function stopRinging() {
  clearInterval(ringTimer)
  ringTimer = null
}

async function preparePeer() {
  local = await navigator.mediaDevices.getUserMedia({ audio: true, video: false })
  pc = new RTCPeerConnection({ iceServers: await iceServers() })
  local.getAudioTracks().forEach((t) => pc.addTrack(t, local))
  pc.ontrack = (e) => {
    if (!remoteAudio) {
      remoteAudio = new Audio()
      remoteAudio.autoplay = true
    }
    remoteAudio.srcObject = e.streams?.[0] || new MediaStream([e.track])
    remoteAudio.play().catch(() => {})
  }
  pc.onconnectionstatechange = () => {
    if (pc?.connectionState === 'connected') onConnected()
    if (pc?.connectionState === 'failed') hangup('Connexion audio impossible (réseau).')
  }
}

// ICE « non-trickle » : on attend la collecte des candidats (6 s max) avant d'envoyer le SDP.
function gathered() {
  return new Promise((resolve) => {
    if (pc.iceGatheringState === 'complete') return resolve()
    const t = setTimeout(resolve, 6000)
    pc.onicegatheringstatechange = () => {
      if (pc.iceGatheringState === 'complete') {
        clearTimeout(t)
        resolve()
      }
    }
  })
}

function onConnected() {
  if (call.phase === 'connected') return
  stopRinging()
  call.phase = 'connected'
  call.seconds = 0
  clearInterval(clockTimer)
  clockTimer = setInterval(() => call.seconds++, 1000)
}

function release() {
  clearInterval(statusTimer)
  clearInterval(clockTimer)
  stopRinging()
  try { local?.getTracks().forEach((t) => t.stop()) } catch (_) {}
  try { pc?.close() } catch (_) {}
  if (remoteAudio) remoteAudio.srcObject = null
  pc = null
  local = null
}

function finish(message) {
  release()
  call.phase = 'ended'
  call.message = message || 'Appel terminé'
  setTimeout(() => {
    if (call.phase === 'ended') Object.assign(call, { phase: 'idle', id: null, conversationId: null, name: '', muted: false, seconds: 0, message: '' })
  }, 2500)
}

function watchStatus() {
  clearInterval(statusTimer)
  let answered = false
  statusTimer = setInterval(async () => {
    if (!call.id) return
    try {
      const { data } = await api.get(`/support/chat/calls/${call.id}`)
      if (data.status === 'accepted') {
        if (call.phase === 'calling' && data.answer && !answered) {
          answered = true
          call.phase = 'connecting'
          try {
            await pc?.setRemoteDescription({ type: 'answer', sdp: sdp(data.answer) })
          } catch (err) {
            hangup('Connexion audio impossible : ' + (err?.message || 'réponse invalide').slice(0, 120))
          }
        }
        return
      }
      if (data.status === 'ringing') return
      finish({ rejected: 'Appel refusé', busy: 'Appel refusé', missed: 'Pas de réponse', cancelled: 'Appel annulé' }[data.status] || 'Appel terminé')
    } catch (_) {}
  }, 1500)
}

/** Sonnerie des appels clients vers le support (toutes les consoles connectées). */
export function startCallWatcher() {
  if (pollTimer) return
  pollTimer = setInterval(async () => {
    if (call.phase !== 'idle' && call.phase !== 'incoming') return
    try {
      const { data } = await api.get('/support/chat/calls/incoming')
      const c = data.call
      if (!c) {
        if (call.phase === 'incoming') { // un collègue a décroché, ou l'appelant a raccroché
          stopRinging()
          Object.assign(call, { phase: 'idle', id: null })
        }
        return
      }
      if (call.phase === 'incoming' || seen.has(c.id)) return
      seen.add(c.id)
      incomingOffer = c.offer
      Object.assign(call, { phase: 'incoming', id: c.id, conversationId: c.conversation_id, name: c.user?.name || 'Client', message: '' })
      startRinging()
      try { new Notification('📞 Appel support', { body: call.name }) } catch (_) {}
    } catch (_) {}
  }, 4000)
}
export function stopCallWatcher() {
  clearInterval(pollTimer)
  pollTimer = null
}

export async function accept() {
  stopRinging()
  call.phase = 'connecting'
  try {
    await preparePeer()
    await pc.setRemoteDescription({ type: 'offer', sdp: sdp(incomingOffer) })
    const answer = await pc.createAnswer()
    await pc.setLocalDescription(answer)
    await gathered()
    await api.post(`/support/chat/calls/${call.id}/accept`, { answer: sdp(pc.localDescription.sdp) })
    watchStatus()
  } catch (e) {
    const msg = e?.response?.status === 409 ? 'Appel déjà pris par un collègue ou terminé.' : micError(e)
    if (call.id) api.post(`/support/chat/calls/${call.id}/end`).catch(() => {})
    finish(msg)
  }
}

export async function decline() {
  stopRinging()
  if (call.id) await api.post(`/support/chat/calls/${call.id}/reject`).catch(() => {})
  finish('Appel refusé')
}

/** Appeler un client depuis sa discussion support. */
export async function startCall(conversationId, name) {
  if (call.phase !== 'idle') return
  Object.assign(call, { phase: 'calling', conversationId, name, id: null, message: '', muted: false })
  try {
    await preparePeer()
    const offer = await pc.createOffer({ offerToReceiveAudio: true })
    await pc.setLocalDescription(offer)
    await gathered()
    const { data } = await api.post(`/support/chat/${conversationId}/calls`, { offer: sdp(pc.localDescription.sdp) })
    call.id = data.id
    watchStatus()
  } catch (e) {
    finish(e?.response?.data?.message || micError(e))
  }
}

export async function hangup(message) {
  if (call.id) api.post(`/support/chat/calls/${call.id}/end`).catch(() => {})
  finish(message)
}

export function toggleMute() {
  call.muted = !call.muted
  local?.getAudioTracks().forEach((t) => (t.enabled = !call.muted))
}

function micError(e) {
  const n = e?.name || ''
  if (n === 'NotAllowedError' || n === 'SecurityError') return 'Micro refusé : autorisez le micro pour ce site dans le navigateur.'
  if (n === 'NotFoundError') return 'Aucun micro détecté sur cet ordinateur.'
  return e?.response?.data?.message || e?.message || 'Erreur'
}
