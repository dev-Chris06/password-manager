import type { PasswordChange } from '../account';
import { alertBox, el, field } from '../dom';
import type { BreachedEntry } from '../hibp';
import { estimateStrength, masterPasswordProblems } from '../passwordPolicy';
import { FormError } from './registerView';
import { bindForm, button, input } from './common';

type Describe = (error: unknown) => string;

function section(title: string, description: string, ...content: Node[]): HTMLElement {
  return el('section', { className: 'settings-section' }, el('h2', {}, title), el('p', { className: 'muted' }, description), ...content);
}

const describeWithForm = (describe: Describe) => (error: unknown): string => (error instanceof FormError ? error.message : describe(error));

export function masterPasswordSection(email: string, onSubmit: (change: PasswordChange) => Promise<void>, describe: Describe): HTMLElement {
  const current = field('Mot de passe maître actuel', input('password', 'change-current', { autocomplete: 'current-password', required: true }));
  const next = field('Nouveau mot de passe maître', input('password', 'change-new', { autocomplete: 'new-password', required: true }));
  const confirmation = field('Confirmation du nouveau mot de passe', input('password', 'change-confirmation', { autocomplete: 'new-password', required: true }));
  const meter = el('meter', { attrs: { min: '0', max: '4', low: '2', high: '3', optimum: '4', value: '0', 'aria-label': 'Robustesse' } });
  next.input.addEventListener('input', () => {
    meter.value = estimateStrength(next.input.value).score;
  });
  const rotate = el('input', { attrs: { type: 'checkbox', id: 'change-rotate' } });
  const newSecretKey = el('input', { attrs: { type: 'checkbox', id: 'change-secret-key' } });
  const messages = el('div', { className: 'messages' });

  const form = el(
    'form',
    { attrs: { novalidate: true } },
    current.wrapper,
    next.wrapper,
    el('div', { className: 'strength' }, meter),
    confirmation.wrapper,
    el('label', { className: 'checkbox', attrs: { for: 'change-rotate' } }, rotate, ' Renouveler aussi la clé de chiffrement du coffre (toutes les entrées sont rechiffrées)'),
    el('label', { className: 'checkbox', attrs: { for: 'change-secret-key' } }, newSecretKey, ' Générer une nouvelle Secret Key (un nouveau kit de secours sera à conserver)'),
    messages,
    button('Changer le mot de passe maître'),
  );
  bindForm(
    form,
    messages,
    'Calcul des nouvelles clés et rechiffrement…',
    async () => {
      const problems = masterPasswordProblems(next.input.value, email);
      if (problems.length > 0) {
        throw new FormError(problems.join(' '));
      }
      if (next.input.value !== confirmation.input.value) {
        throw new FormError('Les deux nouveaux mots de passe ne correspondent pas.');
      }
      await onSubmit({
        currentPassword: current.input.value,
        newPassword: next.input.value,
        rotateVaultKey: rotate.checked,
        newSecretKey: newSecretKey.checked,
      });
    },
    describeWithForm(describe),
  );

  return section(
    'Mot de passe maître',
    'Seule la clé du coffre est rechiffrée. Les autres sessions sont fermées et les appareils de confiance oubliés. Les sauvegardes déjà exportées restent lisibles avec l’ancien mot de passe et l’ancienne Secret Key.',
    form,
  );
}

export function backupSection(
  onExport: (masterPassword: string) => Promise<void>,
  onImport: (content: string, masterPassword: string, secretKeyText: string | undefined) => Promise<void>,
  describe: Describe,
): HTMLElement {
  const exportPassword = field('Mot de passe maître (pour l’export)', input('password', 'export-password', { autocomplete: 'current-password', required: true }));
  const exportMessages = el('div', { className: 'messages' });
  const exportForm = el('form', { attrs: { novalidate: true } }, exportPassword.wrapper, exportMessages, button('Exporter le coffre chiffré'));
  bindForm(exportForm, exportMessages, 'Préparation de la sauvegarde…', async () => {
    await onExport(exportPassword.input.value);
    exportPassword.input.value = '';
    exportMessages.replaceChildren(alertBox('success', 'Sauvegarde téléchargée.'));
  }, describe);

  const file = field('Fichier de sauvegarde', input('file', 'import-file', { accept: 'application/json,.json', required: true }));
  const importPassword = field('Mot de passe maître de la sauvegarde', input('password', 'import-password', { autocomplete: 'off', required: true }));
  const importSecretKey = field(
    'Secret Key de la sauvegarde',
    input('text', 'import-secret-key', { autocomplete: 'off', spellcheck: 'false', placeholder: 'A1-XXXXXX-XXXXXX-XXXXX-XXXXX-XXXXX' }),
    'Laissez vide si la sauvegarde a été faite avec votre Secret Key actuelle.',
  );
  const importMessages = el('div', { className: 'messages' });
  const importForm = el('form', { attrs: { novalidate: true } }, file.wrapper, importPassword.wrapper, importSecretKey.wrapper, importMessages, button('Importer'));
  bindForm(importForm, importMessages, 'Déchiffrement et import…', async () => {
    const selected = file.input.files?.[0];
    if (selected === undefined) {
      throw new FormError('Choisissez un fichier de sauvegarde.');
    }
    if (selected.size > 50 * 1024 * 1024) {
      throw new FormError('Fichier trop volumineux.');
    }
    const typed = importSecretKey.input.value.trim();
    await onImport(await selected.text(), importPassword.input.value, typed === '' ? undefined : typed);
  }, describeWithForm(describe));

  return section(
    'Sauvegarde',
    'Le fichier exporté reste chiffré : il s’ouvre, même sans ce serveur, avec le mot de passe maître et la Secret Key valables au moment de l’export.',
    el('h3', {}, 'Exporter'),
    exportForm,
    el('h3', {}, 'Importer'),
    importForm,
  );
}

export function breachSection(onCheck: () => Promise<BreachedEntry[]>, describe: Describe): HTMLElement {
  const results = el('div', { className: 'messages', attrs: { 'aria-live': 'polite', 'data-testid': 'breach-results' } });
  const run = el('button', { className: 'btn btn-secondary', attrs: { type: 'button' } }, 'Vérifier mes mots de passe');
  run.addEventListener('click', () => {
    run.disabled = true;
    results.replaceChildren(alertBox('info', 'Vérification en cours…'));
    onCheck()
      .then((breached) => {
        results.replaceChildren(
          breached.length === 0
            ? alertBox('success', 'Aucun de vos mots de passe n’apparaît dans les fuites connues.')
            : el('div', {},
                alertBox('warning', `${String(breached.length)} entrée(s) utilisent un mot de passe déjà divulgué : changez-les.`),
                el('ul', {}, ...breached.map((entry) => el('li', {}, `${entry.site}${entry.username === '' ? '' : ` (${entry.username})`} — vu ${entry.count.toLocaleString('fr-FR')} fois`))),
              ),
        );
      })
      .catch((error: unknown) => {
        results.replaceChildren(alertBox('error', `Vérification impossible : ${describe(error)}`));
      })
      .finally(() => {
        run.disabled = false;
      });
  });

  return section(
    'Mots de passe divulgués',
    'Compare vos mots de passe à la base publique Have I Been Pwned. Seuls les 5 premiers caractères d’une empreinte SHA-1 sont envoyés : le service ne peut pas savoir quel mot de passe est vérifié. Rien n’est envoyé sans clic de votre part.',
    run,
    results,
  );
}
