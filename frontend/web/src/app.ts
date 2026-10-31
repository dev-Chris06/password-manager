// Single-page vault application: screens and transitions between them.
import { changeMasterPassword, UnreadableEntriesError } from './account';
import { ApiClient, ApiError } from './api';
import {
  completeSecondFactor,
  confirmTwoFactorEnrollment,
  type DerivedLogin,
  login,
  type LoginResult,
  openVault,
  reauthenticate,
  register,
  startTwoFactorEnrollment,
  unlock,
  type UnlockedVault,
} from './auth';
import { startAutoLock } from './autoLock';
import { downloadFile, exportBackup, importBackup } from './backupFile';
import { describeError } from './errors';
import { findBreachedEntries } from './hibp';
import { loadSecretKey } from './secretKeyStore';
import { VaultStore, VersionConflictError } from './vaultStore';
import { backupCodesView } from './views/backupCodesView';
import { entryFormView } from './views/entryFormView';
import { journalView } from './views/journalView';
import { kitView } from './views/kitView';
import { loginView } from './views/loginView';
import { registerView } from './views/registerView';
import { secondFactorView } from './views/secondFactorView';
import { backupSection, breachSection, masterPasswordSection } from './views/settingsSections';
import { settingsView } from './views/settingsView';
import { twoFactorSetupView } from './views/twoFactorSetupView';
import { vaultView } from './views/vaultView';

interface OpenVault {
  readonly unlocked: UnlockedVault;
  readonly store: VaultStore;
  readonly stopAutoLock: () => void;
}

export class App {
  private open: OpenVault | null = null;

  constructor(
    private readonly root: HTMLElement,
    private readonly api: ApiClient = new ApiClient(),
  ) {}

  async start(): Promise<void> {
    const session = await this.api.session();
    if (session.authenticated && session.email !== null) {
      this.showLocked(session.email);
    } else if (session.registrationOpen) {
      this.showRegister();
    } else {
      this.showLogin();
    }
  }

  private show(view: HTMLElement): void {
    this.root.replaceChildren(view);
    view.querySelector<HTMLInputElement>('input:not([readonly]):not([type=hidden]):not([type=search])')?.focus();
  }

  /** Error messages of the unlocked vault; an expired server session sends back to the login. */
  private readonly describe = (error: unknown): string => {
    if (error instanceof ApiError && error.status === 401) {
      this.close();
      void this.start();
    }
    if (error instanceof VersionConflictError) {
      return 'Cette entrée a été modifiée ailleurs entre-temps : la liste a été rechargée, recommencez la modification.';
    }
    if (error instanceof UnreadableEntriesError) {
      return 'Des entrées illisibles empêchent de renouveler la clé du coffre. Supprimez-les d’abord.';
    }
    return describeError(error);
  };

  // --- Account creation and login -------------------------------------------------------------

  private showRegister(): void {
    this.show(
      registerView({
        describe: describeError,
        onSubmit: async (email, masterPassword) => {
          const secretKeyText = await register(this.api, email, masterPassword);
          this.showKit(email, masterPassword, secretKeyText);
        },
      }),
    );
  }

  private showKit(email: string, masterPassword: string, secretKeyText: string): void {
    this.show(
      kitView({
        email,
        secretKeyText,
        describe: describeError,
        onContinue: async () => {
          const { result, derived } = await login(this.api, { email, masterPassword, secretKeyText });
          await this.afterLogin(result, derived);
        },
      }),
    );
  }

  private showLogin(email?: string): void {
    this.show(
      loginView({
        email,
        locked: false,
        askSecretKey: (typed) => typed !== '' && loadSecretKey(typed) === null,
        describe: describeError,
        onSubmit: async (typedEmail, masterPassword, secretKeyText) => {
          const { result, derived } = await login(this.api, { email: typedEmail, masterPassword, secretKeyText });
          await this.afterLogin(result, derived);
        },
      }),
    );
  }

  private showLocked(email: string): void {
    this.show(
      loginView({
        email,
        locked: true,
        askSecretKey: (typed) => typed !== '' && loadSecretKey(typed) === null,
        describe: describeError,
        onSubmit: async (_email, masterPassword, secretKeyText) => {
          await this.enterVault(await unlock(this.api, email, masterPassword, secretKeyText));
        },
        onLogout: () => this.logout(),
      }),
    );
  }

  private async afterLogin(result: LoginResult, derived: DerivedLogin): Promise<void> {
    switch (result.step) {
      case 'done':
        await this.finishLogin(derived);
        return;
      case '2fa':
        this.showSecondFactor(derived);
        return;
      case '2fa-setup':
        this.showTwoFactorSetup(derived);
        return;
    }
  }

  private async finishLogin(derived: DerivedLogin): Promise<void> {
    await this.enterVault(await openVault(this.api, derived));
  }

  private showSecondFactor(derived: DerivedLogin): void {
    this.show(
      secondFactorView({
        describe: describeError,
        onSubmit: async (answer) => {
          await completeSecondFactor(this.api, answer);
          await this.finishLogin(derived);
        },
        onCancel: () => this.logout(),
      }),
    );
  }

