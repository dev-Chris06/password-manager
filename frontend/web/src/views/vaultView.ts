import { alertBox, el } from '../dom';

export interface VaultViewOptions {
  email: string;
  entryCount: number;
  backupCodesRemaining: number;
  onLock: () => void;
  onLogout: () => Promise<void>;
}

/** Placeholder of the unlocked vault; the full single-page vault comes with phase 5. */
export function vaultView(options: VaultViewOptions): HTMLElement {
  return el(
    'section',
    { className: 'card' },
    el('h1', {}, 'Coffre déverrouillé'),
    options.backupCodesRemaining <= 3
      ? alertBox('warning', `Il ne vous reste que ${String(options.backupCodesRemaining)} code(s) de secours : régénérez-les depuis les paramètres de sécurité.`)
      : null,
    el('p', { attrs: { 'data-testid': 'vault-status' } }, `Connecté en tant que ${options.email} — ${String(options.entryCount)} entrée(s).`),
    el('div', { className: 'actions' },
      el('button', { className: 'btn btn-secondary', attrs: { type: 'button' }, on: { click: options.onLock } }, 'Verrouiller'),
      el('button', { className: 'btn btn-link', attrs: { type: 'button' }, on: { click: () => { void options.onLogout(); } } }, 'Se déconnecter'),
    ),
  );
}
