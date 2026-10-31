// Popup of the extension: unlock, list the entries of the current site and fill them.
import { el } from '../../frontend/web/src/dom';
import { generatePassword } from '../../frontend/web/src/generator';
import type { PopupRequest, Status, TabEntry, WorkerResponse } from './messages';


const root = document.getElementById('popup') as HTMLElement;

const MESSAGES: Record<string, string> = {
  not_configured: 'Indiquez d’abord l’adresse de votre coffre.',
  invalid_server_url: 'Adresse invalide : HTTPS obligatoire (ou http://localhost).',
  permission_denied: 'Autorisation refusée : l’extension ne peut pas joindre ce serveur.',
  secret_key_required: 'Saisissez votre Secret Key (kit de secours) : cet appareil ne la connaît pas encore.',
  invalid_credentials: 'Identifiants incorrects.',
  invalid_code: 'Code incorrect ou déjà utilisé.',
  too_many_requests: 'Trop de tentatives, réessayez plus tard.',
  two_factor_setup_required: 'Activez d’abord la double authentification depuis l’application web.',
  kdf_downgrade: 'Alerte de sécurité : le serveur propose des paramètres de chiffrement affaiblis. Déverrouillage refusé.',
  WeakKdfParametersError: 'Alerte de sécurité : paramètres de chiffrement refusés.',
  InvalidSecretKeyError: 'Secret Key invalide.',
  DecryptionError: 'Déchiffrement impossible : mot de passe maître ou Secret Key incorrect.',
  session_expired: 'Session expirée : déverrouillez à nouveau.',
  unauthorized: 'Session expirée : déverrouillez à nouveau.',
  locked: 'Coffre verrouillé.',
  domain_mismatch: 'Cette entrée ne correspond pas au site ouvert.',
  'no-password-field': 'Aucun champ de mot de passe visible sur cette page.',
  'hidden-field': 'Le champ de mot de passe est masqué ou recouvert : remplissage refusé.',
  origin: 'La page a changé entre-temps : remplissage annulé.',
};

function describe(response: WorkerResponse): string {
  return response.ok ? '' : (MESSAGES[response.error] ?? 'Une erreur est survenue.');
}

async function send(request: PopupRequest): Promise<WorkerResponse> {
  try {
    return await chrome.runtime.sendMessage<PopupRequest, WorkerResponse>(request);
  } catch {
    return { ok: false, error: 'error' };
  }
}

async function activeTabId(): Promise<number | null> {
  if (__E2E__) {
    // End-to-end tests open the popup as a page and designate the target tab explicitly.
    const forced = new URLSearchParams(window.location.search).get('tab');
    if (forced !== null) {
      return Number.parseInt(forced, 10);
    }
  }
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  return tab?.id ?? null;
}

function show(...children: Node[]): void {
  root.replaceChildren(...children);
  root.querySelector<HTMLInputElement>('input')?.focus();
}

function message(kind: 'error' | 'muted' | 'warning', text: string): HTMLParagraphElement {
  return el('p', { className: kind, attrs: { role: kind === 'error' ? 'alert' : 'status' } }, text);
}

function labelled(label: string, input: HTMLInputElement): HTMLElement[] {
  input.id = input.name;
  return [el('label', { attrs: { for: input.id } }, label), input];
}

function form(submitLabel: string, fields: HTMLElement[], onSubmit: (feedback: HTMLElement) => Promise<void>): HTMLFormElement {
  const feedback = el('div');
  const submit = el('button', { className: 'primary', attrs: { type: 'submit' } }, submitLabel);
  const element = el('form', {}, ...fields, feedback, el('div', { className: 'row' }, submit));
  element.addEventListener('submit', (event) => {
    event.preventDefault();
    submit.disabled = true;
    feedback.replaceChildren(message('muted', 'Patientez…'));
    void onSubmit(feedback).finally(() => {
      submit.disabled = false;
    });
  });
  return element;
}

