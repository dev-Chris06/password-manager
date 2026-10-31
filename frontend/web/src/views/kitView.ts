import { el } from '../dom';

export interface KitViewOptions {
  email: string;
  secretKeyText: string;
  onContinue: () => Promise<void>;
  describe: (error: unknown) => string;
}

/**
 * Emergency kit: the Secret Key is shown once and must be kept offline (ADR-004).
 */
export function kitView(options: KitViewOptions): HTMLElement {
  const confirm = el('input', { attrs: { type: 'checkbox', id: 'kit-saved' } });
  const continueButton = el('button', { className: 'btn btn-primary', attrs: { type: 'button', disabled: true } }, 'Continuer');
  const messages = el('div', { className: 'messages' });

  confirm.addEventListener('change', () => {
    continueButton.disabled = !confirm.checked;
  });
  continueButton.addEventListener('click', () => {
    continueButton.disabled = true;
    messages.replaceChildren(el('div', { className: 'alert alert-info' }, 'Ouverture du coffre…'));
    options.onContinue().catch((error: unknown) => {
      messages.replaceChildren(el('div', { className: 'alert alert-error', attrs: { role: 'alert' } }, options.describe(error)));
      continueButton.disabled = false;
    });
  });

  const kit = el(
    'div',
    { className: 'kit', attrs: { id: 'emergency-kit' } },
    el('h2', {}, 'Kit de secours'),
    el('dl', {},
      el('dt', {}, 'Adresse du coffre'),
      el('dd', {}, window.location.origin),
      el('dt', {}, 'Email'),
      el('dd', {}, options.email),
      el('dt', {}, 'Secret Key'),
      el('dd', { className: 'secret-key', attrs: { 'data-testid': 'secret-key' } }, options.secretKeyText),
      el('dt', {}, 'Mot de passe maître'),
      el('dd', { className: 'blank-line' }, '(à écrire à la main, ou à garder en mémoire)'),
    ),
    el('p', {}, 'Sans le mot de passe maître ET la Secret Key, votre coffre est définitivement irrécupérable. Personne, pas même l’administrateur du serveur, ne peut les retrouver.'),
  );

  return el(
    'section',
    { className: 'card' },
    el('h1', {}, 'Conservez votre kit de secours'),
    el('p', { className: 'lead' }, 'Votre Secret Key protège votre coffre même si la base de données est volée. Elle reste sur cet appareil et sur ce kit : le serveur ne la connaît pas.'),
    kit,
    el('div', { className: 'actions' },
      el('button', { className: 'btn btn-secondary', attrs: { type: 'button' }, on: { click: () => { window.print(); } } }, 'Imprimer / enregistrer en PDF'),
    ),
    el('label', { className: 'checkbox', attrs: { for: 'kit-saved' } }, confirm, ' J’ai imprimé ou enregistré mon kit de secours en lieu sûr.'),
    messages,
    continueButton,
  );
}
