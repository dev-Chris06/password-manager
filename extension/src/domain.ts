// Which vault entries may be filled on which page (docs/PLAN.md phase 7).

/**
 * Public suffixes made of two labels, frequent enough to deserve a guard: an entry for one of them
 * must never cover all its subdomains. (A complete Public Suffix List is not bundled.)
 */
const MULTI_LABEL_PUBLIC_SUFFIXES = new Set([
  'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'co.jp', 'ne.jp',
  'com.br', 'com.mx', 'com.ar', 'com.tr', 'co.za', 'co.in', 'co.kr', 'com.cn', 'com.tw', 'com.sg',
  'gouv.fr', 'asso.fr', 'co.ma', 'com.tn', 'github.io', 'gitlab.io', 'netlify.app', 'vercel.app',
  'herokuapp.com', 'pages.dev', 'workers.dev', 'blogspot.com', 'azurewebsites.net', 'cloudfront.net',
]);

interface SiteAddress {
  readonly host: string;
  /** Explicit port of the entry, or '' for the default port of the scheme. */
  readonly port: string;
}

/** Address of a site as typed in an entry ("https://www.example.com:8443/login" → example.com, 8443). */
export function siteAddress(site: string): SiteAddress | null {
  const trimmed = site.trim().toLowerCase();
  if (trimmed === '') {
    return null;
  }
  try {
    const url = new URL(/^[a-z][a-z0-9+.-]*:\/\//.test(trimmed) ? trimmed : `https://${trimmed}`);
    return { host: stripWww(url.hostname), port: url.port };
  } catch {
    return null;
  }
}

/** Host name of a site as typed in an entry ("https://www.example.com/login" → "example.com"). */
export function siteHost(site: string): string | null {
  return siteAddress(site)?.host ?? null;
}

function stripWww(host: string): string {
  return host.replace(/^www\./, '').replace(/\.$/, '');
}

/**
 * Exact host (and port) match by default; subdomains only when the entry allows it, and never
 * below a one-label name ("com") or a known public suffix ("co.uk", "github.io"…).
 */
export function entryMatchesHost(site: string, includeSubdomains: boolean, page: URL): boolean {
  const entry = siteAddress(site);
  const host = stripWww(page.hostname.toLowerCase());
  if (entry === null || host === '' || entry.port !== page.port) {
    return false;
  }
  if (entry.host === host) {
    return true;
  }
  return (
    includeSubdomains &&
    entry.host.includes('.') &&
    !MULTI_LABEL_PUBLIC_SUFFIXES.has(entry.host) &&
    host.endsWith(`.${entry.host}`)
  );
}

/** Only HTTPS pages (and local development over HTTP) can receive credentials. */
export function isFillablePage(url: string): boolean {
  try {
    const parsed = new URL(url);
    return parsed.protocol === 'https:' || (parsed.protocol === 'http:' && ['localhost', '127.0.0.1'].includes(parsed.hostname));
  } catch {
    return false;
  }
}

/** Server address accepted by the extension: HTTPS, or HTTP on the local machine. */
export function normalizeServerUrl(value: string): string | null {
  try {
    const url = new URL(value.trim());
    const local = ['localhost', '127.0.0.1'].includes(url.hostname);
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && local)) {
      return null;
    }
    return url.origin;
  } catch {
    return null;
  }
}
