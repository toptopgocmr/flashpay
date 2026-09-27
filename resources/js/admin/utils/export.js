import api from '../services/api'
import { phone } from './format'

/** Récupère toutes les pages d'une liste paginée Laravel ({ data, last_page }). */
export async function fetchAllPages(url, params = {}, onProgress = () => {}) {
  const rows = []
  let page = 1
  let last = 1
  do {
    const { data } = await api.get(url, { params: { ...params, page } })
    const list = Array.isArray(data) ? data : data.data || []
    rows.push(...list)
    last = Array.isArray(data) ? 1 : data.last_page || 1
    onProgress(page, last)
    page++
  } while (page <= last && page <= 400)
  return rows
}

const cell = (v) => {
  if (v == null) return ''
  const s = String(v).replace(/\r?\n/g, ' ')
  return /[";\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s
}

export const fmtDate = (s) => (s ? new Date(s).toLocaleString('fr-FR', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '')
export const fmtPhone = (p) => phone(p) || ''

/**
 * Télécharge un fichier CSV lisible directement par Excel (séparateur « ; »,
 * encodage UTF-8 avec BOM pour les accents).
 * columns : [{ label, value: (row) => … }]
 */
export function downloadCsv(filename, columns, rows) {
  const lines = [columns.map((c) => cell(c.label)).join(';')]
  for (const r of rows) lines.push(columns.map((c) => cell(c.value(r))).join(';'))
  const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  const stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '')
  a.href = URL.createObjectURL(blob)
  a.download = `${filename}-${stamp}.csv`
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(a.href), 2000)
}

/**
 * Télécharge un vrai classeur Excel (.xlsx). Les montants restent des nombres
 * (sommables dans Excel) ; les téléphones « +242… » restent du texte.
 * La bibliothèque est chargée à la demande pour ne pas alourdir la console.
 */
export async function downloadXlsx(filename, columns, rows, sheet = 'Export') {
  const { default: writeXlsxFile } = await import('write-excel-file')
  const toCell = (v) => {
    if (v == null || v === '') return null
    if (typeof v === 'number' && Number.isFinite(v)) return { type: Number, value: v }
    // Montants renvoyés en texte par l'API (« 5000.00 ») → nombre ; codes/numéros longs ou à zéro initial → texte.
    if (typeof v === 'string' && /^-?(0|[1-9]\d{0,11})(\.\d+)?$/.test(v)) return { type: Number, value: Number(v) }
    return { type: String, value: String(v) }
  }
  const header = columns.map((c) => ({ value: c.label, fontWeight: 'bold', backgroundColor: '#E8EEF9' }))
  const data = [header, ...rows.map((r) => columns.map((c) => toCell(c.value(r))))]
  const widths = columns.map((c, i) => {
    let w = String(c.label).length
    for (let k = 1; k < Math.min(data.length, 300); k++) w = Math.max(w, String(data[k][i]?.value ?? '').length)
    return { width: Math.min(Math.max(w + 2, 8), 60) }
  })
  const stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, '')
  await writeXlsxFile(data, { columns: widths, fileName: `${filename}-${stamp}.xlsx`, sheet: sheet.slice(0, 31), stickyRowsCount: 1 })
}
