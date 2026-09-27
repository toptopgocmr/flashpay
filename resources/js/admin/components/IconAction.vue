<template>
  <component :is="to ? 'router-link' : 'button'" :to="to" type="button" class="ia" :class="tone" :data-tip="label" :aria-label="label" :disabled="disabled">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" v-html="ICONS[icon]"></svg>
  </component>
</template>

<script setup>
// Bouton d'action compact (icône + info-bulle) pour les colonnes d'actions des listes.
defineProps({ icon: String, label: String, to: [String, Object], tone: { type: String, default: '' }, disabled: Boolean })
const ICONS = {
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  fund: '<path d="M12 5v14M5 12h14"/><rect x="2" y="2" width="20" height="20" rx="5"/>',
  key: '<circle cx="7.5" cy="15.5" r="4.5"/><path d="M10.7 12.3L21 2M16 7l3 3M18 5l2 2"/>',
  check: '<path d="M20 6L9 17l-5-5"/>',
  ban: '<circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/>',
  power: '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.8 0"/>',
  trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 11v6M14 11v6"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
  copy: '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15V5a2 2 0 0 1 2-2h10"/>',
  undo: '<path d="M3 7v6h6"/><path d="M3 13a9 9 0 1 0 3-7.7L3 8"/>',
  refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
  star: '<path d="M12 2l3.1 6.3 6.9 1-5 4.9 1.2 6.8L12 17.8 5.8 21l1.2-6.8-5-4.9 6.9-1z"/>',
  reply: '<path d="M9 17l-5-5 5-5"/><path d="M4 12h11a5 5 0 0 1 5 5v2"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
  scale: '<path d="M12 3v18M5 7h14M5 7l-3 7a3 3 0 0 0 6 0zM19 7l-3 7a3 3 0 0 0 6 0z"/>',
  open: '<path d="M7 17L17 7M8 7h9v9"/>',
  snow: '<path d="M12 2v20M4.9 4.9l14.2 14.2M2 12h20M4.9 19.1L19.1 4.9"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  wallet: '<rect x="2" y="6" width="20" height="14" rx="3"/><path d="M16 13h2M2 10h20M6 6V4h11v2"/>',
  crown: '<path d="M3 18h18M4 18l-1-10 5 4 4-7 4 7 5-4-1 10"/>',
  unlock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/>',
  alert: '<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
  bank: '<path d="M3 10l9-6 9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8M3 20h18"/>',
}
</script>

<style>
.ia { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 9px; border: 1px solid var(--border); background: #fff; color: var(--text-2); cursor: pointer; margin-left: 6px; vertical-align: middle; text-decoration: none; position: relative; }
.ia:hover { background: var(--surface-2); color: var(--brand); border-color: var(--brand); }
.ia.ok { color: var(--ok); } .ia.ok:hover { border-color: var(--ok); background: var(--ok-bg); }
.ia.danger { color: var(--err); } .ia.danger:hover { border-color: var(--err); background: var(--err-bg, #fee2e2); }
.ia.accent { color: #a16207; } .ia.accent:hover { border-color: #eab308; background: #fef9c3; }
.ia:disabled { opacity: .45; cursor: not-allowed; }
.ia[data-tip]:hover::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); right: 0; background: #0f172a; color: #fff; font-size: 12px; padding: 4px 8px; border-radius: 6px; white-space: nowrap; z-index: 20; pointer-events: none; }
</style>
