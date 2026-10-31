// Service worker of the extension: it alone talks to the server, derives the keys and decrypts.
// The popup only sends requests; web pages never receive anything but the filled values.
import {
  assertKdfParameters,
  base64ToBytes,
  bytesToBase64,
  decryptEntry,
  deriveAccountKeys,
  encryptEntry,
  isKdfDowngrade,
  type KdfParameters,
  newEntryUuid,
  parseSecretKey,
  unwrapVaultKey,
  wipe,
} from '../../frontend/packages/crypto/src';
import { entryMatchesHost, isFillablePage, normalizeServerUrl } from './domain';
import { fillInPage, type FillResult } from './fill';
import { isPopupRequest, type PopupRequest, type Status, type TabEntry, type WorkerResponse } from './messages';

const AUTO_LOCK_MINUTES = 15;
const LOCK_ALARM = 'auto-lock';

interface EncryptedEntry {
  uuid: string;
  ciphertext: string;
  version: number;
}

interface VaultResponse {
  email: string;
  kdf: KdfParameters;
  salt: string;
  wrappedVaultKey: string;
  entries: EncryptedEntry[];
}

interface LocalState {
  serverUrl?: string;
  email?: string;
  secretKeys?: Record<string, string>;
  knownKdf?: Record<string, KdfParameters>;
}

/** Kept in chrome.storage.session: memory only, cleared when the browser closes. */
interface SessionState {
  vaultKey?: string;
  /** Wrapped form of the vault key in memory: a different one on the server means it was renewed. */
  wrappedVaultKey?: string;
  entries?: EncryptedEntry[];
}

class ApiFailure extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    readonly retryAfter: number | null,
  ) {
    super(code);
  }
}

// Between the proof and the second factor (kept in memory only).
let pending: { email: string; wrapKey: CryptoKey; secretKey: string; kdf: KdfParameters } | null = null;
let csrfToken = '';

void chrome.storage.session.setAccessLevel({ accessLevel: 'TRUSTED_CONTEXTS' });

const local = (): Promise<LocalState> => chrome.storage.local.get<LocalState>(null);
const session = (): Promise<SessionState> => chrome.storage.session.get<SessionState>(null);

async function serverUrl(): Promise<string> {
  const { serverUrl: url } = await local();
  if (url === undefined) {
    throw new ApiFailure(0, 'not_configured', null);
  }
  return url;
}

async function api<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { 'X-PM-Client': '1', Accept: 'application/json' };
  if (body !== undefined) {
    if (csrfToken === '') {
      await refreshCsrf();
    }
    headers['Content-Type'] = 'application/json';
    headers['X-CSRF-Token'] = csrfToken;
  }
  const response = await fetch((await serverUrl()) + path, {
    method,
    headers,
    credentials: 'include',
    cache: 'no-store',
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  });
  const data: unknown = await response.json().catch(() => ({}));
  if (!response.ok) {
    const code = typeof data === 'object' && data !== null && 'error' in data && typeof data.error === 'string' ? data.error : 'error';
    const retry = response.headers.get('Retry-After');
    throw new ApiFailure(response.status, code, retry === null ? null : Number.parseInt(retry, 10));
  }
  return data as T;
}

async function refreshCsrf(): Promise<{ authenticated: boolean; email: string | null }> {
  const state = await api<{ authenticated: boolean; email: string | null; csrfToken: string }>('GET', '/api/session');
  csrfToken = state.csrfToken;
  return state;
}

async function status(): Promise<Status> {
  const state = await local();
  const vault = await session();
  return {
    serverUrl: state.serverUrl ?? null,
    email: state.email ?? null,
    unlocked: vault.vaultKey !== undefined,
    pendingSecondFactor: pending !== null,
    knowsSecretKey: state.email !== undefined && state.secretKeys?.[state.email] !== undefined,
  };
}

async function checkKdf(email: string, kdf: unknown): Promise<KdfParameters> {
  assertKdfParameters(kdf);
  const known = (await local()).knownKdf?.[email];
  if (known !== undefined && isKdfDowngrade(known, kdf)) {
    throw new ApiFailure(0, 'kdf_downgrade', null);
  }
  return kdf;
}

