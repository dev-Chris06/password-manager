import { expect, test } from '@playwright/test';

test('the API hands out a CSRF token and stores the session in the database', async ({ request }) => {
  const response = await request.get('/api/session', { headers: { 'X-PM-Client': '1' } });

  expect(response.status()).toBe(200);
  expect(response.headers()['cache-control']).toBe('no-store, private');
  const body = (await response.json()) as { authenticated: boolean; csrfToken: string };
  expect(body.authenticated).toBe(false);
  expect(body.csrfToken.length).toBeGreaterThan(20);

  const cookie = response.headers()['set-cookie'] ?? '';
  expect(cookie).toContain('pm_session=');
  expect(cookie.toLowerCase()).toContain('httponly');
  expect(cookie.toLowerCase()).toContain('samesite=strict');
});

test('the API refuses calls without the client header', async ({ request }) => {
  const response = await request.get('/api/session');

  expect(response.status()).toBe(403);
  expect(await response.json()).toEqual({ error: 'client_header_missing' });
});
