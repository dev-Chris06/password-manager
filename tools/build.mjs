// Bundles the TypeScript sources with esbuild.
// Usage: node tools/build.mjs [--watch]
import * as esbuild from 'esbuild';

const watch = process.argv.includes('--watch');

/** @type {import('esbuild').BuildOptions} */
const webOptions = {
  entryPoints: { app: 'frontend/web/src/main.ts' },
  outdir: 'public/build',
  bundle: true,
  format: 'esm',
  target: ['chrome120', 'edge120', 'firefox121', 'safari17'],
  sourcemap: watch ? 'inline' : false,
  minify: !watch,
  legalComments: 'none',
  logLevel: 'info',
};

if (watch) {
  const context = await esbuild.context(webOptions);
  await context.watch();
} else {
  await esbuild.build(webOptions);
}
