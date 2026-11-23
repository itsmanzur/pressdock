import * as db from './db.js';
import { connect, Api } from './api.js';
import { h, buildForm } from './forms.js';
import { cachedMeta, loadMeta, syncAll, keepMine, useServer, pull } from './sync.js';
import { listRecords, saveRecord, deleteRecord, labelOf, statusOf, effective, pendingCount } from './store.js';

const app = document.getElementById('app');
// Replace the page content; accepts nested arrays and skips empty values.
const show = (...nodes) => app.replaceChildren(...nodes.flat(Infinity).filter((n) => n != null && n !== false));
const SINGLETONS = new Set(['kafela_receipt', 'kafela_settings']);
const st = { api: null, meta: null, tab: null, search: '', busy: '', msg: null };

const readCfg = () => { try { return JSON.parse(localStorage.getItem('pdock_cfg')); } catch (e) { return null; } };
const say = (text, bad = false) => { st.msg = text ? { text, bad } : null; };

async function boot() {
	if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
	window.addEventListener('online', () => st.api && doSync());
	window.addEventListener('offline', () => render());

	const cfg = readCfg();
	if (!cfg) return loginView();
	st.api = new Api(cfg);
	st.api.mode = cfg.mode || 'pretty';
	st.meta = await cachedMeta();
	if (st.meta) {
		st.tab = st.meta.entities[0] && st.meta.entities[0].key;
		render();
		if (navigator.onLine) doSync();
	} else {
		await firstSync();
	}
}

// ---- login ---------------------------------------------------------------

function loginView(error) {
	const site = h('input', { type: 'url', placeholder: 'https://example.com', value: localStorage.getItem('pdock_site') || '' });
	const user = h('input', { type: 'text', placeholder: 'WordPress username', autocomplete: 'username' });
	const pass = h('input', { type: 'password', placeholder: 'Application Password', autocomplete: 'current-password' });
	const go = h('button', { class: 'primary', onclick: async () => {
		go.disabled = true;
		try {
			const cfg = { site: site.value.trim(), user: user.value.trim(), pass: pass.value };
			const { api, hello } = await connect(cfg);
			localStorage.setItem('pdock_cfg', JSON.stringify({ ...cfg, mode: api.mode }));
			localStorage.setItem('pdock_site', cfg.site);
			await db.wipe();
			st.api = api;
			await db.put('meta', hello, 'hello');
			await firstSync();
		} catch (e) {
			go.disabled = false;
			loginView(e.message);
		}
	} }, 'Connect');
	show(
		h('header', { class: 'bar' }, h('h1', {}, 'Pressdock')),
		h('div', { class: 'card' },
			h('p', { class: 'muted' }, 'Create an Application Password under Users → Profile on your site. The site must use HTTPS.'),
			h('label', { class: 'field' }, h('span', {}, 'Site address'), site),
			h('label', { class: 'field' }, h('span', {}, 'Username'), user),
			h('label', { class: 'field' }, h('span', {}, 'Application Password'), pass),
			error ? h('div', { class: 'msg bad' }, error) : null,
			go
		)
	);
}

async function firstSync() {
	st.busy = 'Loading…';
	render();
	try {
		st.meta = await loadMeta(st.api);
		st.tab = st.meta.entities[0] && st.meta.entities[0].key;
		await pull(st.api, (n) => { st.busy = `Downloading… ${n}`; render(); });
		st.busy = '';
		render();
	} catch (e) {
		st.busy = '';
		if (e.status === 401) { localStorage.removeItem('pdock_cfg'); return loginView(e.message); }
		say(e.message, true);
		render();
	}
}

async function doSync() {
	if (st.busy) return;
	st.busy = 'Syncing…';
	render();
	try {
		st.meta = await loadMeta(st.api).catch(() => st.meta);
		const r = await syncAll(st.api, st.meta.schemas, (t) => { st.busy = `Syncing… ${t}`; render(); });
		say(r ? `Synced: ${r.pushed} sent, ${r.pulled} received.` : '');
	} catch (e) {
		say(e.network ? 'Offline. Changes stay on this device.' : e.message, !e.network);
	}
	st.busy = '';
	render();
}

