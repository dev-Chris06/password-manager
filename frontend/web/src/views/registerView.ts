import { el, field } from '../dom';
import { estimateStrength, masterPasswordProblems } from '../passwordPolicy';
import { bindForm, button, card, input } from './common';

export interface RegisterViewOptions {
  /** The server requires the registration code set by its owner. */
  codeRequired: boolean;
  onSubmit: (email: string, masterPassword: string, registrationCode: string | undefined) => Promise<void>;
  describe: (error: unknown) => string;
}

export function registerView(options: RegisterViewOptions): HTMLElement {
  const code = field(
    'Code d’inscription',
    input('text', 'registration-code', { autocomplete: 'off', spellcheck: 'false', required: true }),
    'Défini par le propriétaire du serveur (REGISTRATION_CODE) : il empêche un inconnu de créer le compte à votre place.',
  );
  code.wrapper.hidden = !options.codeRequired;
  const email = field('Adresse email', input('email', 'email', { autocomplete: 'username', required: true, maxlength: '180' }));
  const password = field(
    'Mot de passe maître',
    input('password', 'master-password', { autocomplete: 'new-password', required: true }),
    'Au moins 12 caractères. Une phrase de plusieurs mots est idéale. Il ne pourra jamais être récupéré.',
  );
  const confirmation = field(
    'Confirmation',
    input('password', 'master-password-confirmation', { autocomplete: 'new-password', required: true }),
  );
  const meter = el('meter', { attrs: { min: '0', max: '4', low: '2', high: '3', optimum: '4', value: '0', 'aria-label': 'Robustesse' } });
  const meterLabel = el('span', { className: 'strength-label' }, '');
  const problems = el('ul', { className: 'problems', attrs: { 'aria-live': 'polite' } });
  const messages = el('div', { className: 'messages' });

  const refresh = (): void => {
    const strength = estimateStrength(password.input.value);
    meter.value = strength.score;
    meterLabel.textContent = password.input.value === '' ? '' : `Robustesse : ${strength.label}`;
    problems.replaceChildren(
      ...masterPasswordProblems(password.input.value, email.input.value).map((problem) => el('li', {}, problem)),
    );
  };
  password.input.addEventListener('input', refresh);
  email.input.addEventListener('input', refresh);

  const form = el(
    'form',
    { attrs: { novalidate: true } },
    code.wrapper,
    email.wrapper,
    password.wrapper,
    el('div', { className: 'strength' }, meter, meterLabel),
    problems,
    confirmation.wrapper,
    messages,
    button('Créer le coffre'),
  );

  bindForm(
    form,
    messages,
    'Création des clés de chiffrement… (quelques secondes)',
    async () => {
      const issues = masterPasswordProblems(password.input.value, email.input.value);
      if (!email.input.checkValidity() || email.input.value === '') {
        throw new FormError('Adresse email invalide.');
      }
      if (issues.length > 0) {
        throw new FormError(issues.join(' '));
      }
      if (password.input.value !== confirmation.input.value) {
        throw new FormError('Les deux mots de passe ne correspondent pas.');
      }
      if (options.codeRequired && code.input.value.trim() === '') {
        throw new FormError('Saisissez le code d’inscription.');
      }
      await options.onSubmit(email.input.value.trim().toLowerCase(), password.input.value, options.codeRequired ? code.input.value.trim() : undefined);
    },
    (error) => (error instanceof FormError ? error.message : options.describe(error)),
  );

  return card(
    'Créer votre coffre',
    el(
      'p',
      { className: 'lead' },
      'Votre coffre est chiffré dans ce navigateur. Le serveur ne voit jamais votre mot de passe maître ni vos données.',
    ),
    form,
  );
}

export class FormError extends Error {
  override name = 'FormError';
}
