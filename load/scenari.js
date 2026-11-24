// Test di carico GIL (k6) contro lo stack e2e con GovPay/pagoPA finti: misura GIL, non GovPay.
// MAI contro la produzione: gli URL sono solo i nomi dei servizi della rete compose e2e.
// Avvio: E2E_LOAD=1 e2e/run.sh (locale) o workflow load-test.yml (workflow_dispatch).
//
// Scenari:
//   pagine          VU costanti (LOAD_VUS, default 10) per LOAD_DURATION (default 2m): GET delle
//                   pagine pubbliche del frontoffice e del login backoffice.
//   ricerca_avviso  4 ricerche/min (GET del form + POST con _csrf) sulla pendenza creata dal
//                   flusso 02: sotto i rate limit del frontoffice (10/min per IP, 5/min per
//                   coppia CF+avviso), che altrimenti risponderebbero "troppe richieste".
// Soglie iniziali (da tarare): errori < 1%, p95 < 800 ms.
import http from 'k6/http';
import { check, sleep } from 'k6';

const FRONTOFFICE = 'http://frontoffice';
const BACKOFFICE = 'http://backoffice';
const FAKE = 'http://fake-govpay:8080';
const CF = 'RSSMRA80A01H501U';
const VUS = Number(__ENV.LOAD_VUS || 10);
const DURATION = __ENV.LOAD_DURATION || '2m';

export const options = {
  scenarios: {
    pagine: { executor: 'constant-vus', vus: VUS, duration: DURATION, exec: 'pagine' },
    ricerca_avviso: {
      executor: 'constant-arrival-rate', rate: 4, timeUnit: '1m', duration: DURATION,
      preAllocatedVUs: 2, exec: 'ricercaAvviso',
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<800'],
    checks: ['rate>0.99'],
  },
  summaryTrendStats: ['avg', 'med', 'p(90)', 'p(95)', 'max'],
};

export function setup() {
  const res = http.get(`${FAKE}/__fake/pendenze`, { tags: { name: 'setup' } });
  const pendenze = res.json();
  if (!Array.isArray(pendenze) || pendenze.length === 0) {
    throw new Error('nessuna pendenza sul finto: eseguire prima i flussi @pre-upgrade');
  }
  return { numeroAvviso: pendenze[0].numeroAvviso };
}

const PAGINE = [
  ['fo home', `${FRONTOFFICE}/`],
  ['fo pagamento spontaneo', `${FRONTOFFICE}/pagamento-spontaneo`],
  ['fo guida', `${FRONTOFFICE}/guida`],
  ['bo login', `${BACKOFFICE}/login`],
];

export function pagine() {
  for (const [name, url] of PAGINE) {
    const res = http.get(url, { tags: { name } });
    check(res, { [`${name} 200`]: (r) => r.status === 200 });
  }
  sleep(1);
}

export function ricercaAvviso(data) {
  const form = http.get(`${FRONTOFFICE}/pagamento-avviso`, { tags: { name: 'fo form avviso' } });
  const csrf = form.html().find('input[name="_csrf"]').attr('value');
  check(form, { 'form avviso con _csrf': () => Boolean(csrf) });
  const res = http.post(`${FRONTOFFICE}/pagamento-avviso`,
    { _csrf: csrf, codiceFiscale: CF, codiceAvviso: data.numeroAvviso },
    { tags: { name: 'fo ricerca avviso' } });
  check(res, { 'avviso trovato': (r) => r.status === 200 && r.body.includes(`Avviso ${data.numeroAvviso}`) });
}
