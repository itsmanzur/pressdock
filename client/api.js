// Pressdock REST client (Application Password, HTTP Basic).
export class ApiError extends Error {
	constructor(message, { status = 0, code = '', network = false } = {}) {
		super(message);
		this.status = status;
		this.code = code;
		this.network = network;
	}
}

export class Api {
	constructor({ site, user, pass, mode = 'pretty' }) {
		this.site = site.replace(/\/+$/, '');
		this.user = user;
		this.mode = mode;
		this.auth = 'Basic ' + btoa(unescape(encodeURIComponent(user + ':' + pass)));
	}

	url(path, query) {
		const qs = query ? new URLSearchParams(query).toString() : '';
		if (this.mode === 'plain') {
			return `${this.site}/?rest_route=/pressdock/v1${path}${qs ? '&' + qs : ''}`;
		}
		return `${this.site}/wp-json/pressdock/v1${path}${qs ? '?' + qs : ''}`;
	}

	async request(method, path, { query, body } = {}) {
		let res;
		try {
			res = await fetch(this.url(path, query), {
				method,
				headers: {
					Authorization: this.auth,
					Accept: 'application/json',
					...(body ? { 'Content-Type': 'application/json' } : {}),
				},
				body: body ? JSON.stringify(body) : undefined,
			});
		} catch (e) {
			throw new ApiError('Cannot reach the site (offline?).', { network: true });
		}
		let data = null;
		try {
			data = await res.json();
		} catch (e) { /* not JSON */ }
		if (!res.ok) {
			throw new ApiError((data && data.message) || `HTTP ${res.status}`, { status: res.status, code: (data && data.code) || '' });
		}
		return data;
	}

	handshake() { return this.request('GET', '/handshake'); }
	entities() { return this.request('GET', '/entities'); }
	pull(since, limit = 200) { return this.request('GET', '/pull', { query: { since, limit } }); }
	push(batch_id, ops) { return this.request('POST', '/push', { body: { batch_id, ops } }); }
	undo(batch_id) { return this.request('POST', '/undo', { body: { batch_id } }); }
}

// Pretty permalinks first, then ?rest_route= for sites with plain permalinks.
export async function connect(cfg) {
	let api = new Api({ ...cfg, mode: 'pretty' });
	try {
		return { api, hello: await api.handshake() };
	} catch (e) {
		if (e.status !== 404) throw e;
	}
	api = new Api({ ...cfg, mode: 'plain' });
	return { api, hello: await api.handshake() };
}
