import { test, expect } from '@playwright/test';
import { CF_CITTADINO, FRONTOFFICE, OIDC } from './support/env';
import { pendenze, unmatched } from './support/fake';
import { loginOperatore } from './support/login';

// Flusso 4: l'operatore attiva il login OIDC esterno (Impostazioni → Frontoffice) verso l'IdP
// finto (tests/Fakes/Oidc); il cittadino entra (Authorization Code + PKCE) e vede in area
// personale la pendenza a suo nome creata nel flusso 2.
test('cittadino entra con OIDC e vede le sue pendenze', async ({ page }) => {
  await loginOperatore(page);
  await page.goto('/impostazioni?tab=frontoffice');
  await page.locator('select[name="auth_proxy_type"]').selectOption('external');
  await page.locator('[name="external_oidc_issuer"]').fill(OIDC);
  await page.locator('[name="external_oidc_client_id"]').fill('gil-e2e');
  await page.locator('[name="external_oidc_client_secret"]').fill('segreto-finto');
  const saved = page.waitForResponse((r) => r.url().endsWith('/impostazioni/frontoffice/save'));
  await page.locator('button[onclick*="/impostazioni/frontoffice/save"]').click();
  expect((await saved).ok()).toBeTruthy();

  const cittadino = await page.context().browser()!.newContext();
  const fo = await cittadino.newPage();
  await fo.goto(`${FRONTOFFICE}/login?return_to=/pendenze`);
  await expect(fo).toHaveURL(new RegExp(`^${OIDC}/OIDC/authorization`));
  await fo.getByRole('link', { name: 'Accedi come Mario Rossi' }).click();
  await expect(fo).toHaveURL(new RegExp(`^${FRONTOFFICE}/pendenze`));

  const [p] = await pendenze();
  expect(p.soggettoPagatore.identificativo).toBe(CF_CITTADINO);
  await expect(fo.getByText(p.numeroAvviso).first()).toBeVisible();
  await cittadino.close();
});

test.afterAll(async () => {
  expect(await unmatched()).toEqual([]);
});
