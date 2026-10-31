import type { ApiClient } from '../api';
import { alertBox, el } from '../dom';

interface JournalPage {
  items: { action: string; detail: string; ip: string; userAgent: string; createdAt: string }[];
  page: number;
  pages: number;
  total: number;
}

const ACTION_LABELS: Record<string, string> = {
  register: 'Création du compte',
  login_first_factor: 'Connexion : mot de passe vérifié',
  login_success: 'Connexion réussie',
  login_failure: 'Échec de connexion',
  login_blocked: 'Connexion bloquée (trop de tentatives)',
  logout: 'Déconnexion',
  '2fa_enabled': 'Double authentification activée',
  '2fa_failure': 'Code de double authentification refusé',
  '2fa_reset_cli': 'Double authentification réinitialisée (serveur)',
  backup_code_used: 'Code de secours utilisé',
  backup_codes_regenerated: 'Codes de secours régénérés',
  entry_created: 'Entrée ajoutée',
  entry_updated: 'Entrée modifiée',
  entry_deleted: 'Entrée supprimée',
  entries_imported: 'Import de sauvegarde',
  vault_rekeyed: 'Clé du coffre renouvelée',
  master_password_changed: 'Mot de passe maître changé',
};

export function journalView(api: ApiClient, onBack: () => void, describe: (error: unknown) => string): HTMLElement {
  const body = el('div', {}, alertBox('info', 'Chargement…'));

  const load = (page: number): void => {
    api
      .get<JournalPage>(`/api/audit-log?page=${String(page)}`)
      .then((journal) => {
        const rows = journal.items.map((item) =>
          el('tr', {},
            el('td', {}, new Date(item.createdAt).toLocaleString('fr-FR')),
            el('td', {}, ACTION_LABELS[item.action] ?? item.action),
            el('td', { className: 'muted' }, item.detail),
            el('td', { className: 'muted' }, item.ip),
          ),
        );
        body.replaceChildren(
          journal.total === 0
            ? el('p', {}, 'Aucune action enregistrée.')
            : el('div', { className: 'table-wrap' },
                el('table', { className: 'journal' },
                  el('thead', {}, el('tr', {}, el('th', {}, 'Date'), el('th', {}, 'Action'), el('th', {}, 'Détail'), el('th', {}, 'Adresse IP'))),
                  el('tbody', {}, ...rows),
                ),
              ),
          el('div', { className: 'actions' },
            journal.page > 1 ? el('button', { className: 'btn btn-secondary', attrs: { type: 'button' }, on: { click: () => { load(journal.page - 1); } } }, 'Plus récent') : null,
            el('span', { className: 'muted' }, `Page ${String(journal.page)} / ${String(journal.pages)}`),
            journal.page < journal.pages ? el('button', { className: 'btn btn-secondary', attrs: { type: 'button' }, on: { click: () => { load(journal.page + 1); } } }, 'Plus ancien') : null,
          ),
        );
      })
      .catch((error: unknown) => {
        body.replaceChildren(alertBox('error', describe(error)));
      });
  };
  load(1);

  return el(
    'section',
    { className: 'card' },
    el('h1', {}, 'Journal de sécurité'),
    el('p', { className: 'lead' }, 'Actions effectuées sur votre compte durant les 90 derniers jours. Le serveur ne connaît pas le contenu de vos entrées.'),
    body,
    el('button', { className: 'btn btn-link', attrs: { type: 'button' }, on: { click: onBack } }, 'Retour au coffre'),
  );
}
