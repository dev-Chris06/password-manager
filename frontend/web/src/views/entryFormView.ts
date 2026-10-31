import { ENTRY_CATEGORIES, type EntryCategory, type EntryData } from '../../../packages/crypto/src';
import { CATEGORY_LABELS } from '../categories';
import { alertBox, el, field } from '../dom';
import { DEFAULT_GENERATOR_OPTIONS, generatePassword } from '../generator';
import { estimateStrength } from '../passwordPolicy';
import { bindForm, button, input } from './common';

export interface EntryFormOptions {
  title: string;
  initial?: EntryData | undefined;
  /** Sites where a password is already used, to warn about reuse. */
  reusedOn: (password: string) => string[];
  onSave: (data: EntryData) => Promise<void>;
  onCancel: () => void;
  describe: (error: unknown) => string;
}

export function entryFormView(options: EntryFormOptions): HTMLElement {
  const initial = options.initial;
  const site = field('Site', input('text', 'entry-site', { required: true, maxlength: '255', value: initial?.site ?? '', autocomplete: 'off' }), 'Domaine du site, par exemple exemple.fr.');
  const username = field('Identifiant', input('text', 'entry-username', { maxlength: '255', value: initial?.username ?? '', autocomplete: 'off' }));
  const password = field('Mot de passe', input('password', 'entry-password', { required: true, value: initial?.password ?? '', autocomplete: 'new-password' }));

  const toggle = el('button', { className: 'btn btn-secondary btn-small', attrs: { type: 'button' } }, 'Afficher');
  toggle.addEventListener('click', () => {
    const hidden = password.input.type === 'password';
    password.input.type = hidden ? 'text' : 'password';
    toggle.textContent = hidden ? 'Masquer' : 'Afficher';
  });
  const generate = el('button', { className: 'btn btn-secondary btn-small', attrs: { type: 'button' } }, 'Générer');
  generate.addEventListener('click', () => {
    password.input.value = generatePassword(DEFAULT_GENERATOR_OPTIONS);
    password.input.type = 'text';
    toggle.textContent = 'Masquer';
    refresh();
  });

  const meter = el('meter', { attrs: { min: '0', max: '4', low: '2', high: '3', optimum: '4', value: '0', 'aria-label': 'Robustesse' } });
  const meterLabel = el('span', { className: 'strength-label' });
  const reuse = el('div', { className: 'messages', attrs: { 'aria-live': 'polite' } });
  const reuseConfirm = el('input', { attrs: { type: 'checkbox', id: 'entry-reuse-confirm' } });
  const reuseLabel = el('label', { className: 'checkbox', attrs: { for: 'entry-reuse-confirm' } }, reuseConfirm, ' Je garde ce mot de passe malgré la réutilisation.');
  reuseLabel.hidden = true;

  const refresh = (): void => {
    const strength = estimateStrength(password.input.value);
    meter.value = strength.score;
    meterLabel.textContent = password.input.value === '' ? '' : `Robustesse : ${strength.label}`;
    const sites = options.reusedOn(password.input.value);
    reuse.replaceChildren(...(sites.length === 0 ? [] : [alertBox('warning', `Mot de passe déjà utilisé pour : ${sites.join(', ')}.`)]));
    reuseLabel.hidden = sites.length === 0;
    if (sites.length === 0) {
      reuseConfirm.checked = false;
    }
  };
  password.input.addEventListener('input', refresh);

  const category = el(
    'select',
    { attrs: { id: 'entry-category', name: 'entry-category' } },
    ...ENTRY_CATEGORIES.map((value) => el('option', { attrs: { value, selected: value === (initial?.category ?? 'other') } }, CATEGORY_LABELS[value])),
  );
  const notes = el('textarea', { attrs: { id: 'entry-notes', name: 'entry-notes', rows: '3', maxlength: '10000' } });
  notes.value = initial?.notes ?? '';
  const subdomains = el('input', { attrs: { type: 'checkbox', id: 'entry-subdomains', checked: initial?.includeSubdomains === true } });
  const messages = el('div', { className: 'messages' });

  const form = el(
    'form',
    { attrs: { novalidate: true } },
    site.wrapper,
    username.wrapper,
    password.wrapper,
    el('div', { className: 'actions compact' }, toggle, generate),
    el('div', { className: 'strength' }, meter, meterLabel),
    reuse,
    reuseLabel,
    el('div', { className: 'field' }, el('label', { attrs: { for: 'entry-category' } }, 'Catégorie'), category),
    el('div', { className: 'field' }, el('label', { attrs: { for: 'entry-notes' } }, 'Notes'), notes),
    el('label', { className: 'checkbox', attrs: { for: 'entry-subdomains' } }, subdomains, ' Remplir aussi sur les sous-domaines du site (extension)'),
    messages,
    el('div', { className: 'actions' },
      button('Enregistrer'),
      el('button', { className: 'btn btn-link', attrs: { type: 'button' }, on: { click: options.onCancel } }, 'Annuler'),
    ),
  );
  refresh();

  bindForm(
    form,
    messages,
    'Chiffrement et enregistrement…',
    async () => {
      if (site.input.value.trim() === '' || password.input.value === '') {
        throw new EntryFormError('Le site et le mot de passe sont obligatoires.');
      }
      if (options.reusedOn(password.input.value).length > 0 && !reuseConfirm.checked) {
        throw new EntryFormError('Ce mot de passe est déjà utilisé ailleurs : confirmez ou générez-en un nouveau.');
      }
      await options.onSave({
        site: site.input.value,
        username: username.input.value,
        password: password.input.value,
        category: category.value as EntryCategory,
        notes: notes.value,
        includeSubdomains: subdomains.checked,
      });
    },
    (error) => (error instanceof EntryFormError ? error.message : options.describe(error)),
  );

  return el('section', { className: 'card' }, el('h1', {}, options.title), form);
}

class EntryFormError extends Error {
  override name = 'EntryFormError';
}
