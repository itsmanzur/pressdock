import type { Entity, EntityActions, EntitySchema } from '../schema/types';
import { platform } from '../platform';
import type { PullResponse, PushOp, PushResponse, SyncApi } from '../sync/types';

export type ApiErrorKind =
  | 'network' // offline, DNS, TLS, refused
  | 'timeout'
  | 'auth' // 401
  | 'forbidden' // 403 (capability)
  | 'insecure' // 403 pdock_insecure_transport
  | 'not_installed' // Pressdock Connector missing on the site
  | 'http'
  | 'bad_response';

export class ApiError extends Error {
  constructor(
    readonly kind: ApiErrorKind,
    message: string,
    readonly status?: number,
    readonly code?: string,
  ) {
    super(message);
  }
}

export interface HandshakeEntity {
  key: string;
  label: string;
  group: string;
  actions: EntityActions;
}

export interface Handshake {
  site: {
    name: string;
    url: string;
    rest_url: string;
    wp_version: string;
    plugin_version: string;
    api_version: string;
    timezone: string;
  };
  user: { id: number; login: string; display_name: string };
  entities: HandshakeEntity[];
  cursor: number;
  limits: { max_ops: number; pull_default: number; pull_max: number };
  https: boolean;
  server_time: string;
}

export interface SiteCredentials {
  url: string;
  username: string;
  password: string;
}

export const normalizeSiteUrl = (raw: string): string => {
  let u = raw.trim();
  if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
  return u.replace(/\/+$/, '').replace(/\/wp-json.*$/i, '').replace(/\/wp-admin.*$/i, '');
};

const utf8b64 = (s: string) => {
  const bytes = new TextEncoder().encode(s);
  return btoa(Array.from(bytes, (b) => String.fromCharCode(b)).join(''));
};

const TIMEOUT_MS = 20_000;

/** Thin typed wrapper over the pressdock/v1 REST API (contract: docs/api-contract.md). */
export class PressdockApi implements SyncApi {
  private readonly base: string;
  private readonly auth: string;

  constructor(creds: SiteCredentials) {
    this.base = normalizeSiteUrl(creds.url);
    // Application Passwords are shown with spaces; WordPress accepts them either way.
    this.auth = 'Basic ' + utf8b64(`${creds.username}:${creds.password}`);
  }

  private async raw(url: string, init: RequestInit = {}): Promise<Response> {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), TIMEOUT_MS);
    try {
      return await platform.fetch(url, {
        ...init,
        signal: ctl.signal,
        headers: { Accept: 'application/json', Authorization: this.auth, ...(init.headers ?? {}) },
      });
    } catch (e) {
      if (ctl.signal.aborted) throw new ApiError('timeout', 'Request timed out');
      throw new ApiError('network', e instanceof Error ? e.message : String(e));
    } finally {
      clearTimeout(timer);
    }
  }

  /** Try pretty permalinks first, then the `?rest_route=` form for sites without them. */
  private async request<T>(route: string, query: Record<string, string | number> = {}, post?: unknown): Promise<T> {
    const qs = new URLSearchParams(Object.entries(query).map(([k, v]) => [k, String(v)])).toString();
    const init: RequestInit =
      post === undefined ? {} : { method: 'POST', body: JSON.stringify(post), headers: { 'Content-Type': 'application/json' } };
    const pretty = `${this.base}/wp-json/pressdock/v1/${route}${qs ? '?' + qs : ''}`;
    let res = await this.raw(pretty, init);
    let body = await this.readBody(res);
    const code = (body.json as { code?: string } | null)?.code;
    // no pretty permalinks: retry through ?rest_route=
    if (res.status === 404 && (body.json === null || code === 'rest_no_route')) {
      const ugly = `${this.base}/?rest_route=${encodeURIComponent('/pressdock/v1/' + route)}${qs ? '&' + qs : ''}`;
      res = await this.raw(ugly, init);
      body = await this.readBody(res);
    }
    return this.interpret<T>(res, body);
  }

  private async readBody(res: Response): Promise<{ text: string; json: unknown }> {
    const text = await res.text();
    try {
      return { text, json: JSON.parse(text) };
    } catch {
      return { text, json: null };
    }
  }

  private interpret<T>(res: Response, body: { text: string; json: unknown }): T {
    const j = body.json as { code?: string; message?: string } | null;
    if (res.ok) {
      // A 200 that is not JSON means this is not a WordPress REST endpoint (or the connector is absent).
      if (j === null) throw new ApiError('not_installed', 'The server did not return JSON.', res.status);
      return j as T;
    }
    const msg = j?.message || `HTTP ${res.status}`;
    if (res.status === 404 && (!j || j.code === 'rest_no_route')) {
      throw new ApiError('not_installed', msg, 404, j?.code);
    }
    if (res.status === 401 || j?.code === 'pdock_not_authenticated' || j?.code === 'incorrect_password') {
      throw new ApiError('auth', msg, res.status, j?.code);
    }
    if (j?.code === 'pdock_insecure_transport') throw new ApiError('insecure', msg, res.status, j.code);
    if (res.status === 403) throw new ApiError('forbidden', msg, res.status, j?.code);
    throw new ApiError('http', msg, res.status, j?.code);
  }

  push(batchId: string, ops: PushOp[]): Promise<PushResponse> {
    return this.request<PushResponse>('push', {}, { batch_id: batchId, ops });
  }

  pull(entity: string, since: number, limit: number): Promise<PullResponse> {
    return this.request<PullResponse>('pull', { entity, since, limit });
  }

  undo(batchId: string): Promise<{ restored: number; errors: unknown[] }> {
    return this.request('undo', {}, { batch_id: batchId });
  }

  handshake(): Promise<Handshake> {
    return this.request<Handshake>('handshake');
  }

  async entities(): Promise<Entity[]> {
    const r = await this.request<{
      entities: { key: string; label: string; group: string; actions: EntityActions; schema: EntitySchema }[];
    }>('entities');
    if (!r || !Array.isArray(r.entities)) throw new ApiError('bad_response', 'Unexpected /entities response');
    return r.entities.map((e) => ({
      key: e.key,
      label: e.label,
      group: e.group || '',
      actions: e.actions,
      schema: e.schema ?? {},
      cursor: null,
    }));
  }
}
