import { el } from '../dom';

export interface BackupCodesViewOptions {
  email: string;
  codes: readonly string[];
  onContinue: () => Promise<void>;
  describe: (error: unknown) => string;
}

/** Backup codes are shown only once: they replace the phone, not the master password. */
export function backupCodesView(options: BackupCodesViewOptions): HTMLElement {
  const confirm = el('input', { attrs: { type: 'checkbox', id: 'codes-saved' } });
  const continueButton = el('button', { className: 'btn btn-primary', attrs: { type: 'button', disabled: true } }, 'Continuer');
  const messages = el('div', { className: 'messages' });

  confirm.addEventListener('change', () => {
    continueButton.disabled = !confirm.checked;
  });
  continueButton.addEventListener('click', () => {
    continueButton.disabled = true;
    options.onContinue().catch((error: unknown) => {
      messages.replaceChildren(el('div', { className: 'alert alert-error', attrs: { role: 'alert' } }, options.describe(error)));
      continueButton.disabled = false;
    });
  });

  const download = (): void => {
    const text = [
      `Codes de secours — ${options.email} — ${window.location.origin}`,
      'Chaque code ne fonctionne qu’une seule fois. Ils remplacent votre téléphone, pas votre mot de passe maître.',
      '',
      ...options.codes,
      '',
    ].join('\n');
    const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
    const link = el('a', { attrs: { href: url, download: 'codes-de-secours.txt' } });
    link.click();
    URL.revokeObjectURL(url);
  };

  return el(
    'section',
    { className: 'card' },
    el('h1', {}, 'Vos codes de secours'),
    el('p', { className: 'lead' }, 'Si vous perdez votre téléphone, chacun de ces codes permet une connexion. Ils ne seront plus jamais affichés.'),
    el('div', { className: 'kit' }, el('ul', { className: 'backup-codes', attrs: { 'data-testid': 'backup-codes' } }, ...options.codes.map((code) => el('li', {}, code)))),
    el('div', { className: 'actions' },
      el('button', { className: 'btn btn-secondary', attrs: { type: 'button' }, on: { click: download } }, 'Télécharger (.txt)'),
      el('button', { className: 'btn btn-secondary', attrs: { type: 'button' }, on: { click: () => { window.print(); } } }, 'Imprimer'),
    ),
    el('label', { className: 'checkbox', attrs: { for: 'codes-saved' } }, confirm, ' J’ai enregistré mes codes de secours en lieu sûr.'),
    messages,
    continueButton,
  );
}
