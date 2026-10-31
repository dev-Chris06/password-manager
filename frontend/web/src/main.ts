// Entry point of the vault web application.
import { App } from './app';

const root = document.getElementById('app');
if (root !== null) {
  new App(root)
    .start()
    .then(() => {
      document.documentElement.dataset.app = 'ready';
    })
    .catch(() => {
      root.textContent = 'Impossible de joindre le serveur. Rechargez la page.';
      document.documentElement.dataset.app = 'error';
    });
}
