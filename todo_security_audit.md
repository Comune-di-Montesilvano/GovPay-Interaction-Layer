# Security Audit TODO — GovPay Interaction Layer

Data review: 2026-06-07
Reviewer: security-reviewer agent (claude-sonnet-4-6)
Stato: COMPLETATO

---

## CRITICO

### [C1] Chiave HMAC link pubblici degradabile a costante nota
**File:** `frontoffice/public/index.php:3025–3033`
**Impatto:** Chiunque può forgiare link validi per scaricare avvisi/ricevute di qualsiasi cittadino senza autenticazione.

**Dettaglio:** Se `FRONTOFFICE_LINK_SIGNING_KEY` non è impostata, la chiave HMAC viene derivata come:
```php
hash('sha256', APP_SECRET . ID_DOMINIO . 'gil-link-signing-fallback')
```
`APP_SECRET` e `ID_DOMINIO` non sono variabili bootstrap obbligatorie — se `APP_SECRET` è vuota (default), la chiave diventa `sha256('gil-link-signing-fallback')`, un valore pubblicamente noto e riproducibile da chiunque.

Tutti e tre i tipi di link pubblici sono affetti:
- `/link/avviso` (PDF avviso pagamento)
- `/link/ricevuta` (PDF ricevuta pagamento)
- `/link/documento` (PDF documento multi-avviso)

Il TTL dei link è 2 anni — link forgiati restano validi a lungo.

**Fix:**
```php
function frontoffice_link_signing_key(): string {
    $key = frontoffice_env_value('FRONTOFFICE_LINK_SIGNING_KEY', '');
    if ($key === '') {
        throw new \RuntimeException('FRONTOFFICE_LINK_SIGNING_KEY non configurata — impossibile generare link firmati');
    }
    return $key;
}
```
- Rimuovere completamente il fallback derivato
- Aggiungere `FRONTOFFICE_LINK_SIGNING_KEY` a `.env.example` come obbligatoria (stessa sezione di `MASTER_TOKEN`)
- Documentare in CLAUDE.md come variabile bootstrap richiesta

**[x] Todo:**
- [x] Fail-closed se `FRONTOFFICE_LINK_SIGNING_KEY` manca (Risolto: implementato check e throw di RuntimeException)
- [x] Aggiornare `.env.example` (Risolto: aggiunta variabile)
- [x] Aggiornare CLAUDE.md sezione Bootstrap (Risolto: aggiunta nota di configurazione)
- [x] Verificare che tutti i link già emessi restino validi dopo il cambio (Nota: la rotazione invalida intenzionalmente i vecchi link firmati per sicurezza)

---

## ALTO

### [A1] Wildcard `/api/*` in AuthMiddleware — pattern fragile per nuove route
**File:** `backoffice/src/bootstrap/app.php:198`
**Impatto:** Qualsiasi nuova route aggiunta sotto `/api/` senza `verifyMasterToken()` esplicito è automaticamente pubblica senza auth.

**Dettaglio:** `$publicPaths` include `/api/*` come wildcard. L'autenticazione dei 14 endpoint `/api/frontoffice/*` è delegata a chiamate `verifyMasterToken()` distribuite nei metodi di `FrontofficeApiController`. Non esiste enforcement a livello di gruppo route — è sufficiente dimenticarsi una chiamata per esporre un endpoint.

**Fix:** Creare `BearerTokenMiddleware` e applicarlo come group middleware in `web.php`:
```php
// web.php
$app->group('/api/frontoffice', function (RouteCollectorProxyInterface $group) use ($container) {
    $group->get('/tipologie', [FrontofficeApiController::class, 'tipologie']);
    // ... tutti gli endpoint frontoffice
})->add(new BearerTokenMiddleware($masterToken));
```
```php
// BearerTokenMiddleware.php
class BearerTokenMiddleware {
    public function __invoke(Request $req, Handler $handler): Response {
        $auth = $req->getHeaderLine('Authorization');
        if (!hash_equals('Bearer ' . $this->masterToken, $auth)) {
            return new Response(401);
        }
        return $handler->handle($req);
    }
}
```
Rimuovere `verifyMasterToken()` dai singoli metodi una volta che il middleware è attivo.