function showConfigure(current: string | null): void {
  const url = el('input', { attrs: { type: 'url', name: 'server-url', required: true, placeholder: 'https://coffre.exemple.fr', value: current ?? '' } });
  show(
    el('h1', {}, 'Adresse du coffre'),
    el('p', { className: 'muted' }, 'L’extension ne contacte que ce serveur.'),
    form('Enregistrer', labelled('URL du serveur', url), async (feedback) => {
      let origin: string;
      try {
        origin = new URL(url.value.trim()).origin;
      } catch {
        feedback.replaceChildren(message('error', MESSAGES.invalid_server_url ?? ''));
        return;
      }
      const granted = await chrome.permissions.request({ origins: [`${origin}/*`] }).catch(() => false);
      if (!granted) {
        feedback.replaceChildren(message('error', MESSAGES.permission_denied ?? ''));
        return;
      }
      const response = await send({ type: 'configure', serverUrl: origin });
      if (!response.ok) {
        feedback.replaceChildren(message('error', describe(response)));
        return;
      }
      await render();
    }),
  );
}

function showUnlock(status: Status): void {
  const email = el('input', { attrs: { type: 'email', name: 'email', required: true, autocomplete: 'username', value: status.email ?? '' } });
  const password = el('input', { attrs: { type: 'password', name: 'master-password', required: true, autocomplete: 'current-password' } });
  const secretKey = el('input', { attrs: { type: 'text', name: 'secret-key', autocomplete: 'off', spellcheck: 'false', placeholder: 'A1-XXXXXX-XXXXXX-XXXXX-XXXXX-XXXXX' } });
  const secretKeyFields = el('div', {}, ...labelled('Secret Key', secretKey));
  secretKeyFields.hidden = status.knowsSecretKey;

  show(
    el('h1', {}, 'Déverrouiller le coffre'),
    form('Déverrouiller', [...labelled('Email', email), ...labelled('Mot de passe maître', password), secretKeyFields], async (feedback) => {
      feedback.replaceChildren(message('muted', 'Calcul des clés…'));
      const response = await send({
        type: 'unlock',
        email: email.value,
        password: password.value,
        secretKey: secretKey.value.trim() === '' ? undefined : secretKey.value.trim(),
      });
      password.value = '';
      if (!response.ok) {
        if (response.error === 'secret_key_required') {
          secretKeyFields.hidden = false;
        }
        feedback.replaceChildren(message('error', describe(response)));
        return;
      }
      if (response.step === '2fa') {
        showSecondFactor();
        return;
      }
      await render();
    }),
    el('div', { className: 'row' }, el('button', { className: 'link', attrs: { type: 'button' }, on: { click: () => { showConfigure(status.serverUrl); } } }, 'Changer de serveur')),
  );
}

function showSecondFactor(): void {
  let backup = false;
  const code = el('input', { attrs: { type: 'text', name: 'totp-code', inputmode: 'numeric', autocomplete: 'one-time-code', maxlength: '12', required: true } });
  const label = el('label', { attrs: { for: 'totp-code' } }, 'Code à 6 chiffres');
  code.id = 'totp-code';
  const toggle = el('button', { className: 'link', attrs: { type: 'button' } }, 'Utiliser un code de secours');
  toggle.addEventListener('click', () => {
    backup = !backup;
    label.textContent = backup ? 'Code de secours' : 'Code à 6 chiffres';
    toggle.textContent = backup ? 'Utiliser l’application' : 'Utiliser un code de secours';
    code.value = '';
    code.focus();
  });
  show(
    el('h1', {}, 'Double authentification'),
    form('Valider', [label, code], async (feedback) => {
      const value = code.value.trim();
      const response = await send(backup ? { type: 'second-factor', backupCode: value } : { type: 'second-factor', code: value.replace(/\s/g, '') });
      if (!response.ok) {
        feedback.replaceChildren(message('error', describe(response)));
        if (response.error === 'too_many_requests' || response.error === 'session_expired') {
          window.setTimeout(() => { void render(); }, 1500);
        }
        return;
      }
      await render();
    }),
    el('div', { className: 'row' }, toggle),
  );
}

