import { reactive } from 'vue'

/**
 * Interface commune de la console : notifications (toasts), boîtes de dialogue
 * (remplacent alert / confirm / prompt du navigateur) et indicateur de chargement.
 */
export const ui = reactive({ toasts: [], dialog: null, loading: 0 })

let seq = 0
/** Notification brève en bas à droite. type : ok | err | warn | info */
export function toast(message, type = 'ok', ms = 4500) {
  if (!message) return
  const id = ++seq
  // Pas deux fois le même message à l'écran
  if (ui.toasts.some((t) => t.message === message)) return
  ui.toasts.push({ id, message, type })
  setTimeout(() => dismiss(id), ms)
}
export function dismiss(id) {
  const i = ui.toasts.findIndex((t) => t.id === id)
  if (i >= 0) ui.toasts.splice(i, 1)
}

/**
 * Boîte de dialogue. Renvoie une promesse :
 *  - confirmation : true / false ;
 *  - avec `input` : le texte saisi, ou null si annulé.
 * ask({ title, message, confirmLabel, danger, input: { label, placeholder, required, multiline } })
 */
export function ask(opts = {}) {
  if (ui.dialog) ui.dialog.resolve(opts.input ? null : false)
  return new Promise((resolve) => {
    ui.dialog = {
      title: 'Confirmer',
      confirmLabel: opts.input ? 'Valider' : 'Confirmer',
      cancelLabel: 'Annuler',
      ...opts,
      value: opts.input?.value ?? '',
      resolve: (v) => { ui.dialog = null; resolve(v) },
    }
  })
}
/** Confirmation simple : await confirmBox('Supprimer ?', { danger: true }) */
export const confirmBox = (message, opts = {}) => ask({ message, ...opts })
/** Saisie d'un texte : await promptBox('Motif', { required: true }) → texte ou null */
export const promptBox = (label, opts = {}) => ask({ title: opts.title || label, message: opts.message, confirmLabel: opts.confirmLabel, danger: opts.danger,
  input: { label, placeholder: opts.placeholder, required: !!opts.required, multiline: opts.multiline ?? true, value: opts.value } })
