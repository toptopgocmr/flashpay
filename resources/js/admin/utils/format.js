const nf = new Intl.NumberFormat('fr-FR')
export const money = (n, c) => (n == null ? '—' : nf.format(n) + ' ' + (c || 'XAF'))
export const num = (n) => (n == null ? '—' : nf.format(n))
export const date = (s) => (s ? new Date(s).toLocaleString('fr-FR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')
export const errMsg = (e) => e?.response?.data?.message || e?.response?.data?.error?.message || e?.message || 'Erreur'

/** Numéro au format international : +242067621919 (anciens numéros locaux 0… → Congo +242). */
export const phone = (p) => {
  if (!p) return p
  const s = String(p).trim()
  if (s.startsWith('+')) return s.replace(/\s+/g, '')
  const d = s.replace(/\D+/g, '')
  if (!d) return s
  if (d.startsWith('0') && d.length === 9) return '+242' + d
  return '+' + d
}
