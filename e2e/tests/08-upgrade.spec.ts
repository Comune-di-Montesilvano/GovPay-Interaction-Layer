import { test, expect } from './support/test';
import { CF_CITTADINO } from './support/env';
import { pendenze, unmatched } from './support/fake';
import { loginOperatore } from './support/login';

// Solo nella modalita' upgrade di e2e/run.sh (E2E_UPGRADE=1, testIgnore altrimenti): dopo il
// passaggio dalla release precedente alle immagini nuove sullo stesso DB, l'operatore creato
// dal wizard della release precedente entra ancora e vede la pendenza creata prima.
test('@post-upgrade operatore della release precedente vede la pendenza creata prima', async ({ page }) => {
  const [p] = await pendenze();
  expect(p, 'pendenza della fase pre-upgrade assente').toBeTruthy();

  await loginOperatore(page);
  await page.goto(`/pendenze/dettaglio/${encodeURIComponent(p.idPendenza)}`);
  await expect(page.getByText(p.numeroAvviso).first()).toBeVisible();
  await expect(page.getByText(CF_CITTADINO).first()).toBeVisible();
});

test.afterAll(async () => {
  expect(await unmatched()).toEqual([]);
});