async function unlockVault(request: Extract<PopupRequest, { type: 'unlock' }>): Promise<WorkerResponse> {
  const email = request.email.trim().toLowerCase();
  const state = await local();
  const secretKey = request.secretKey ?? state.secretKeys?.[email];
  if (secretKey === undefined || secretKey === '') {
    return { ok: false, error: 'secret_key_required' };
  }
  const secretKeyBytes = parseSecretKey(secretKey);

  const current = await refreshCsrf();
  if (current.authenticated && current.email === email) {
    // The browser already has a complete session (shared with the web app): unlock locally.
    const vault = await api<VaultResponse>('GET', '/api/vault');
    const kdf = await checkKdf(email, vault.kdf);
    const { wrapKey } = await deriveAccountKeys(request.password, secretKeyBytes, base64ToBytes(vault.salt), kdf);
    await finishUnlock(email, wrapKey, secretKey, kdf);
    return { ok: true, step: 'done' };
  }

  const prelogin = await api<{ kdf: unknown; salt: string }>('POST', '/api/auth/prelogin', { email });
  const kdf = await checkKdf(email, prelogin.kdf);
  const { wrapKey, authProof } = await deriveAccountKeys(request.password, secretKeyBytes, base64ToBytes(prelogin.salt), kdf);
  const result = await api<{ step: string }>('POST', '/api/auth/login', { email, authProof });
  await refreshCsrf();

  if (result.step === '2fa') {
    pending = { email, wrapKey, secretKey, kdf };
    return { ok: true, step: '2fa' };
  }
  if (result.step !== 'done') {
    // The mandatory two-factor enrollment happens in the web app.
    return { ok: false, error: 'two_factor_setup_required' };
  }
  await finishUnlock(email, wrapKey, secretKey, kdf);
  return { ok: true, step: 'done' };
}

async function secondFactor(request: Extract<PopupRequest, { type: 'second-factor' }>): Promise<WorkerResponse> {
  if (pending === null) {
    return { ok: false, error: 'session_expired' };
  }
  const answer = request.backupCode !== undefined ? { backupCode: request.backupCode } : { code: request.code ?? '' };
  try {
    await api('POST', '/api/auth/2fa/check', answer);
  } catch (error) {
    if (error instanceof ApiFailure && error.status === 429) {
      pending = null;
    }
    throw error;
  }
  await refreshCsrf();
  const { email, wrapKey, secretKey, kdf } = pending;
  pending = null;
  await finishUnlock(email, wrapKey, secretKey, kdf);
  return { ok: true, step: 'done' };
}

async function finishUnlock(email: string, wrapKey: CryptoKey, secretKey: string, kdf: KdfParameters): Promise<void> {
  const vault = await api<VaultResponse>('GET', '/api/vault');
  // Extractable only to be kept in the memory-only session storage between service worker restarts.
  const vaultKey = await unwrapVaultKey(wrapKey, vault.wrappedVaultKey, { extractable: true });
  const raw = new Uint8Array(await crypto.subtle.exportKey('raw', vaultKey));
  await chrome.storage.session.set({ vaultKey: bytesToBase64(raw), wrappedVaultKey: vault.wrappedVaultKey, entries: vault.entries } satisfies SessionState);
  wipe(raw);

  const state = await local();
  await chrome.storage.local.set({
    email,
    secretKeys: { ...state.secretKeys, [email]: secretKey },
    knownKdf: { ...state.knownKdf, [email]: kdf },
  } satisfies LocalState);
  await scheduleLock();
}

async function lock(): Promise<void> {
  pending = null;
  await chrome.storage.session.clear();
  await chrome.alarms.clear(LOCK_ALARM);
}

async function scheduleLock(): Promise<void> {
  await chrome.alarms.create(LOCK_ALARM, { delayInMinutes: AUTO_LOCK_MINUTES });
}

