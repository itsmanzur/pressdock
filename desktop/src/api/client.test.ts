import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, PressdockApi } from './client';

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const html = (status = 200) => new Response('<html>page</html>', { status, headers: { 'Content-Type': 'text/html' } });
const HS = { site: { name: 'S', api_version: '1.0' }, user: { id: 1 } };

let n = 0;
const api = () => new PressdockApi({ url: `https://site${++n}.test`, username: 'u', password: 'p' });

afterEach(() => vi.unstubAllGlobals());

describe('REST URL form fallback', () => {
  it('uses /wp-json when it answers', async () => {
    const f = vi.fn(async (url: string) => (url.includes('/wp-json/') ? json(HS) : html()));
    vi.stubGlobal('fetch', f);
    const a = api();
    await a.handshake();
    await a.handshake();
    expect(f.mock.calls.every(([u]) => String(u).includes('/wp-json/'))).toBe(true);
  });

  it('falls back to ?rest_route on a CORS/network failure (plain permalinks in a browser) and remembers it', async () => {
    const f = vi.fn(async (url: string) => {
      if (url.includes('/wp-json/')) throw new TypeError('Failed to fetch');
      return json(HS);
    });
    vi.stubGlobal('fetch', f);
    const a = api();
    expect((await a.handshake()).site.name).toBe('S');
    await a.handshake();
    const urls = f.mock.calls.map(([u]) => String(u));
    expect(urls.filter((u) => u.includes('/wp-json/'))).toHaveLength(1); // second call goes straight to ?rest_route
    expect(urls[1]).toContain('/index.php?rest_route=%2Fpressdock%2Fv1%2Fhandshake');
  });

  it('falls back when /wp-json returns an HTML page (200) or a 404', async () => {
    for (const bad of [() => html(200), () => html(404), () => json({ code: 'rest_no_route' }, 404)]) {
      const f = vi.fn(async (url: string) => (url.includes('/wp-json/') ? bad() : json(HS)));
      vi.stubGlobal('fetch', f);
      expect((await api().handshake()).site.name).toBe('S');
      expect(f).toHaveBeenCalledTimes(2);
    }
  });

  it('a real network failure still reports "network"', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => { throw new TypeError('Failed to fetch'); }));
    await expect(api().handshake()).rejects.toMatchObject({ kind: 'network' } satisfies Partial<ApiError>);
  });

  it('auth errors are not retried through the other form', async () => {
    const f = vi.fn(async () => json({ code: 'pdock_not_authenticated', message: 'no' }, 401));
    vi.stubGlobal('fetch', f);
    await expect(api().handshake()).rejects.toMatchObject({ kind: 'auth' });
    expect(f).toHaveBeenCalledTimes(1);
  });
});
