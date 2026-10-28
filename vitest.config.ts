import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['frontend/**/*.test.ts', 'extension/**/*.test.ts'],
    passWithNoTests: true,
  },
});