async function vaultKey(): Promise<CryptoKey> {
  const { vaultKey: raw } = await session();
  if (raw === undefined) {
    throw new ApiFailure(0, 'locked', null);
  }
  const bytes = base64ToBytes(raw);
  try {
    return await crypto.subtle.importKey('raw', bytes, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
  } finally {
    wipe(bytes);
  }
}

/** Latest encrypted entries; the cached copy is used when the server cannot be reached. */
async function encryptedEntries(): Promise<EncryptedEntry[]> {
  let vault: VaultResponse;
  try {
    vault = await api<VaultResponse>('GET', '/api/vault');
  } catch (error) {
    if (error instanceof ApiFailure && error.status === 401) {
      throw error;
    }
    return (await session()).entries ?? [];
  }
  // The vault key was renewed elsewhere (master password change with rotation): the key held
  // here is obsolete. Lock instead of encrypting new entries with it.
  if (vault.wrappedVaultKey !== (await session()).wrappedVaultKey) {
    await lock();
    throw new ApiFailure(0, 'vault_key_changed', null);
  }
  await chrome.storage.session.set({ entries: vault.entries } satisfies SessionState);
  return vault.entries;
}

/** The page URL always comes from the browser, never from a message. */
async function fillableTab(tabId: number): Promise<{ url: URL } | null> {
  const tab = await chrome.tabs.get(tabId);
  if (tab.url === undefined || !isFillablePage(tab.url)) {
    return null;
  }
  return { url: new URL(tab.url) };
}

/** Entries matching one snapshot of the tab address (the same snapshot is used to fill). */
async function matchingEntries(url: URL): Promise<(TabEntry & { password: string })[]> {
  const key = await vaultKey();
  const matches = [];
  for (const entry of await encryptedEntries()) {
    try {
      const data = await decryptEntry(key, entry.uuid, entry.ciphertext);
      if (entryMatchesHost(data.site, data.includeSubdomains, url)) {
        matches.push({ uuid: entry.uuid, site: data.site, username: data.username, password: data.password });
      }
    } catch {
      // Unreadable entry: ignored.
    }
  }
  await scheduleLock();
  return matches;
}

async function fill(tabId: number, uuid: string): Promise<WorkerResponse> {
  // One snapshot of the address: the entry is matched against it and the page must still have
  // this exact origin when the values are injected (a navigation in between cancels the fill).
  const tab = await fillableTab(tabId);
  if (tab === null) {
    return { ok: false, error: 'domain_mismatch' };
  }
  const entry = (await matchingEntries(tab.url)).find((candidate) => candidate.uuid === uuid);
  if (entry === undefined) {
    return { ok: false, error: 'domain_mismatch' };
  }
  const [injection] = await chrome.scripting.executeScript({
    target: { tabId, allFrames: false },
    func: fillInPage,
    args: [entry.username, entry.password, tab.url.origin],
  });
  const result: FillResult | undefined = injection?.result;
  return result?.filled === true ? { ok: true } : { ok: false, error: result?.reason ?? 'fill_failed' };
}

async function add(request: Extract<PopupRequest, { type: 'add' }>): Promise<WorkerResponse> {
  const tab = await fillableTab(request.tabId);
  if (tab === null || request.password === '') {
    return { ok: false, error: 'invalid_payload' };
  }
  // Fails (and locks) if the vault key was renewed elsewhere.
  await encryptedEntries();
  const uuid = newEntryUuid();
  const ciphertext = await encryptEntry(await vaultKey(), uuid, {
    site: tab.url.hostname.replace(/^www\./, ''),
    username: request.username,
    password: request.password,
    category: 'other',
    notes: '',
    includeSubdomains: request.includeSubdomains,
  });
  await api('POST', '/api/entries', { uuid, ciphertext });
  await encryptedEntries();
  return { ok: true };
}

async function configure(value: string): Promise<WorkerResponse> {
  const url = normalizeServerUrl(value);
  if (url === null) {
    return { ok: false, error: 'invalid_server_url' };
  }
  const { serverUrl: previous } = await local();
  if (previous !== url) {
    await lock();
    csrfToken = '';
  }
  await chrome.storage.local.set({ serverUrl: url } satisfies LocalState);
  return { ok: true, status: await status() };
}

async function handle(request: PopupRequest): Promise<WorkerResponse> {
  switch (request.type) {
    case 'status':
      return { ok: true, status: await status() };
    case 'configure':
      return configure(request.serverUrl);
    case 'unlock':
      return unlockVault(request);
    case 'second-factor':
      return secondFactor(request);
    case 'lock':
      await lock();
      return { ok: true };
    case 'entries-for-tab': {
      const tab = await fillableTab(request.tabId);
      if (tab === null) {
        return { ok: true, host: '', fillable: false, entries: [] };
      }
      const entries = await matchingEntries(tab.url);
      return { ok: true, host: tab.url.host, fillable: true, entries: entries.map(({ uuid, site, username }) => ({ uuid, site, username })) };
    }
    case 'fill':
      return fill(request.tabId, request.uuid);
    case 'add':
      return add(request);
  }
}

chrome.runtime.onMessage.addListener((message: unknown, sender, sendResponse: (response: WorkerResponse) => void) => {
  // Only the extension's own pages (the popup) may talk to the service worker: never a web page,
  // a content script or another extension.
  const fromOwnPage = sender.id === chrome.runtime.id && sender.url?.startsWith(chrome.runtime.getURL('')) === true;
  if (!fromOwnPage || !isPopupRequest(message)) {
    sendResponse({ ok: false, error: 'forbidden' });
    return false;
  }
  handle(message)
    .then(sendResponse)
    .catch((error: unknown) => {
      if (error instanceof ApiFailure) {
        if (error.status === 401) {
          void lock();
        }
        sendResponse({ ok: false, error: error.code, retryAfter: error.retryAfter });
      } else {
        sendResponse({ ok: false, error: error instanceof Error ? error.name : 'error' });
      }
    });
  return true;
});

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === LOCK_ALARM) {
    void lock();
  }
});
