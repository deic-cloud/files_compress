/**
 * "Compress" and "Extract here" file actions, registered via the official
 * @nextcloud/files API (this file is bundled — registerFileAction is not
 * exposed to plain JS). The heavy lifting (shell zip/unzip/tar) happens
 * server-side; here we call the OCS endpoints and insert the resulting node
 * into the current view (no full page reload).
 */
import { registerFileAction } from '@nextcloud/files'
import { getClient, getRootPath, getDefaultPropfind, resultToNode } from '@nextcloud/files/dav'
import { emit } from '@nextcloud/event-bus'
import { translate as t } from '@nextcloud/l10n'
import { showError, showInfo, showSuccess } from './toast'

const OCS = (window.OC?.webroot || '') + '/ocs/v2.php/apps/files_compress/api/v1'

// Files we offer "Extract here" on. tar.gz/tbz handled before the plain gz/bz2.
const ARCHIVE_RE = /\.(zip|tar|tar\.gz|tgz|tar\.bz2|tbz2|tbz|gz|bz2)$/i

const ICON_COMPRESS = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M3,3H21V7H3V3M4,8H20V21H4V8M9.5,11A0.5,0.5 0 0,0 9,11.5V13H15V11.5A0.5,0.5 0 0,0 14.5,11H9.5Z"/></svg>'
const ICON_EXTRACT = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
	+ '<path fill="currentColor" d="M19,20H4C2.89,20 2,19.1 2,18V6C2,4.89 2.89,4 4,4H10L12,6H19A2,2 0 0,1 21,8H21L4,8V18L6.14,10H23.21L20.93,18.5C20.7,19.37 19.92,20 19,20Z"/></svg>'

function ocsPost(path, params) {
	return fetch(OCS + path + '?format=json', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded',
			'OCS-APIREQUEST': 'true',
			requesttoken: window.OC.requestToken,
		},
		body: params,
	}).then((r) => r.json())
}

function isArchive(node) {
	return ARCHIVE_RE.test((node?.basename || node?.displayname || '').toLowerCase())
}

// We wrote the new file/folder straight to disk (bypassing the WebDAV write
// API) and scanned it server-side. Rather than reload the whole page, fetch
// just the new node over WebDAV and announce it so the Files view inserts it
// in place.
//
// Deriving the node from the *parent folder we were given* keeps it the same
// shape as its siblings — including custom views (user_group_admin grant
// folders), whose member nodes ride the standard DAV tree. We PROPFIND the
// child under the parent's own DAV source rather than rebuilding the path from
// the root, so we never double a prefix or land outside the view. If anything
// fails we fall back to a reload.
async function announce(folder, name) {
	try {
		// Parent's DAV URL + the new child; getClient() resolves relative to the
		// DAV root, so derive a root-relative path from the parent's source.
		const base = (folder?.source || '').replace(/\/+$/, '')
		const davRoot = getRootPath().replace(/\/+$/, '')
		const marker = '/remote.php/dav'
		let rel
		if (base && base.includes(marker)) {
			rel = base.slice(base.indexOf(marker) + marker.length) // e.g. /files/alice/.uga_grants/gid/sub
		} else {
			const dir = folder?.path && folder.path !== '/' ? folder.path : ''
			rel = `${davRoot}${dir}`
		}
		const davPath = `${rel}/${name}`.replace(/\/{2,}/g, '/')
		const res = await getClient().stat(davPath, { details: true, data: getDefaultPropfind() })
		emit('files:node:created', resultToNode(res.data))
	} catch (e) {
		console.warn('[files_compress] node announce failed', e)
		// A full reload inside a grant view drops out of the custom view and
		// leaks the raw .uga_grants breadcrumb, so never reload there — the
		// archive simply shows on the next refresh. Elsewhere a reload is safe.
		const src = folder?.source || ''
		const isGrantView = /\.uga_grants|\/Grants(\/|$)|\/remote\.php\/user_group_admin\//.test(src)
		if (!isGrantView) {
			window.location.reload()
		}
	}
}

async function handle(res, okMsg, folder) {
	const meta = res?.ocs?.meta
	if (meta && meta.status === 'ok') {
		const name = res?.ocs?.data?.name
		if (name) { await announce(folder, name) }
		showSuccess(okMsg)
	} else {
		showError(res?.ocs?.data?.message || (meta && meta.message) || t('files_compress', 'Operation failed.'))
	}
}

async function doCompress(nodes, folder) {
	const ids = (nodes || []).map((n) => n.fileid).filter(Boolean)
	if (!ids.length) {
		showError(t('files_compress', 'Nothing selected to compress.'))
		return
	}
	showInfo(t('files_compress', 'Compressing…'))
	const body = new URLSearchParams()
	ids.forEach((id) => body.append('fileids[]', String(id)))
	await handle(await ocsPost('/compress', body), t('files_compress', 'Archive created.'), folder)
}

async function doExtract(node, folder) {
	if (!node?.fileid) {
		showError(t('files_compress', 'Archive not found.'))
		return
	}
	showInfo(t('files_compress', 'Extracting…'))
	await handle(await ocsPost('/extract', new URLSearchParams({ fileid: String(node.fileid) })), t('files_compress', 'Archive extracted.'), folder)
}

async function guard(fn) {
	try {
		await fn()
	} catch (e) {
		showError(t('files_compress', 'Operation failed') + ': ' + (e && e.message ? e.message : e))
		console.error('[files_compress]', e)
	}
}

registerFileAction({
	id: 'files-compress-compress',
	displayName: () => t('files_compress', 'Compress'),
	title: () => t('files_compress', 'Create a zip archive of the selection'),
	iconSvgInline: () => ICON_COMPRESS,
	enabled: ({ nodes }) => Array.isArray(nodes) && nodes.length > 0,
	exec: async ({ nodes, folder }) => { await guard(() => doCompress(nodes, folder)); return null },
	execBatch: async ({ nodes, folder }) => { await guard(() => doCompress(nodes, folder)); return nodes.map(() => null) },
	order: 30,
})

registerFileAction({
	id: 'files-compress-extract',
	displayName: () => t('files_compress', 'Extract here'),
	title: () => t('files_compress', 'Extract this archive into the current folder'),
	iconSvgInline: () => ICON_EXTRACT,
	enabled: ({ nodes }) => Array.isArray(nodes) && nodes.length === 1 && isArchive(nodes[0]),
	exec: async ({ nodes, folder }) => { await guard(() => doExtract(nodes[0], folder)); return null },
	order: 31,
})
