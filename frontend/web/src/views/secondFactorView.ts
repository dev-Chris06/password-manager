import { el, field } from '../dom';
import { bindForm, button, card, input } from './common';

export interface SecondFactorViewOptions {
  onSubmit: (answer: { code: string } | { backupCode: string }) => Promise<void>;
  onCancel: () => Promise<void>;
  describe: (error: unknown) => string;
}

export function secondFactorView(options: SecondFactorViewOptions): HTMLElement {
  let useBackupCode = false;
  const code = field(
    'Code à 6 chiffres',
    input('text', 'totp-code', { inputmode: 'numeric', autocomplete: 'one-time-code', pattern: '[0-9 ]*', maxlength: '7', required: true }),
    'Ouvrez votre application d’authentification.',
  );
  const backup = field(
    'Code de secours',
    input('text', 'backup-code', { autocomplete: 'off', spellcheck: 'false', placeholder: 'XXXX-XXXX-XXXX' }),
    'Chaque code de secours ne fonctionne qu’une fois.',
  );
  backup.wrapper.hidden = true;
  const messages = el('div', { className: 'messages' });

  const toggle = el('button', { className: 'btn btn-link', attrs: { type: 'button' } }, 'Utiliser un code de secours');
  toggle.addEventListener('click', () => {
    useBackupCode = !useBackupCode;
    code.wrapper.hidden = useBackupCode;
    backup.wrapper.hidden = !useBackupCode;
    toggle.textContent = useBackupCode ? 'Utiliser l’application d’authentification' : 'Utiliser un code de secours';
    (useBackupCode ? backup.input : code.input).focus();
  });

  const form = el('form', { attrs: { novalidate: true } }, code.wrapper, backup.wrapper, messages, button('Valider'));
  bindForm(
    form,
    messages,
    'Vérification…',
    async () => {
      await options.onSubmit(
        useBackupCode ? { backupCode: backup.input.value.trim() } : { code: code.input.value.replace(/\s/g, '') },
      );
    },
    options.describe,
  );

  return card(
    'Double authentification',
    form,
    el('div', { className: 'actions' },
      toggle,
      el('button', { className: 'btn btn-link', attrs: { type: 'button' }, on: { click: () => { void options.onCancel(); } } }, 'Annuler'),
    ),
  );
}
