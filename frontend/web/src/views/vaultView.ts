import { ENTRY_CATEGORIES, type EntryCategory } from '../../../packages/crypto/src';
import { CATEGORY_LABELS } from '../categories';
import { copyWithClear } from '../clipboard';
import { alertBox, el } from '../dom';
import type { VaultItem, VaultStore } from '../vaultStore';

export interface VaultViewOptions {
  email: string;
  store: VaultStore;
  backupCodesRemaining: number;
  notice?: string | undefined;
  onAdd: () => void;
  onEdit: (uuid: string) => void;
  onDelete: (uuid: string) => Promise<void>;
  onJournal: () => void;
  onSettings: () => void;
  onLock: () => void;
  onLogout: () => Promise<void>;
  describe: (error: unknown) => string;
}

export function vaultView(options: VaultViewOptions): HTMLElement {
  const search = el('input', { attrs: { type: 'search', id: 'vault-search', placeholder: 'Rechercher un site ou un identifiant', 'aria-label': 'Rechercher' } });
  const filter = el(
    'select',
    { attrs: { id: 'vault-category', 'aria-label': 'Catégorie' } },
    el('option', { attrs: { value: '' } }, 'Toutes les catégories'),
    ...ENTRY_CATEGORIES.map((value) => el('option', { attrs: { value } }, CATEGORY_LABELS[value])),
  );
  const list = el('ul', { className: 'entries', attrs: { 'data-testid': 'entries' } });
  const status = el('div', { className: 'messages', attrs: { 'aria-live': 'polite' } });
  const summary = el('p', { className: 'muted', attrs: { 'data-testid': 'vault-status' } });

  const render = (): void => {
    const query = search.value.trim().toLowerCase();
    const category = filter.value as EntryCategory | '';
    const all = options.store.list();
    const items = all.filter(
      (item) =>
        (category === '' || item.data.category === category) &&
        (query === '' || item.data.site.toLowerCase().includes(query) || item.data.username.toLowerCase().includes(query)),
    );
    summary.textContent = `Connecté en tant que ${options.email} — ${String(all.length)} entrée(s).`;
    list.replaceChildren(
      ...(items.length === 0
        ? [el('li', { className: 'empty' }, all.length === 0 ? 'Votre coffre est vide. Ajoutez votre première entrée.' : 'Aucune entrée ne correspond.')]
        : items.map((item) => entryRow(item))),
    );
  };

  const flash = (kind: 'success' | 'error', message: string): void => {
    status.replaceChildren(alertBox(kind, message));
  };

  const entryRow = (item: VaultItem): HTMLLIElement => {
    const secret = el('span', { className: 'secret', attrs: { 'aria-label': 'Mot de passe' } }, '••••••••••');
    let shown = false;
    const show = el('button', { className: 'btn btn-secondary btn-small', attrs: { type: 'button' } }, 'Afficher');
    show.addEventListener('click', () => {
      shown = !shown;
      secret.textContent = shown ? item.data.password : '••••••••••';
      show.textContent = shown ? 'Masquer' : 'Afficher';
    });
    const copy = (label: string, value: string) =>
      el('button', {
        className: 'btn btn-secondary btn-small',
        attrs: { type: 'button' },
        on: {
          click: () => {
            copyWithClear(value)
              .then(() => { flash('success', `${label} copié. Effacement du presse-papiers tenté dans 15 secondes.`); })
              .catch(() => { flash('error', 'Copie impossible : autorisez l’accès au presse-papiers.'); });
          },
        },
      }, `Copier ${label.toLowerCase()}`);

    return el(
      'li',
      { className: 'entry', attrs: { 'data-uuid': item.uuid } },
      el('div', { className: 'entry-main' },
        el('strong', { className: 'entry-site' }, item.data.site),
        el('span', { className: 'badge' }, CATEGORY_LABELS[item.data.category]),
        el('div', { className: 'muted' }, item.data.username === '' ? '(sans identifiant)' : item.data.username),
        el('div', {}, secret),
      ),
      el('div', { className: 'entry-actions' },
        item.data.username === '' ? null : copy('Identifiant', item.data.username),
        copy('Mot de passe', item.data.password),
        show,
        el('button', { className: 'btn btn-secondary btn-small', attrs: { type: 'button' }, on: { click: () => { options.onEdit(item.uuid); } } }, 'Modifier'),
        el('button', {
          className: 'btn btn-danger btn-small',
          attrs: { type: 'button' },
          on: {
            click: () => {
              if (window.confirm(`Supprimer l’entrée « ${item.data.site} » ?`)) {
                options.onDelete(item.uuid).then(render).catch((error: unknown) => { flash('error', options.describe(error)); });
              }
            },
          },
        }, 'Supprimer'),
      ),
    );
  };

  search.addEventListener('input', render);
  filter.addEventListener('change', render);
  render();

  const warnings = [
    options.notice === undefined ? null : alertBox('success', options.notice),
    options.backupCodesRemaining <= 3
      ? alertBox('warning', `Il ne vous reste que ${String(options.backupCodesRemaining)} code(s) de secours : régénérez-les dans les paramètres.`)
      : null,
    options.store.unreadableCount() > 0
      ? alertBox('error', `${String(options.store.unreadableCount())} entrée(s) illisible(s) : données altérées ou chiffrées avec une autre clé.`)
      : null,
  ];

  return el(
    'section',
    { className: 'card vault' },
    el('div', { className: 'vault-header' },
      el('h1', {}, 'Mon coffre'),
      el('div', { className: 'actions compact' },
        el('button', { className: 'btn btn-primary', attrs: { type: 'button' }, on: { click: options.onAdd } }, 'Ajouter'),
        el('button', { className: 'btn btn-secondary', attrs: { type: 'button' }, on: { click: options.onSettings } }, 'Paramètres'),
        el('button', { className: 'btn btn-secondary', attrs: { type: 'button' }, on: { click: options.onJournal } }, 'Journal'),
        el('button', { className: 'btn btn-secondary', attrs: { type: 'button' }, on: { click: options.onLock } }, 'Verrouiller'),
        el('button', { className: 'btn btn-link', attrs: { type: 'button' }, on: { click: () => { void options.onLogout(); } } }, 'Se déconnecter'),
      ),
    ),
    summary,
    ...warnings.filter((warning): warning is HTMLDivElement => warning !== null),
    status,
    el('div', { className: 'toolbar' }, search, filter),
    list,
  );
}
