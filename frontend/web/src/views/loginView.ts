import { el, field } from '../dom';
import { bindForm, button, card, input } from './common';

export interface LoginViewOptions {
  /** Email to prefill (locked session or known device). */
  email?: string | undefined;
  /** Locked session: only the master password is asked, the server session stays open. */
  locked: boolean;
  /** The Secret Key field is shown when this device does not know it yet. */
  askSecretKey: (email: string) => boolean;
  onSubmit: (email: string, masterPassword: string, secretKeyText: string | undefined) => Promise<void>;
  onLogout?: (() => Promise<void>) | undefined;
  describe: (error: unknown) => string;
}

export function loginView(options: LoginViewOptions): HTMLElement {
  const email = field('Adresse email', input('email', 'email', { autocomplete: 'username', required: true, value: options.email ?? '' }));
  if (options.locked) {
    email.input.readOnly = true;
  }
  const password = field('Mot de passe maître', input('password', 'master-password', { autocomplete: 'current-password', required: true }));
  const secretKey = field(
    'Secret Key',
    input('text', 'secret-key', { autocomplete: 'off', spellcheck: 'false', placeholder: 'A1-XXXXXX-XXXXXX-XXXXX-XXXXX-XXXXX' }),
    'Elle figure sur votre kit de secours. Elle sera ensuite mémorisée sur cet appareil.',
  );
  const messages = el('div', { className: 'messages' });

  const toggleSecretKey = (): void => {
    secretKey.wrapper.hidden = !options.askSecretKey(email.input.value.trim().toLowerCase());
  };
  email.input.addEventListener('input', toggleSecretKey);
  toggleSecretKey();

  const form = el(
    'form',
    { attrs: { novalidate: true } },
    email.wrapper,
    password.wrapper,
    secretKey.wrapper,
    messages,
    button(options.locked ? 'Déverrouiller' : 'Se connecter'),
  );

  bindForm(
    form,
    messages,
    'Calcul des clés de chiffrement…',
    async () => {
      const typed = secretKey.wrapper.hidden ? undefined : secretKey.input.value.trim();
      await options.onSubmit(email.input.value.trim().toLowerCase(), password.input.value, typed === '' ? undefined : typed);
      password.input.value = '';
    },
    options.describe,
  );

  const footer = options.onLogout === undefined
    ? null
    : el('button', { className: 'btn btn-link', attrs: { type: 'button' }, on: { click: () => { void options.onLogout?.(); } } }, 'Se déconnecter');

  return card(options.locked ? 'Coffre verrouillé' : 'Connexion', form, ...(footer === null ? [] : [footer]));
}
