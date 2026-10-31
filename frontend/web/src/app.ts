// Single-page vault application: screens and transitions between them.
import { ApiClient } from './api';
import {
  completeSecondFactor,
  confirmTwoFactorEnrollment,
  type DerivedLogin,
  login,
  type LoginResult,
  openVault,
  register,
  startTwoFactorEnrollment,
  unlock,
  type UnlockedVault,
} from './auth';
import { describeError } from './errors';
import { loadSecretKey } from './secretKeyStore';
import { backupCodesView } from './views/backupCodesView';
import { kitView } from './views/kitView';
import { loginView } from './views/loginView';
import { registerView } from './views/registerView';
import { secondFactorView } from './views/secondFactorView';
import { twoFactorSetupView } from './views/twoFactorSetupView';
import { vaultView } from './views/vaultView';

export class App {
  private unlocked: UnlockedVault | null = null;

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
    view.querySelector<HTMLInputElement>('input:not([readonly]):not([type=hidden])')?.focus();
  }

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
          this.unlocked = await unlock(this.api, email, masterPassword, secretKeyText);
          this.showVault();
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
    this.unlocked = await openVault(this.api, derived);
    this.showVault();
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
            backupCodesView({
              email: derived.email,
              codes,
              describe: describeError,
              onContinue: () => this.finishLogin(derived),
            }),
          );
        },
        onCancel: () => this.logout(),
      }),
    );
  }

  private showVault(): void {
    const unlocked = this.unlocked;
    if (unlocked === null) {
      return;
    }
    this.show(
      vaultView({
        email: unlocked.email,
        entryCount: unlocked.vault.entries.length,
        backupCodesRemaining: unlocked.vault.backupCodesRemaining,
        onLock: () => {
          this.lock();
        },
        onLogout: () => this.logout(),
      }),
    );
  }

  private lock(): void {
    const email = this.unlocked?.email;
    this.unlocked = null;
    if (email !== undefined) {
      this.showLocked(email);
    }
  }

  private async logout(): Promise<void> {
    this.unlocked = null;
    await this.api.post('/api/auth/logout');
    await this.start();
  }
}
