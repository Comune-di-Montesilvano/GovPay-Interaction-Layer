import { test, expect, INTERNAL_HOSTS } from './support/test';
import { FRONTOFFICE } from './support/env';

// Nessuna risorsa da host esterni (CDN, Google Fonts...): il browser di cittadini e operatori
// non deve contattare terzi. I font sono nell'immagine (/assets/fonts, @fontsource).
for (const [nome, url] of [['backoffice login', '/login'], ['frontoffice home', `${FRONTOFFICE}/`]] as const) {
  test(`${nome}: solo risorse locali, font caricati`, async ({ page }) => {
    const esterne: string[] = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if ((u.protocol === 'http:' || u.protocol === 'https:') && !INTERNAL_HOSTS.has(u.hostname)) esterne.push(r.url());
    });
    await page.goto(url);
    await page.evaluate(() => document.fonts.ready);
    expect(esterne).toEqual([]);
    // Il browser scarica un font solo se la pagina lo usa: load() forza il download dal server.
    for (const font of ['400 16px "Titillium Web"', '700 16px "Titillium Web"', '400 16px "JetBrains Mono"']) {
      const facce = await page.evaluate((f) => document.fonts.load(f).then((l) => l.length), font);
      expect(facce, font).toBeGreaterThan(0);
    }
    expect(esterne).toEqual([]);
  });
}