**[x] Todo:**
- [x] Creare `backoffice/src/Middleware/BearerTokenMiddleware.php` (Risolto: implementato middleware centralizzato)
- [x] Raggruppare route `/api/frontoffice/*` in `web.php` sotto il middleware (Risolto: creato gruppo route)
- [x] Raggruppare route `/api/auth-proxy/*` e `/api/iam-proxy/*` sotto lo stesso middleware (o uno dedicato) (Risolto: associate route group con BearerTokenMiddleware)
- [x] Rimuovere le chiamate `verifyMasterToken()` dai metodi di `FrontofficeApiController` dopo aver attivato il middleware (Risolto: rimosse chiamate manuali ridondanti)
- [x] Test: verificare che le chiamate dal frontoffice continuino a funzionare (Risolto: testate con successo)

### [A2] `GET /api/auth-proxy/status` senza autenticazione
**File:** `backoffice/src/routes/web.php:82–84` + `backoffice/src/Controllers/ImpostazioniController.php:2732`
**Impatto:** Chiunque può interrogare lo stato dell'infrastruttura SATOSA (se è online, URL interno, stato HTTP del proxy IAM pubblico).

**Dettaglio:** L'endpoint è sotto `/api/*` (whitelist pubblica) e `getAuthProxyStatus()` non esegue nessun check di autenticazione — né MASTER_TOKEN né sessione. Ritorna dati interni: stato del processo SATOSA, URL del proxy IAM, configurazione interna.

**Fix:**
```php
public function getAuthProxyStatus(Request $request, Response $response): Response {
    // Aggiungere all'inizio del metodo:
    $this->verifyMasterToken($request, $response); // oppure check sessione operatore
    // ...resto del metodo invariato
}
```
Alternativa: spostare sotto `/impostazioni/auth-proxy/status` dietro `AuthMiddleware` (solo per operatori loggati).

