// Bundles the TypeScript sources with esbuild: the vault web app and the browser extension.
// Usage: node tools/build.mjs [--watch] [--e2e]
//   --e2e  builds the extension for the end-to-end tests (host permissions granted up front,
//          target tab selectable through the popup URL). Never use it for a release.
import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';

import * as esbuild from 'esbuild';

const watch = process.argv.includes('--watch');
const e2e = process.argv.includes('--e2e');

/** @type {import('esbuild').BuildOptions} */
const common = {
  bundle: true,
  format: 'esm',
  sourcemap: watch ? 'inline' : false,
  minify: !watch,
  legalComments: 'none',
  logLevel: 'info',
  loader: { '.txt': 'text' },
};

/** @type {import('esbuild').BuildOptions} */
const webOptions = {
  ...common,
  entryPoints: { app: 'frontend/web/src/main.ts', style: 'frontend/web/styles/app.css' },
  outdir: 'public/build',
  target: ['chrome120', 'edge120', 'firefox121', 'safari17'],
};

/** @type {import('esbuild').BuildOptions} */
const extensionOptions = {
  ...common,
  entryPoints: { background: 'extension/src/background.ts', popup: 'extension/src/popup.ts' },
  outdir: 'extension/dist',
  target: ['chrome120'],
  define: { __E2E__: JSON.stringify(e2e) },
};

async function copyExtensionStatic() {
  await mkdir('extension/dist', { recursive: true });
  await cp('extension/static/popup.html', 'extension/dist/popup.html');
  await cp('extension/static/popup.css', 'extension/dist/popup.css');
  const manifest = JSON.parse(await readFile('extension/static/manifest.json', 'utf8'));
  if (e2e) {
    manifest.host_permissions = ['<all_urls>'];
  }
  await writeFile('extension/dist/manifest.json', `${JSON.stringify(manifest, null, 2)}\n`);
}

await rm('extension/dist', { recursive: true, force: true });
await copyExtensionStatic();

if (watch) {
  await (await esbuild.context(webOptions)).watch();
  await (await esbuild.context(extensionOptions)).watch();
} else {
  await Promise.all([esbuild.build(webOptions), esbuild.build(extensionOptions)]);
}
