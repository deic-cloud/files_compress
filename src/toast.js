/** Minimal toast via toastify-js (what NC uses under the hood) — avoids the
 *  heavy @nextcloud/dialogs Vue dependency for simple messages. */
import Toastify from 'toastify-js'
import 'toastify-js/src/toastify.css'

function toast(text, type) {
	Toastify({
		text,
		duration: type === 'error' ? 7000 : 4000,
		gravity: 'top',
		position: 'right',
		close: true,
		style: { background: type === 'error' ? 'var(--color-error, #e9322d)' : 'var(--color-primary-element, #0082c9)' },
	}).showToast()
}

export const showError = (t) => toast(t, 'error')
export const showInfo = (t) => toast(t, 'info')
export const showSuccess = (t) => toast(t, 'info')