  private showTwoFactorSetup(derived: DerivedLogin): void {
    this.show(
      twoFactorSetupView({
        describe: describeError,
        start: () => startTwoFactorEnrollment(this.api, derived),
        onConfirm: async (code) => {
          const codes = await confirmTwoFactorEnrollment(this.api, code);
          this.show(
            backupCodesView({ email: derived.email, codes, describe: describeError, onContinue: () => this.finishLogin(derived) }),
          );
        },
        onCancel: () => this.logout(),
      }),
    );
  }

  // --- Unlocked vault ----------------------------------------------------------------------

  private async enterVault(unlocked: UnlockedVault, notice?: string): Promise<void> {
    this.close();
    const store = new VaultStore(this.api, unlocked.vaultKey);
    await store.load(unlocked.vault);
    this.open = { unlocked, store, stopAutoLock: startAutoLock(() => { this.lock(); }) };
    this.showVault(notice);
  }

  private showVault(notice?: string): void {
    const open = this.open;
    if (open === null) {
      return;
    }
    this.show(
      vaultView({
        email: open.unlocked.email,
        store: open.store,
        backupCodesRemaining: open.unlocked.vault.backupCodesRemaining,
        notice,
        describe: this.describe,
        onAdd: () => { this.showEntryForm(); },
        onEdit: (uuid) => { this.showEntryForm(uuid); },
        onDelete: (uuid) => open.store.remove(uuid),
        onJournal: () => { this.show(journalView(this.api, () => { this.showVault(); }, this.describe)); },
        onSettings: () => { this.showSettings(); },
        onLock: () => { this.lock(); },
        onLogout: () => this.logout(),
      }),
    );
  }

  private showEntryForm(uuid?: string): void {
    const open = this.open;
    if (open === null) {
      return;
    }
    const existing = uuid === undefined ? undefined : open.store.get(uuid);
    this.show(
      entryFormView({
        title: existing === undefined ? 'Nouvelle entrée' : 'Modifier l’entrée',
        initial: existing?.data,
        reusedOn: (password) => open.store.reusedOn(password, uuid),
        describe: this.describe,
        onCancel: () => { this.showVault(); },
        onSave: async (data) => {
          try {
            if (existing === undefined) {
              await open.store.add(data);
            } else {
              await open.store.update(existing.uuid, data);
            }
          } catch (error) {
            if (error instanceof VersionConflictError) {
              this.showVault(this.describe(error));
              return;
            }
            throw error;
          }
          this.showVault(existing === undefined ? 'Entrée ajoutée.' : 'Entrée modifiée.');
        },
      }),
    );
  }

  private showSettings(): void {
    const open = this.open;
    if (open === null) {
      return;
    }
    const email = open.unlocked.email;
    this.show(
      settingsView({
        describe: this.describe,
        onBack: () => { this.showVault(); },
        sections: [
          masterPasswordSection(
            email,
            async (change) => {
              const { unlocked, secretKeyText } = await changeMasterPassword(this.api, open.store, email, change);
              if (change.newSecretKey) {
                this.close();
                this.show(kitView({
                  email,
                  secretKeyText,
                  describe: describeError,
                  onContinue: () => this.enterVault(unlocked, 'Mot de passe maître et Secret Key changés.'),
                }));
              } else {
                await this.enterVault(unlocked, change.rotateVaultKey ? 'Mot de passe maître changé et coffre rechiffré.' : 'Mot de passe maître changé.');
              }
            },
            this.describe,
          ),
          backupSection(
            async (masterPassword) => {
              const { fileName, content } = await exportBackup(this.api, masterPassword);
              downloadFile(fileName, content);
            },
            async (content, masterPassword, secretKeyText) => {
              const { imported, skipped } = await importBackup(open.store, content, masterPassword, secretKeyText, email);
              this.showVault(`${String(imported)} entrée(s) importée(s), ${String(skipped)} déjà présente(s) ignorée(s).`);
            },
            this.describe,
          ),
          breachSection(() => findBreachedEntries(open.store.list()), this.describe),
        ],
        onRegenerateBackupCodes: async (masterPassword, totpCode) => {
          const { authProof } = await reauthenticate(open.unlocked.vault, masterPassword);
          const { backupCodes } = await this.api.post<{ backupCodes: string[] }>('/api/2fa/backup-codes', { authProof, code: totpCode });
          this.open = { ...open, unlocked: { ...open.unlocked, vault: { ...open.unlocked.vault, backupCodesRemaining: backupCodes.length } } };
          this.show(
            backupCodesView({
              email: open.unlocked.email,
              codes: backupCodes,
              describe: this.describe,
              onContinue: () => { this.showVault('Nouveaux codes de secours enregistrés.'); return Promise.resolve(); },
            }),
          );
        },
      }),
    );
  }

  /** Forgets the vault key and every decrypted value. */
  private close(): void {
    if (this.open !== null) {
      this.open.stopAutoLock();
      this.open.store.clear();
      this.open = null;
    }
  }

  private lock(): void {
    const email = this.open?.unlocked.email;
    this.close();
    if (email !== undefined) {
      this.showLocked(email);
    }
  }

  private async logout(): Promise<void> {
    this.close();
    await this.api.post('/api/auth/logout');
    await this.start();
  }
}
