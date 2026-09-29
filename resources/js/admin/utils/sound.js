// Son des notifications de la console (Web Audio : aucun fichier à charger).
// Les navigateurs n'autorisent le son qu'après une première interaction : le
// contexte audio est « débloqué » au premier clic / touche.
let ctx = null

function context() {
  if (!ctx) {
    const AC = window.AudioContext || window.webkitAudioContext
    if (!AC) return null
    ctx = new AC()
  }
  return ctx
}

export function unlockAudio() {
  // Prépare aussi le lecteur de secours pendant le geste de l'utilisateur
  if (!audioEl) { try { audioEl = new Audio(wav()); audioEl.load() } catch (_) {} }
  const c = context()
  if (!c) return
  if (c.state === 'suspended') c.resume().catch(() => {})
  // Son silencieux : certains navigateurs n'activent l'audio qu'après une vraie lecture
  try { tone(c, 440, 0, 0.01, 0.0002) } catch (_) {}
}

/** true si le navigateur autorise le son (après un clic sur la page). */
export function audioReady() {
  return !!ctx && ctx.state === 'running'
}

function tone(c, freq, start, duration, volume, type = 'sine') {
  const osc = c.createOscillator()
  const gain = c.createGain()
  osc.type = type
  osc.frequency.value = freq
  gain.gain.setValueAtTime(0.0001, c.currentTime + start)
  gain.gain.exponentialRampToValueAtTime(volume, c.currentTime + start + 0.02)
  gain.gain.exponentialRampToValueAtTime(0.0001, c.currentTime + start + duration)
  osc.connect(gain).connect(c.destination)
  osc.start(c.currentTime + start)
  osc.stop(c.currentTime + start + duration + 0.05)
}

// Secours : un vrai fichier son (WAV généré en mémoire) lu par un élément <audio>,
// plus fiable que Web Audio sur certains navigateurs / pilotes Windows.
let wavUrl = null
function wav() {
  if (wavUrl) return wavUrl
  const rate = 22050, dur = 0.7, n = Math.floor(rate * dur)
  const buf = new ArrayBuffer(44 + n * 2), v = new DataView(buf)
  const w = (o, s) => { for (let i = 0; i < s.length; i++) v.setUint8(o + i, s.charCodeAt(i)) }
  w(0, 'RIFF'); v.setUint32(4, 36 + n * 2, true); w(8, 'WAVE'); w(12, 'fmt ')
  v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true)
  v.setUint32(24, rate, true); v.setUint32(28, rate * 2, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true)
  w(36, 'data'); v.setUint32(40, n * 2, true)
  for (let i = 0; i < n; i++) {
    const t = i / rate
    const f = t < 0.25 ? 880 : 1320 // carillon deux notes
    const env = Math.min(1, t * 60) * Math.exp(-(t % 0.35) * 7)
    v.setInt16(44 + i * 2, Math.sin(2 * Math.PI * f * t) * env * 0.5 * 32767, true)
  }
  wavUrl = URL.createObjectURL(new Blob([buf], { type: 'audio/wav' }))
  return wavUrl
}
let audioEl = null
async function playFallback() {
  try {
    audioEl = audioEl || new Audio(wav())
    audioEl.currentTime = 0
    audioEl.volume = 1
    await audioEl.play()
    return true
  } catch (_) {
    return false
  }
}

/**
 * info/success : carillon doux · warning : double bip · critical : alarme répétée.
 * Renvoie false si le navigateur a bloqué le son (aucun clic sur la page encore).
 */
export async function playNotificationSound(severity = 'info') {
  const c = context()
  if (c && c.state !== 'running') {
    try { await c.resume() } catch (_) {}
  }
  if (!c || c.state !== 'running') return playFallback()
  if (severity === 'critical') {
    for (let i = 0; i < 3; i++) {
      tone(c, 880, i * 0.36, 0.16, 0.35, 'square')
      tone(c, 660, i * 0.36 + 0.17, 0.16, 0.35, 'square')
    }
  } else if (severity === 'warning') {
    tone(c, 740, 0, 0.18, 0.3, 'triangle')
    tone(c, 740, 0.25, 0.18, 0.3, 'triangle')
  } else {
    tone(c, 880, 0, 0.35, 0.25)
    tone(c, 1320, 0.15, 0.45, 0.2)
  }
  return true
}
