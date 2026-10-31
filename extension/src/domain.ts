// Which vault entries may be filled on which page (docs/PLAN.md phase 7).

/** Host name of a site as typed in an entry ("https://www.example.com/login" → "example.com"). */
export function siteHost(site: string): string | null {
  const trimmed = site.trim().toLowerCase();
  if (trimmed === '') {
    return null;
  }
  try {
    const url = new URL(/^[a-z][a-z0-9+.-]*:\/\//.test(trimmed) ? trimmed : `https://${trimmed}`);
    return stripWww(url.hostname);
  } catch {
    return null;
  }
}

function stripWww(host: string): string {
  return host.replace(/^www\./, '').replace(/\.$/, '');
}

/**
 * Exact host match by default; subdomains only when the entry allows it. A one-label site
 * (e.g. "com") never matches subdomains, so an entry cannot cover a whole top-level domain.
 */
export function entryMatchesHost(site: string, includeSubdomains: boolean, pageHost: string): boolean {
  const entryHost = siteHost(site);
  const host = stripWww(pageHost.toLowerCase());
  if (entryHost === null || host === '') {
    return false;
  }
  if (entryHost === host) {
    return true;
  }
  return includeSubdomains && entryHost.includes('.') && host.endsWith(`.${entryHost}`);
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