// ---- list ----------------------------------------------------------------

async function render() {
	if (!st.meta) return;
	const ents = st.meta.entities;
	const ops = await db.all('ops');
	const ent = ents.find((e) => e.key === st.tab) || ents[0];
	const schema = ent ? st.meta.schemas[ent.key] : {};
	const recs = ent ? await listRecords(ent.key) : [];
	const q = st.search.toLowerCase();
	const shown = recs
		.filter((r) => !q || labelOf(r, schema).toLowerCase().includes(q))
		.sort((a, b) => (b.remote_id ?? 1e12) - (a.remote_id ?? 1e12));

	const canCreate = ent && ent.actions.create && !(SINGLETONS.has(ent.key) && recs.length);
	const online = navigator.onLine;

	show(
		h('header', { class: 'bar' },
			h('h1', {}, st.meta.hello.site.name),
			h('span', { class: 'muted' }, online ? '' : 'offline'),
			h('button', { class: 'primary', disabled: !!st.busy || !online, onclick: doSync }, st.busy || `Sync${ops.length ? ` (${ops.length})` : ''}`),
			h('button', { onclick: historyView }, '⋯')
		),
		st.msg ? h('div', { class: 'msg' + (st.msg.bad ? ' bad' : '') }, st.msg.text) : null,
		h('div', { class: 'tabs' }, ents.map((e) => h('button', { class: e.key === ent.key ? 'on' : '', onclick: () => { st.tab = e.key; st.search = ''; render(); } }, e.label))),
		h('input', { type: 'text', placeholder: 'Search', value: st.search, oninput: (e) => { st.search = e.target.value; render().then(() => { const i = app.querySelector('input[type=text]'); if (i) { i.focus(); i.setSelectionRange(i.value.length, i.value.length); } }); } }),
		shown.length ? shown.map((r) => itemEl(r, schema, ops)) : h('p', { class: 'muted' }, 'No records.'),
		canCreate ? h('button', { class: 'primary fab', onclick: () => editView(ent, null), 'aria-label': 'New' }, '+') : null
	);
}

function itemEl(r, schema, ops) {
	const s = statusOf(r, ops);
	const d = effective(r);
	const sub = Object.entries(schema).filter(([k, f]) => ['date', 'enum', 'int'].includes(f.type) && d[k] !== '' && d[k] != null).slice(0, 2).map(([k]) => d[k]).join(' · ');
	return h('div', { class: 'card item', onclick: () => editView(st.meta.entities.find((e) => e.key === r.entity), r) },
		h('div', { class: 'row' },
			h('div', { class: 'grow' }, h('div', { class: 't' }, labelOf(r, schema)), sub ? h('div', { class: 'muted' }, sub) : null),
			h('span', { class: `chip ${s}` }, s)
		)
	);
}

// ---- edit ----------------------------------------------------------------

async function relationOptions(schema) {
	const targets = new Set();
	const walk = (sch) => Object.values(sch).forEach((f) => { if (f.type === 'relation' && f.target) targets.add(f.target); if (f.properties) walk(f.properties); });
	walk(schema);
	const out = {};
	for (const t of targets) {
		const tschema = (st.meta.schemas[t]) || {};
		out[t] = (await listRecords(t)).map((r) => ({ value: r.remote_id ?? r.uuid, label: labelOf(r, tschema) }));
	}
	return out;
}

