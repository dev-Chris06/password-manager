import { expect, test } from '@playwright/test';

test('home page loads its bundle under the CSP', async ({ page }) => {
  const cspViolations: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error' && message.text().includes('Content Security Policy')) {
      cspViolations.push(message.text());
    }
  });

  const response = await page.goto('/');

  expect(response?.status()).toBe(200);
  await expect(page.locator('html')).toHaveAttribute('data-app', 'ready');
  await expect(page).toHaveTitle(/Gestionnaire de mots de passe/);
  expect(cspViolations).toEqual([]);
});

test('home page sends the security headers', async ({ request }) => {
  const response = await request.get('/');
  const headers = response.headers();

  expect(headers['content-security-policy']).toContain("default-src 'self'");
  expect(headers['content-security-policy']).toContain("require-trusted-types-for 'script'");
  expect(headers['content-security-policy']).toMatch(/script-src 'self' 'nonce-[A-Za-z0-9+/=]+' 'wasm-unsafe-eval'/);
  expect(headers['content-security-policy']).not.toMatch(/'unsafe-eval'|'unsafe-inline'/);
  expect(headers['x-content-type-options']).toBe('nosniff');
  expect(headers['referrer-policy']).toBe('no-referrer');
  expect(headers['cross-origin-opener-policy']).toBe('same-origin');
});
