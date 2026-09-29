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

/** info/success : carillon doux · warning : double bip · critical : alarme répétée */
export async function playNotificationSound(severity = 'info') {
  const c = context()
  if (!c) return
  if (c.state !== 'running') {
    try { await c.resume() } catch (_) { return }
  }
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
}