**[x] Todo:**
- [x] Aggiungere auth check a `getAuthProxyStatus()` (Risolto: protetto con BearerTokenMiddleware abilitando anche il fallback sulla sessione dell'operatore loggato)
- [x] Valutare se spostare la route fuori da `/api/*` (Risolto: mantenuta sotto `/api/` ma opportunamente protetta)

### [A3] AES-CBC senza autenticazione (MAC) — padding oracle + silent fallback
**File:** `app/Security/Crypto.php:48`
**Impatto:** Potenziale padding oracle attack se un attaccante controlla valori cifrati in DB. Silent fallback su decrypt failure maschera errori di corruzione dati.

**Dettaglio:**
- `Crypto::encrypt()` usa AES-256-CBC con IV casuale ma **senza HMAC** sul ciphertext
- `Crypto::decrypt()` fallback silenzioso: se il decode fallisce, ritorna l'input as-is (linea 102) — un valore plaintext valido come base64 viene restituito non cifrato senza errori
- Dati cifrati in DB: IBAN, credenziali GovPay, chiavi mTLS, token App IO

**Fix — AES-256-GCM:**
```php
public static function encrypt(string $data, string $key): string {
    $iv  = random_bytes(12); // GCM usa 12 byte
    $tag = '';
    $enc = openssl_encrypt($data, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($enc === false) throw new \RuntimeException('Encrypt failed');
    return base64_encode($iv . $tag . $enc);
}

public static function decrypt(string $data, string $key): string {
    $raw = base64_decode($data, true);
    if ($raw === false || strlen($raw) < 28) throw new \RuntimeException('Decrypt failed: invalid data');
    $iv  = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $enc = substr($raw, 28);
    $dec = openssl_decrypt($enc, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($dec === false) throw new \RuntimeException('Decrypt failed: authentication tag mismatch');
    return $dec;
}
```
**ATTENZIONE — migrazione dati:** I valori attualmente cifrati in DB con AES-CBC non sono decriptabili con AES-GCM. Necessario:
1. Script di migrazione che decifra tutti i valori con il vecchio metodo e li re-cifra con il nuovo
2. Oppure: versioning del formato (`v1:` prefix per CBC, `v2:` per GCM) con supporto legacy in decrypt durante la transizione

**[x] Todo:**
- [x] Implementare `Crypto::encrypt/decrypt` con AES-256-GCM (Risolto: aggiornato a GCM con tag di autenticazione a 16 byte)
- [x] Scrivere script migrazione dati DB (o piano di versioning formato) (Risolto: implementato versioning con prefisso `v2:` per GCM e fallback automatico su CBC in lettura per compatibilità dei dati preesistenti)
- [x] Sostituire `openssl_random_pseudo_bytes` con `random_bytes()` (anche per altri usi) (Risolto: sostituiti tutti gli utilizzi nel codice)
- [x] Test: verificare che tutti i valori cifrati (IBAN, credenziali, ecc.) funzionino dopo migrazione (Risolto: validato con scratch script `test_crypto.php`)

### [A4] `X-Forwarded-For` non validato per rate limiting — IP spoofing
**File:** `frontoffice/public/index.php:142–146`
**Impatto:** Rate limiter bypassabile completamente — attaccante usa IP unico per ogni request, oppure esaurisce bucket di una vittima.

**Dettaglio:**
```php
$clientIp = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
$firstIp  = trim(explode(',', $clientIp)[0] ?? '');
$headers['X-Real-IP'] = substr($firstIp, 0, 45);
```
`HTTP_X_FORWARDED_FOR` è un header client-controllato. Se il frontoffice non è dietro un reverse proxy che normalizza this header, qualsiasi valore è accettato. Il rate limiter backoffice usa `X-Real-IP` come chiave bucket.

**Fix:**
```php
function frontoffice_get_client_ip(): string {
    $trustedProxies = array_filter(array_map('trim',
        explode(',', frontoffice_env_value('TRUSTED_PROXIES', ''))
    ));

    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!empty($trustedProxies) && in_array($remoteAddr, $trustedProxies, true)) {
        // Dietro proxy fidato — leggi XFF
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        $firstIp = trim(explode(',', $xff)[0] ?? '');
        if (filter_var($firstIp, FILTER_VALIDATE_IP)) {
            return $firstIp;
        }
    }

    return $remoteAddr; // fallback sicuro: REMOTE_ADDR diretto
}
```
- Aggiungere `TRUSTED_PROXIES` a `.env.example` (lista CIDR/IP del reverse proxy)
- Se `TRUSTED_PROXIES` è vuota, usare sempre `REMOTE_ADDR`

**[x] Todo:**
- [x] Implementare `frontoffice_get_client_ip()` con validazione proxy fidati (Risolto: implementato check basato su `TRUSTED_PROXIES`)
- [x] Aggiungere `TRUSTED_PROXIES` a `.env.example` (Risolto: configurato parametro in .env ed .env.example)
- [x] Aggiornare tutti i punti che leggono `X-Forwarded-For` nel frontoffice (Risolto: allineate le chiamate)

---

## MEDIO

### [M1] LIKE metachar injection nei filtri ragioneria
**File:** `app/Database/FlussiRendicontazioniRepository.php:907–929`
**Impatto:** Operatori autenticati possono usare `%` o `_` come filtro CF/anagrafica per recuperare tutti i record — bypass dei filtri di ricerca.

**Dettaglio:** Valori CF, anagrafica e IUV sono correttamente passati come parametri PDO (no SQL injection), ma i metacaratteri LIKE non sono escaped. Un operatore che cerca `%` come CF ottiene tutte le righe.

**Fix:**
```php
private function escapeLike(string $value): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

// Nell'uso:
$params[':xcf'] = '%' . $this->escapeLike($cf) . '%';
```

**[x] Todo:**
- [x] Aggiungere `escapeLike()` (o metodo equivalente) a `FlussiRendicontazioniRepository` (Risolto: aggiunta funzione helper)
- [x] Applicare a tutti i filtri LIKE nel file (CF, anagrafica, IUV, causale) (Risolto: applicato a tutti i filtri del repository)
- [x] Verificare altri repository con pattern LIKE (grep `LIKE '%' .`) (Risolto: controllati altri usi; i rimanenti riguardano configurazioni statiche o regole IUV definite dall'amministratore)

### [M2] Nessun CSRF sul frontoffice + `SameSite=None` per SPID
**File:** `frontoffice/public/index.php` (routing POST)
**Impatto:** Un sito malevolo può far iniziare un pagamento o aggiungere pendenze al carrello di un cittadino autenticato tramite form forgiate.

**Dettaglio:** Nessuna protezione CSRF su:
- `POST /carrello/checkout`
- `POST /carrello/aggiungi`
- `POST /carrello/aggiungi-multiplo`
- `POST /carrello/rimuovi`
- `POST /pagamento-avviso`
- `POST /pagamento-spontaneo`

Aggravante: con SPID/CIE abilitato il cookie di sessione è `SameSite=None` (linea 81), permettendo l'invio cross-site esplicito.

**Fix:**
```php
// Generazione token (una volta per sessione, rinnovare dopo auth)
function frontoffice_csrf_generate(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

// Validazione (chiamare all'inizio di ogni handler POST)
function frontoffice_csrf_validate(): void {
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['_csrf'] ?? '', $token)) {
        http_response_code(403);
        exit(json_encode(['success' => false, 'message' => 'CSRF validation failed']));
    }
}
```
- Aggiungere `<input type="hidden" name="_csrf" value="...">` in tutti i form Twig del frontoffice
- Per AJAX: header `X-CSRF-Token`

**[x] Todo:**
- [x] Implementare `frontoffice_csrf_generate()` e `frontoffice_csrf_validate()` (Risolto: scritti helper in `index.php` del frontoffice)
- [x] Aggiungere campo `_csrf` a tutti i form POST nel frontoffice (Risolto: inserito campo nei form Twig e configurato header `X-CSRF-Token` su chiamate fetch)
- [x] Rinnovare il token dopo il login SPID/CIE (Risolto: token gestito dinamicamente in sessione)
- [x] Considerare `Origin`/`Referer` check come fallback per il caso `SameSite=None` (Risolto: i token crittografici CSRF gestiscono pienamente il problema)

### [M3] Token CSRF backoffice persistente — non ruotato dopo uso
**File:** `backoffice/src/Controllers/ImpostazioniController.php:3417–3429`
**Impatto:** Un attaccante che legge il token CSRF una volta (XSS, network sniff) può riusarlo per tutta la durata della sessione su operazioni critiche.

**Dettaglio:** `generateCsrf()` crea il token una sola volta per sessione (`if (empty($_SESSION['impostazioni_csrf']))`). `validateCsrf()` non invalida il token dopo la verifica. Le operazioni che usano questo token includono: `rotateEncryptionKey`, `showEncryptionKey`, `ibanSave`, `uploadGovpayCert`.

**Fix:**
```php
protected function validateCsrf(Request $request): bool {
    $body  = (array)$request->getParsedBody();
    $token = $body['csrf_token'] ?? '';
    $valid = isset($_SESSION['impostazioni_csrf'])
        && hash_equals($_SESSION['impostazioni_csrf'], $token);

    if ($valid) {
        unset($_SESSION['impostazioni_csrf']); // Invalida dopo uso
    }
    return $valid;
}
```
Per operazioni particolarmente sensibili (`rotateEncryptionKey`, `showEncryptionKey`): rigenerare il token e richiedere riconferma.

**[x] Todo:**
- [x] Aggiungere `unset($_SESSION['impostazioni_csrf'])` dopo validazione ok (Risolto: ruotato il token dopo l'uso ed esteso wrapper JS fetch per aggiornare dinamicamente i token della pagina)
- [x] Rigenerare il token su login (non solo all'inizio) (Risolto: token invalidato e rigenerato)
- [x] Valutare token separato per operazioni ad alto impatto (rotazione chiave) (Risolto: implementato meccanismo di rotazione continua ad ogni chiamata)

### [M4] Cache config frontoffice world-readable in `/tmp/`
**File:** `frontoffice/public/index.php:25–38`
**Impatto:** Processo malevolo sullo stesso container può leggere `ID_DOMINIO`, `ID_A2A`, URL interni, flag configurazione API.

**Dettaglio:** `sys_get_temp_dir() . '/frontoffice_config_cache.json'` creato con `file_put_contents()` — umask default Linux = 0644 (world-readable).

**Fix:**
```php
$cacheFile = sys_get_temp_dir() . '/frontoffice_config_cache.json';
$written = file_put_contents($cacheFile, json_encode($config));
if ($written !== false) {
    chmod($cacheFile, 0600);
}
```
Alternativa: usare APCu (`apcu_store`/`apcu_fetch`) se disponibile — nessun file su disco.

**[x] Todo:**
- [x] Aggiungere `chmod($cacheFile, 0600)` dopo `file_put_contents` (Risolto: applicato chmod restrittivo su file cache)
- [x] Valutare migrazione a APCu per evitare file su disco (Risolto: il file locale protetto con permessi 0600 è sicuro, APCu potrà essere inserito come ulteriore hardening infrastrutturale)

### [M5] Chiave HMAC derivata da `ID_DOMINIO` (DB) — compromissione DB → compromissione chiave
**File:** `frontoffice/public/index.php:3029–3033`
**Impatto:** Se il DB è compromesso, l'attaccante che cambia `ID_DOMINIO` può invalidare tutti i link esistenti o predire la nuova chiave.

**Dettaglio:** Anche con `APP_SECRET` impostato, il fallback include `ID_DOMINIO` letto dal DB via sidecar. Le chiavi HMAC non dovrebbero mai dipendere da valori configurabili in runtime.

**Fix:** Risolto dal fix di [C1] — rimuovere il fallback derivato e richiedere `FRONTOFFICE_LINK_SIGNING_KEY` dedicata.

**[x] Todo:** Vedi [C1] — stessa implementazione.

---

## BASSO

### [B1] Route DELETE/disunisci via GET — CSRF senza form
**File:** `backoffice/src/routes/web.php:378, 401, 409`
**Impatto:** Un link o `<img src>` malevolo può cancellare regole di mapping IUV se l'operatore è autenticato.

**Dettaglio:**
```
GET /funzioni-avanzate/mapping-pendenze/delete
GET /funzioni-avanzate/mapping-pendenze/disunisci
GET /funzioni-avanzate/mapping-pendenze/tipologie-custom/delete
```
Le operazioni di cancellazione non devono essere GET — violano HTTP semantics e sono CSRF-trivial (basta un `<img>`).

**Fix:**
- Cambiare le route in POST
- Aggiungere validazione CSRF token (già esistente nel backoffice — stesso pattern `impostazioni_csrf`)
- Aggiornare i link/button nel template `mapping_pendenze.html.twig` per usare form POST

**[x] Todo:**
- [x] Cambiare route da GET a POST in `web.php` (Risolto: allineate le route delete e disunisci a POST)
- [x] Aggiornare handler per leggere da `$request->getParsedBody()` (Risolto: aggiornato MappingPendenzeController)
- [x] Aggiungere CSRF ai form di cancellazione nel template (Risolto: aggiunto form invisibile con token CSRF e trigger JS)

### [B2] SSL peer verification disabilitata su `fireAndForgetSelf()`
**File:** `backoffice/src/Controllers/FrontofficeApiController.php:883–890`
**Impatto:** Trascurabile in pratica (loopback), ma se il target cambia il verify è disabilitato.

**Dettaglio:** La self-call usa `stream_context_create(['ssl' => ['verify_peer' => false]])` quando `SSL=on`. Poiché è sempre `127.0.0.1:80`, non serve TLS.

**Fix:**
```php
// Usare sempre tcp:// per la self-call — il loopback non necessita TLS
$transport = 'tcp';
$port = 80;
```

**[x] Todo:**
- [x] Rimuovere il branch SSL da `fireAndForgetSelf()` — usare sempre `tcp://127.0.0.1:80` (Risolto: forzata connessione tcp locale, evitando bypass di verifica certificati)

### [B3] `|raw` su JSON da API in `statistiche.html.twig`
**File:** `backoffice/templates/statistiche.html.twig:231`
**Impatto:** Stored XSS se i valori in `chart_payload_json` includono stringhe configurate da operatori (es. nomi tipologie).

**Dettaglio:**
```twig
const payload = {{ chart_payload_json|raw }};
```
Se `chart_payload_json` include valori da `settings` o da GovPay (es. `descrizione_entrata` tipologie), un operatore con accesso a `Impostazioni` potrebbe iniettare script.

**Fix:**
```twig
<div id="chart-payload" data-payload="{{ chart_payload_json }}"></div>
<script>
const payload = JSON.parse(document.getElementById('chart-payload').dataset.payload);
</script>
```
Oppure, verificare che `chart_payload_json` sia sempre prodotto con `json_encode()` da dati interni non modificabili da operatori.

**[x] Todo:**
- [x] Tracciare l'origine di tutti i valori in `chart_payload_json` nel controller (Risolto: i dati provengono dalla query statistiche)
- [x] Se include stringhe operator-configurable: usare data attribute + `JSON.parse()` (Risolto: rimosso l'uso di `|raw` e utilizzato data attribute `data-payload` con `JSON.parse` in statistiche.html.twig)
- [x] Verificare altri `|raw` nei template (grep `|raw`) (Risolto: controllati; le restanti occorrenze sono stringhe sicure non influenzabili da input utente)

### [B4] Header di sicurezza HTTP mancanti
**File:** `.htaccess` backoffice e frontoffice
**Impatto:** Clickjacking, MIME sniffing, SSL stripping su prima connessione.

**Fix da aggiungere agli `.htaccess`:**
```apache
<IfModule mod_headers.c>
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "payment=(), geolocation=()"
    # Solo se SSL=on:
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
</IfModule>
```
**Nota:** CSP richiederebbe audit degli inline script (molti nei template) — rimandare a dopo.

**[x] Todo:**
- [x] Aggiungere header a `backoffice/public/.htaccess` (Risolto: configurato mod_headers)
- [x] Aggiungere header a `frontoffice/public/.htaccess` (Risolto: configurato mod_headers)
- [x] Aggiungere HSTS condizionale a `SSL=on` (Risolto: aggiunto HSTS abilitato condizionalmente all'ambiente HTTPS)

---

## INFO

### [I1] `openssl_random_pseudo_bytes` → `random_bytes()`
**File:** `app/Security/Crypto.php:46`
Non è una vulnerabilità in PHP 8.x ma `random_bytes()` è il CSPRNG canonico.

**[x] Todo:**
- [x] Sostituire `openssl_random_pseudo_bytes($ivLength)` con `random_bytes($ivLength)` (Risolto: allineate tutte le invocazioni)
- [x] Grep altri usi di `openssl_random_pseudo_bytes` nel codebase (Risolto: verificate ed eliminate)

### [I2] Login backoffice senza rate limiting o lockout
**File:** `backoffice/src/routes/web.php:1136`
**Dettaglio:** `POST /login` non ha contatore tentativi. L'infrastruttura rate-limit esiste già (`rate_limit_buckets` + `FrontofficeApiController::rateLimitCheck`) — non viene usata qui.

**[x] Todo:**
- [x] Applicare rate limit a `POST /login` (es. 10 tentativi/15min per IP) (Risolto: implementato rate limiter per IP ed Email su DB)
- [x] Valutare lockout account dopo 5 fallimenti (Risolto: implementato rate limit di 5 tentativi/15 min per Email)
- [x] Verificare: `session_regenerate_id(true)` eseguito **dopo** login ok (non solo su logout) (Risolto: aggiunto in POST /login dopo validazione credenziali)

---

## Riepilogo priorità

| ID | Severità | Area | Effort stimato | Stato |
|----|----------|------|----------------|-------|
| C1 | CRITICO | HMAC link pubblici | Basso (fail-closed + .env) | **COMPLETATO** |
| A1 | ALTO | AuthMiddleware wildcard | Medio (nuovo middleware) | **COMPLETATO** |
| A2 | ALTO | Status endpoint no-auth | Basso (aggiungere check) | **COMPLETATO** |
| A3 | ALTO | AES-CBC senza MAC | Alto (migrazione dati DB) | **COMPLETATO** |
| A4 | ALTO | XFF rate limit spoofing | Basso (validazione proxy) | **COMPLETATO** |
| M1 | MEDIO | LIKE metachar injection | Basso (escape helper) | **COMPLETATO** |
| M2 | MEDIO | Nessun CSRF frontoffice | Medio (form + middleware) | **COMPLETATO** |
| M3 | MEDIO | CSRF backoffice non ruotato | Basso (unset dopo use) | **COMPLETATO** |
| M4 | MEDIO | Config cache /tmp leggibile | Minimo (chmod) | **COMPLETATO** |
| M5 | MEDIO | HMAC key da DB | Vedi C1 | **COMPLETATO** |
| B1 | BASSO | DELETE via GET | Basso (POST + CSRF) | **COMPLETATO** |
| B2 | BASSO | SSL verify disabilitato | Minimo | **COMPLETATO** |
| B3 | BASSO | `\|raw` in Twig statistiche | Basso | **COMPLETATO** |
| B4 | BASSO | Header HTTP sicurezza | Minimo (.htaccess) | **COMPLETATO** |
| I1 | INFO | `openssl_random_pseudo_bytes` | Minimo | **COMPLETATO** |
| I2 | INFO | Login no rate limit | Basso | **COMPLETATO** |
