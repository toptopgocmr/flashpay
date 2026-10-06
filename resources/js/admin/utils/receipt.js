import api from '../services/api'

/**
 * Reçus de transaction (page imprimable / PDF signée côté serveur).
 * L'onglet est ouvert tout de suite (sinon le navigateur bloque la fenêtre
 * surgissante), puis redirigé vers le lien signé.
 */
async function openSigned(getUrl) {
  const w = window.open('about:blank', '_blank')
  if (w) w.document.write('<p style="font:15px sans-serif;text-align:center;margin-top:40px;color:#64748b">Préparation du reçu…</p>')
  try {
    const url = await getUrl()
    if (w) w.location.href = url
    else window.location.href = url
  } catch (e) {
    if (w) w.close()
    window.alert(e.response?.data?.message || "Impossible de générer le reçu.")
  }
}

/** Reçu d'une transaction. `ticket` : format imprimante thermique 58/80 mm. */
export const openReceipt = (id, { ticket = false } = {}) =>
  openSigned(async () => {
    const { data } = await api.get(`/admin/transactions/${id}/receipt`)
    return data.url + (ticket ? '#ticket' : '')
  })

/** Plusieurs reçus sur une seule page (impression lancée automatiquement). */
export const openReceipts = (ids) =>
  openSigned(async () => {
    const { data } = await api.post('/admin/transactions/receipts', { ids: ids.slice(0, 100) })
    return data.url
  })
