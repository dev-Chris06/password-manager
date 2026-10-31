import type { TwoFactorEnrollment } from '../auth';
import { alertBox, el, field } from '../dom';
import { bindForm, button, card, input } from './common';

export interface TwoFactorSetupViewOptions {
  start: () => Promise<TwoFactorEnrollment>;
  onConfirm: (code: string) => Promise<void>;
  onCancel: () => Promise<void>;
  describe: (error: unknown) => string;
}

/** Mandatory enrollment of an authenticator app (ADR-005). */
export function twoFactorSetupView(options: TwoFactorSetupViewOptions): HTMLElement {
  const body = el('div', {}, alertBox('info', 'Préparation…'));

  options
    .start()
    .then((enrollment) => {
      const code = field(
        'Code affiché par l’application',
        input('text', 'totp-code', { inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '7', required: true }),
      );
      const messages = el('div', { className: 'messages' });
      const form = el('form', { attrs: { novalidate: true } }, code.wrapper, messages, button('Activer'));
      bindForm(form, messages, 'Vérification…', () => options.onConfirm(code.input.value.replace(/\s/g, '')), options.describe);

      body.replaceChildren(
        el('ol', { className: 'steps' },
          el('li', {}, 'Installez une application d’authentification (Aegis, 2FAS, Google Authenticator, Microsoft Authenticator…).'),
          el('li', {}, 'Scannez ce QR code, ou saisissez la clé manuellement.'),
          el('li', {}, 'Saisissez le code à 6 chiffres affiché.'),
        ),
        el('img', { className: 'qr-code', attrs: { src: enrollment.qrCode, alt: 'QR code de configuration de la double authentification', width: '240', height: '240' } }),
        el('p', {}, 'Clé manuelle : ', el('code', { className: 'totp-secret', attrs: { 'data-testid': 'totp-secret' } }, enrollment.secret.replace(/(.{4})/g, '$1 ').trim())),
        form,
      );
      code.input.focus();
    })
    .catch((error: unknown) => {
      body.replaceChildren(alertBox('error', options.describe(error)));
    });

  return card(
    'Activez la double authentification',
    el('p', { className: 'lead' }, 'Elle est obligatoire : même avec votre mot de passe maître, personne ne pourra télécharger votre coffre chiffré sans votre téléphone.'),
    body,
    el('button', { className: 'btn btn-link', attrs: { type: 'button' }, on: { click: () => { void options.onCancel(); } } }, 'Annuler'),
  );
}
