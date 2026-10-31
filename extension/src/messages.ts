// Messages between the popup and the service worker. No content script exists: web pages
// cannot send any of these messages.

export type PopupRequest =
  | { type: 'status' }
  | { type: 'configure'; serverUrl: string }
  | { type: 'unlock'; email: string; password: string; secretKey?: string | undefined }
  | { type: 'second-factor'; code?: string | undefined; backupCode?: string | undefined }
  | { type: 'lock' }
  | { type: 'entries-for-tab'; tabId: number }
  | { type: 'fill'; tabId: number; uuid: string }
  | { type: 'add'; tabId: number; username: string; password: string; includeSubdomains: boolean };

export interface Status {
  readonly serverUrl: string | null;
  readonly email: string | null;
  readonly unlocked: boolean;
  readonly pendingSecondFactor: boolean;
  readonly knowsSecretKey: boolean;
}

export interface TabEntry {
  readonly uuid: string;
  readonly site: string;
  readonly username: string;
}

export type WorkerResponse =
  | { ok: true; status?: Status; step?: 'done' | '2fa'; entries?: TabEntry[]; host?: string; fillable?: boolean }
  | { ok: false; error: string; retryAfter?: number | null };

export const MESSAGE_TYPES = ['status', 'configure', 'unlock', 'second-factor', 'lock', 'entries-for-tab', 'fill', 'add'] as const;

export function isPopupRequest(value: unknown): value is PopupRequest {
  return (
    typeof value === 'object' &&
    value !== null &&
    'type' in value &&
    typeof value.type === 'string' &&
    (MESSAGE_TYPES as readonly string[]).includes(value.type)
  );
}