async function showEntries(status: Status): Promise<void> {
  const tabId = await activeTabId();
  const list = el('ul', { attrs: { 'data-testid': 'popup-entries' } });
  const feedback = el('div');
  const header = el('p', { className: 'muted' }, 'Recherche des comptes…');
  const addSection = el('div');

  show(
    el('h1', {}, 'Comptes pour ce site'),
    header,
    list,
    feedback,
    addSection,
    el('div', { className: 'row' },
      el('button', { attrs: { type: 'button' }, on: { click: () => { void send({ type: 'lock' }).then(render); } } }, 'Verrouiller'),
      status.serverUrl === null ? null : el('button', { className: 'link', attrs: { type: 'button' }, on: { click: () => { void chrome.tabs.create({ url: status.serverUrl ?? '' }); } } }, 'Ouvrir le coffre'),
    ),
  );

  if (tabId === null) {
    header.textContent = 'Aucun onglet actif.';
    return;
  }
  const response = await send({ type: 'entries-for-tab', tabId });
  if (!response.ok) {
    header.replaceChildren(message('error', describe(response)));
    if (response.error === 'unauthorized' || response.error === 'locked') {
      window.setTimeout(() => { void render(); }, 1500);
    }
    return;
  }
  if (response.fillable !== true) {
    header.textContent = 'Cette page n’accepte pas le remplissage (HTTPS obligatoire).';
    return;
  }
  const entries: TabEntry[] = response.entries ?? [];
  header.textContent = entries.length === 0 ? `Aucun compte enregistré pour ${response.host ?? ''}.` : `Site : ${response.host ?? ''}`;
  list.replaceChildren(
    ...entries.map((entry) =>
      el('li', {},
        el('div', {}, el('strong', {}, entry.username === '' ? '(sans identifiant)' : entry.username), el('span', { className: 'muted' }, entry.site)),
        el('button', {
          className: 'primary',
          attrs: { type: 'button' },
          on: {
            click: () => {
              void send({ type: 'fill', tabId, uuid: entry.uuid }).then((result) => {
                if (result.ok) {
                  window.close();
                } else {
                  feedback.replaceChildren(message('error', describe(result)));
                }
              });
            },
          },
        }, 'Remplir'),
      ),
    ),
  );

  const username = el('input', { attrs: { type: 'text', name: 'new-username', autocomplete: 'off' } });
  const password = el('input', { attrs: { type: 'text', name: 'new-password', autocomplete: 'off', value: generatePassword() } });
  const subdomains = el('input', { attrs: { type: 'checkbox', id: 'new-subdomains' } });
  addSection.replaceChildren(
    el('details', {},
      el('summary', {}, 'Ajouter un compte pour ce site'),
      form('Enregistrer', [
        ...labelled('Identifiant', username),
        ...labelled('Mot de passe (généré)', password),
        el('label', { attrs: { for: 'new-subdomains' } }, subdomains, ' Aussi sur les sous-domaines'),
      ], async (addFeedback) => {
        const result = await send({ type: 'add', tabId, username: username.value, password: password.value, includeSubdomains: subdomains.checked });
        addFeedback.replaceChildren(result.ok ? message('muted', 'Compte enregistré.') : message('error', describe(result)));
        if (result.ok) {
          await showEntries(status);
        }
      }),
    ),
  );
}

async function render(): Promise<void> {
  const response = await send({ type: 'status' });
  const status = response.ok ? response.status : undefined;
  if (status === undefined) {
    show(message('error', 'Extension indisponible.'));
    return;
  }
  if (status.serverUrl === null) {
    showConfigure(null);
  } else if (status.pendingSecondFactor) {
    showSecondFactor();
  } else if (!status.unlocked) {
    showUnlock(status);
  } else {
    await showEntries(status);
  }
}

void render();
