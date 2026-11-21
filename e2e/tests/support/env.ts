// Costanti dei test e2e: solo valori sintetici. Password e URL arrivano da e2e/run.sh.
export const BACKOFFICE = 'http://backoffice';
export const FRONTOFFICE = 'http://frontoffice';
export const FAKE = 'http://fake-govpay:8080';
export const MAILPIT = 'http://mailpit:8025';
export const OIDC = 'http://oidc:8080';

export const ADMIN_EMAIL = 'operatore@example.test';
export const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD ?? '';
if (ADMIN_PASSWORD.length < 12) {
  throw new Error('E2E_ADMIN_PASSWORD assente o corta: avviare i test con e2e/run.sh');
}

export const ID_DOMINIO = '00000000000';
export const CF_CITTADINO = 'RSSMRA80A01H501U';
export const EMAIL_CITTADINO = 'cittadino@example.test';
