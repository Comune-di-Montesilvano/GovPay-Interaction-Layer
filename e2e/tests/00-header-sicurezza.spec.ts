import { test, expect } from './support/test';
import { BACKOFFICE, FRONTOFFICE } from './support/env';

// Header di sicurezza HTTP (apache/security-headers.conf) su backoffice e frontoffice. Lo stack
// e2e e' in HTTP: l'HSTS si verifica simulando il reverse proxy TLS con X-Forwarded-Proto.
for (const [nome, url] of [['backoffice', `${BACKOFFICE}/login`], ['frontoffice', `${FRONTOFFICE}/`]] as const) {
  test(`${nome}: header di sicurezza`, async ({ request }) => {
    const h = (await request.get(url, { maxRedirects: 0 })).headers();
    expect(h['content-security-policy']).toContain("default-src 'self'");
    expect(h['x-content-type-options']).toBe('nosniff');
    expect(h['x-frame-options']).toBe('SAMEORIGIN');
    expect(h['referrer-policy']).toBe('strict-origin-when-cross-origin');
    expect(h['permissions-policy']).toContain('camera=()');
    expect(h['permissions-policy']).toContain('geolocation=()');
    expect(h['cross-origin-opener-policy']).toBe('same-origin');
    // Deprecato: i browser moderni lo ignorano, nei vecchi il filtro XSS creava vulnerabilita'.
    expect(h['x-xss-protection']).toBeUndefined();
    expect(h['x-powered-by']).toBeUndefined();
    expect(h['server']).toBe('Apache');
    // HSTS solo su HTTPS: mai su HTTP in chiaro (inutile e fuorviante).
    expect(h['strict-transport-security']).toBeUndefined();
  });

  test(`${nome}: HSTS dietro reverse proxy HTTPS`, async ({ request }) => {
    const h = (await request.get(url, { maxRedirects: 0, headers: { 'X-Forwarded-Proto': 'https' } })).headers();
    expect(h['strict-transport-security']).toMatch(/^max-age=\d{7,}$/);
  });
}

// Nessun elenco dei file nelle directory (Options senza Indexes nel vhost): trovato da ZAP attivo
// su /assets/, /assets/css/, /assets/js/, /img/ del frontoffice.
test('nessun directory listing', async ({ request }) => {
  for (const url of [`${FRONTOFFICE}/assets/`, `${FRONTOFFICE}/assets/js/`, `${FRONTOFFICE}/img/`, `${BACKOFFICE}/assets/`]) {
    const r = await request.get(url, { maxRedirects: 0 });
    expect(await r.text(), url).not.toContain('Index of /');
  }
});
