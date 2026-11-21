import { request } from '@playwright/test';
import { FAKE } from './env';

// Endpoint di controllo del GovPay/pagoPA finto (tests/Fakes/GovPay/router.php).
async function call(method: 'GET' | 'POST', path: string, data?: unknown): Promise<unknown> {
  const ctx = await request.newContext();
  try {
    const res = await ctx.fetch(FAKE + path, { method, data });
    if (!res.ok()) throw new Error(`finto ${method} ${path}: HTTP ${res.status()}`);
    return await res.json();
  } finally {
    await ctx.dispose();
  }
}

export const unmatched = async (): Promise<string[]> => (await call('GET', '/__fake/unmatched')) as string[];
export const pendenze = async (): Promise<Array<Record<string, any>>> => (await call('GET', '/__fake/pendenze')) as Array<Record<string, any>>;
export const setPaid = async (idDominio: string, iuv: string): Promise<void> => {
  await call('POST', '/__fake/paid', { idDominio, iuv });
};
