import { el, field } from '../dom';
import { bindForm, button, input } from './common';

export interface SettingsViewOptions {
  onRegenerateBackupCodes: (masterPassword: string, totpCode: string) => Promise<void>;
  onBack: () => void;
  describe: (error: unknown) => string;
  /** Extra sections added by later features (backup, master password…). */
  sections?: HTMLElement[];
}

export function settingsView(options: SettingsViewOptions): HTMLElement {
  const password = field('Mot de passe maître', input('password', 'codes-master-password', { autocomplete: 'current-password', required: true }));
  const code = field('Code de l’application d’authentification', input('text', 'codes-totp', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '7', required: true }));
  const messages = el('div', { className: 'messages' });
  const form = el('form', { attrs: { novalidate: true } }, password.wrapper, code.wrapper, messages, button('Régénérer les codes de secours'));
  bindForm(form, messages, 'Vérification…', () => options.onRegenerateBackupCodes(password.input.value, code.input.value.replace(/\s/g, '')), options.describe);

  return el(
    'section',
    { className: 'card' },
    el('h1', {}, 'Paramètres de sécurité'),
    ...(options.sections ?? []),
    el('section', { className: 'settings-section' },
      el('h2', {}, 'Codes de secours'),
      el('p', { className: 'muted' }, 'Remplace tous vos codes de secours par dix nouveaux. Les anciens cessent immédiatement de fonctionner.'),
      form,
    ),
    el('button', { className: 'btn btn-link', attrs: { type: 'button' }, on: { click: options.onBack } }, 'Retour au coffre'),
  );
}
