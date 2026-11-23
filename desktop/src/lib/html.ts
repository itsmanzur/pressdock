const BLOCKED = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'LINK', 'META', 'BASE', 'FORM']);

/** Strip active content from HTML before it goes into the editor (defense in depth next to the app CSP). */
export function sanitizeHtml(html: string): string {
  const doc = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
  const walk = (node: Element) => {
    for (const child of Array.from(node.children)) {
      if (BLOCKED.has(child.tagName)) {
        child.remove();
        continue;
      }
      for (const attr of Array.from(child.attributes)) {
        const name = attr.name.toLowerCase();
        const val = attr.value.trim().toLowerCase();
        if (name.startsWith('on') || ((name === 'href' || name === 'src' || name === 'xlink:href') && /^(javascript|data):/.test(val))) {
          child.removeAttribute(attr.name);
        }
      }
      walk(child);
    }
  };
  walk(doc.body);
  return doc.body.innerHTML;
}

export function htmlToText(html: string): string {
  return html
    .replace(/<[^>]*>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/\s+/g, ' ')
    .trim();
}

/** 'YYYY-MM-DD HH:mm:ss' (server GMT) <-> 'YYYY-MM-DDTHH:mm' (datetime-local); no timezone shifting. */
export const toLocalInput = (v: unknown): string =>
  typeof v === 'string' && v ? v.replace(' ', 'T').slice(0, 16) : '';
export const fromLocalInput = (v: string): string => (v ? v.replace('T', ' ') + ':00' : '');

export function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const r = new FileReader();
    r.onerror = () => reject(r.error);
    r.onload = () => resolve(String(r.result).split(',')[1] ?? '');
    r.readAsDataURL(file);
  });
}

export const formatBytes = (n: number): string =>
  n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : `${(n / 1048576).toFixed(1)} MB`;