async function editView(ent, r) {
	const schema = st.meta.schemas[ent.key];
	const rel = await relationOptions(schema);
	const canWrite = r ? ent.actions.update : ent.actions.create;
	const form = buildForm(schema, r ? effective(r) : {}, rel, { readOnlyAll: !canWrite });
	const note = h('div');
	const showErr = (m) => note.replaceChildren(h('div', { class: 'msg bad' }, m));

	const save = h('button', { class: 'primary', disabled: !canWrite, onclick: async () => {
		const bad = form.invalid();
		if (bad) return showErr(bad);
		await saveRecord(ent.key, r, { ...(r ? effective(r) : {}), ...form.read() }, schema);
		say('Saved on this device.');
		await render();
		if (navigator.onLine) doSync();
	} }, 'Save');

	const remove = r && ent.actions.delete ? h('button', { class: 'danger', onclick: async () => {
		if (!confirm('Delete this record?')) return;
		await deleteRecord(r, false);
		await render();
		if (navigator.onLine) doSync();
	} }, 'Delete') : null;

	show(
		h('header', { class: 'bar' }, h('button', { onclick: render }, '←'), h('h1', {}, r ? labelOf(r, schema) : `New · ${ent.label}`)),
		r && r.error ? h('div', { class: 'msg bad' }, `${r.error.message}${r.error.field ? ` (${r.error.field})` : ''}`) : null,
		r && r.conflict ? conflictEl(r, schema) : null,
		note,
		form.el,
		h('div', { class: 'row' }, save, remove)
	);
}

function conflictEl(r, schema) {
	const c = r.conflict;
	const mine = c.mine || {};
	const server = c.server && !c.server.deleted ? c.server.data || {} : null;
	const rows = (obj) => h('pre', {}, obj ? JSON.stringify(obj, null, 2) : 'Deleted on the server');
	const unique = c.reason === 'unique';
	return h('div', { class: 'card' },
		h('strong', {}, unique ? 'A record with this value already exists.' : 'Changed on the server since you last synced.'),
		h('div', { class: 'cols' },
			h('div', {}, h('div', { class: 'muted' }, 'Mine'), rows(c.kind === 'delete' ? { delete: true } : mine)),
			h('div', {}, h('div', { class: 'muted' }, 'Server'), rows(server))
		),
		h('div', { class: 'row' },
			h('button', { class: 'primary', onclick: async () => { await keepMine(r); say('Will be re-sent on the next sync.'); await render(); if (navigator.onLine) doSync(); } }, c.kind === 'delete' ? 'Delete anyway' : 'Keep mine'),
			h('button', { onclick: async () => { await useServer(r); await render(); } }, 'Use server')
		)
	);
}

// ---- history / undo / sign out ---------------------------------------------

async function historyView() {
	const batches = (await db.all('batches')).sort((a, b) => b.at - a.at).slice(0, 20);
	const body = h('div');
	show(
		h('header', { class: 'bar' }, h('button', { onclick: render }, '←'), h('h1', {}, 'Sync history')),
		h('p', { class: 'muted' }, `Signed in as ${st.meta.hello.user.display_name}. Undo needs an administrator account.`),
		body,
		batches.length ? batches.map((b) => h('div', { class: 'card' },
			h('div', { class: 'row' },
				h('div', { class: 'grow' }, new Date(b.at).toLocaleString(), h('div', { class: 'muted' }, `${b.applied}/${b.total} applied`)),
				h('button', { onclick: async () => {
					if (!confirm('Undo this batch on the server?')) return;
					try {
						const r = await st.api.undo(b.batch_id);
						await db.del('batches', b.batch_id);
						await pull(st.api);
						say(`Undone: ${r.restored} restored.`);
						await render();
					} catch (e) { body.replaceChildren(h('div', { class: 'msg bad' }, e.message)); }
				} }, 'Undo')
			))) : h('p', { class: 'muted' }, 'Nothing yet.'),
		h('button', { class: 'danger', onclick: async () => {
			const left = await pendingCount();
			if (!confirm(left ? `${left} change(s) are not synced and will be lost. Sign out?` : 'Sign out and clear local data?')) return;
			localStorage.removeItem('pdock_cfg');
			await db.wipe();
			st.meta = null;
			loginView();
		} }, 'Sign out')
	);
}

boot();
