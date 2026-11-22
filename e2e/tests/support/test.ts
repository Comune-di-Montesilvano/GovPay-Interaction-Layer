import { test as base, expect, type BrowserContext } from '@playwright/test';

// Host dello stack e2e (rete compose). Il browser non esce mai da qui: le risorse esterne
// (es. Google Fonts importato da assets/css/tokens.css) vengono interrotte subito. In CI
// harden-runner blocca l'egress e una connessione appesa terrebbe `page.goto` in attesa di
// `load` fino al timeout; e un test non deve mai contattare servizi reali.
export const INTERNAL_HOSTS = new Set(['backoffice', 'frontoffice', 'fake-govpay', 'oidc', 'mailpit']);

export async function isolate(context: BrowserContext): Promise<void> {
  await context.route(
    (url) => (url.protocol === 'http:' || url.protocol === 'https:') && !INTERNAL_HOSTS.has(url.hostname),
    (route) => route.abort('blockedbyclient'),
  );
}

export const test = base.extend<{ hermetic: void }>({
  hermetic: [
    async ({ context }, use) => {
      await isolate(context);
      await use();
    },
    { auto: true },
  ],
});

export { expect };
