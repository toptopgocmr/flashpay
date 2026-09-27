<template>
  <Teleport to="body">
    <div class="modal-backdrop" @mousedown.self="$emit('close')">
      <div class="modal" role="dialog" :aria-label="title">
        <div class="modal-head">
          <div><h3>{{ title }}</h3><p v-if="subtitle">{{ subtitle }}</p></div>
          <button class="icon-btn" title="Fermer" @click="$emit('close')">
            <svg width="16" height="16" viewBox="0 0 16 16" stroke="currentColor" stroke-width="2"><path d="M4 4l8 8M12 4l-8 8"/></svg>
          </button>
        </div>
        <div class="modal-body"><slot /></div>
        <div class="modal-foot" v-if="$slots.foot"><slot name="foot" /></div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
defineProps({ title: String, subtitle: String })
defineEmits(['close'])
</script>

<style>
.modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); z-index: 100; display: flex; align-items: flex-start; justify-content: center; padding: 8vh 16px 16px; overflow-y: auto; }
.modal { background: #fff; border-radius: 14px; width: 100%; max-width: 560px; box-shadow: var(--shadow-lg); border: 1px solid var(--border); }
.modal-head { display: flex; justify-content: space-between; gap: 12px; padding: 18px 20px 12px; border-bottom: 1px solid var(--border); }
.modal-head h3 { margin: 0; font-size: 17px; }
.modal-head p { margin: 4px 0 0; color: var(--text-2); font-size: 13px; }
.modal-body { padding: 18px 20px; display: grid; gap: 14px; }
.modal-body .row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.modal-body input, .modal-body select { width: 100%; }
.modal-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 14px 20px; border-top: 1px solid var(--border); background: var(--surface-2); border-radius: 0 0 14px 14px; }
.section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--text-2); border-top: 1px solid var(--border); padding-top: 14px; }
.seg-types { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
.seg-types button { text-align: left; background: #fff; border: 1px solid var(--border-strong); border-radius: 10px; padding: 8px 10px; cursor: pointer; font: inherit; }
.seg-types button strong { display: block; font-size: 13px; }
.seg-types button small { color: var(--text-2); font-size: 11.5px; }
.seg-types button.on { border-color: var(--brand); box-shadow: 0 0 0 3px rgba(30, 58, 138, .12); background: var(--info-bg); }
@media (max-width: 600px) { .seg-types { grid-template-columns: 1fr 1fr; } }
.with-flag { display: flex; align-items: center; gap: 8px; }
.with-flag select { flex: 1; }
.creds { background: var(--surface-2); border: 1px dashed var(--border-strong); border-radius: 10px; padding: 14px 16px; display: grid; gap: 6px; }
.creds div { display: flex; justify-content: space-between; gap: 12px; }
.creds span { color: var(--text-2); }
@media (max-width: 600px) { .modal-body .row2 { grid-template-columns: 1fr; } }
</style>
