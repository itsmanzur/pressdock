// Tiny promise wrapper over IndexedDB.
const NAME = 'pressdock-pwa';
let opened;

export function open() {
	if (!opened) {
		opened = new Promise((resolve, reject) => {
			const r = indexedDB.open(NAME, 1);
			r.onupgradeneeded = () => {
				const d = r.result;
				d.createObjectStore('meta');
				d.createObjectStore('records', { keyPath: 'key' }).createIndex('entity', 'entity');
				d.createObjectStore('ops', { keyPath: 'op_id' });
				d.createObjectStore('batches', { keyPath: 'batch_id' });
			};
			r.onsuccess = () => resolve(r.result);
			r.onerror = () => reject(r.error);
		});
	}
	return opened;
}

const wrap = (req) => new Promise((resolve, reject) => {
	req.onsuccess = () => resolve(req.result);
	req.onerror = () => reject(req.error);
});
const store = async (name, mode = 'readonly') => (await open()).transaction(name, mode).objectStore(name);

export const get = async (s, key) => wrap((await store(s)).get(key));
export const all = async (s) => wrap((await store(s)).getAll());
export const byEntity = async (entity) => wrap((await store('records')).index('entity').getAll(entity));
export const put = async (s, value, key) => wrap((await store(s, 'readwrite')).put(value, key));
export const del = async (s, key) => wrap((await store(s, 'readwrite')).delete(key));

export async function wipe() {
	const d = await open();
	await Promise.all(['meta', 'records', 'ops', 'batches'].map((n) => wrap(d.transaction(n, 'readwrite').objectStore(n).clear())));
}
