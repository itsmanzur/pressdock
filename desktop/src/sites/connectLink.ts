/**
 * "Open in Pressdock" links from the WordPress dashboard:
 *   https://<app>/#connect?url=<site url>&user=<username>
 * The data is in the URL fragment, so it never reaches the server that hosts the app.
 * Read once, then removed from the address bar.
 */
export interface ConnectLink {
  url: string;
  username: string;
}

let cached: ConnectLink | null | undefined;

function parse(): ConnectLink | null {
  if (typeof location === 'undefined' || !location.hash.startsWith('#connect')) return null;
  const q = new URLSearchParams(location.hash.slice(location.hash.indexOf('?') + 1));
  const url = (q.get('url') ?? '').trim();
  const username = (q.get('user') ?? '').trim();
  try {
    history.replaceState(null, '', location.pathname + location.search);
  } catch {
    /* ignore */
  }
  if (!/^https?:\/\/[^\s]+$/i.test(url) || url.length > 300 || username.length > 100) return null;
  return { url, username };
}

/** The pending link, without consuming it. */
export function peekConnectLink(): ConnectLink | null {
  if (cached === undefined) cached = parse();
  return cached;
}

/** The pending link; later calls return null. */
export function takeConnectLink(): ConnectLink | null {
  const link = peekConnectLink();
  cached = null;
  return link;
}
