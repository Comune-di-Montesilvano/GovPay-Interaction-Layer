import { test, expect, request } from '@playwright/test';
import { EMAIL_CITTADINO, MAILPIT } from './support/env';
import { pendenze, unmatched } from './support/fake';
import { waitForMail } from './support/mailpit';

// Flusso 5: la creazione della pendenza (flusso 2) invia al cittadino la mail con i link
// pubblici firmati (HMAC). Il link avviso scarica il PDF; una firma alterata viene rifiutata.
test('mail di nuova pendenza con link avviso firmato', async () => {
  const [p] = await pendenze();
  const mail = await waitForMail(EMAIL_CITTADINO);
  expect(mail.Subject).toContain('Servizio di test e2e');

  const ctx = await request.newContext();
  try {
    const msg = await (await ctx.get(`${MAILPIT}/api/v1/message/${mail.ID}`)).json();
    const text: string = msg.Text;
    expect(text).toContain(p.numeroAvviso);
    const link = text.match(/https?:\/\/\S+\/link\/avviso\?\S+/)?.[0];
    expect(link, 'link avviso assente nella mail').toBeTruthy();

    const ok = await ctx.get(link!, { maxRedirects: 0 });
    expect(ok.status()).toBe(200);
    expect(ok.headers()['content-type']).toContain('application/pdf');
    expect((await ok.body()).subarray(0, 4).toString()).toBe('%PDF');

    const tampered = link!.replace(/sig=([0-9a-f])/, (_m, c: string) => `sig=${c === '0' ? '1' : '0'}`);
    const ko = await ctx.get(tampered, { maxRedirects: 0 });
    expect(ko.headers()['content-type'] ?? '').not.toContain('application/pdf');
  } finally {
    await ctx.dispose();
  }
});

test.afterAll(async () => {
  expect(await unmatched()).toEqual([]);
});
