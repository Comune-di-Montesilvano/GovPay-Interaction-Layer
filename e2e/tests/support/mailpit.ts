import { request } from '@playwright/test';
import { MAILPIT } from './env';

export interface MailSummary { ID: string; Subject: string; To: Array<{ Address: string }> }

/** Attende una mail per `to` su Mailpit (API REST v1). */
export async function waitForMail(to: string, timeoutMs = 15_000): Promise<MailSummary> {
  const ctx = await request.newContext();
  try {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      const res = await ctx.get(`${MAILPIT}/api/v1/search?query=${encodeURIComponent('to:' + to)}`);
      if (res.ok()) {
        const body = (await res.json()) as { messages?: MailSummary[] };
        if (body.messages && body.messages.length > 0) return body.messages[0];
      }
      await new Promise((r) => setTimeout(r, 500));
    }
    throw new Error(`nessuna mail per ${to} entro ${timeoutMs} ms`);
  } finally {
    await ctx.dispose();
  }
}
