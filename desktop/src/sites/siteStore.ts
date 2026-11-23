import { platform } from '../platform';
import { SiteRepo } from '../db/repo';

/** Site metadata only. The Application Password is NEVER part of this record. */
export interface Site {
  id: string;
  name: string;
  url: string;
  username: string;
  createdAt: string;
  wpSiteName?: string;
  lastConnectedAt?: string;
}

const KEY = 'sites';
const account = (id: string) => `site:${id}`;
const dbName = (id: string) => `site-${id}`;

export async function listSites(): Promise<Site[]> {
  return (await platform.kv.get<Site[]>(KEY)) ?? [];
}

async function writeSites(sites: Site[]) {
  await platform.kv.set(KEY, sites);
}

/** Insert or update a site. `password` is written to the OS keychain only when provided. */
export async function saveSite(site: Site, password?: string): Promise<void> {
  if (password) await platform.secrets.set(account(site.id), password);
  const sites = await listSites();
  const i = sites.findIndex((s) => s.id === site.id);
  if (i >= 0) sites[i] = site;
  else sites.push(site);
  await writeSites(sites);
}

export const getPassword = (id: string) => platform.secrets.get(account(id));

export async function removeSite(id: string): Promise<void> {
  try {
    const repo = await openRepo(id);
    await repo.wipe();
    await repo.db.close();
  } catch {
    /* database may be missing; nothing to wipe */
  }
  await platform.secrets.delete(account(id));
  await writeSites((await listSites()).filter((s) => s.id !== id));
  repoCache.delete(id);
}

const repoCache = new Map<string, Promise<SiteRepo>>();

/** One SQLite file per site (`site-<id>.db`), migrated on first open. */
export function openRepo(id: string): Promise<SiteRepo> {
  let p = repoCache.get(id);
  if (!p) {
    p = platform.openDb(dbName(id)).then((db) => SiteRepo.open(db));
    repoCache.set(id, p);
    p.catch(() => repoCache.delete(id));
  }
  return p;
}
