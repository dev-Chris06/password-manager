import { readFileSync } from 'node:fs';

import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [
    {
      // Same behaviour as the esbuild "text" loader used for the production bundle.
      name: 'text-loader',
      enforce: 'pre',
      load(id) {
        return id.endsWith('.txt') ? `export default ${JSON.stringify(readFileSync(id, 'utf8'))};` : null;
      },
    },
  ],
  test: {
    include: ['frontend/**/*.test.ts', 'extension/**/*.test.ts'],
    passWithNoTests: true,
  },
});
