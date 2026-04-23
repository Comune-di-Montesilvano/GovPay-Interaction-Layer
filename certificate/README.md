# Certificati GovPay – Directory di integrazione

Questa directory contiene i certificati e le chiavi private necessari per l'autenticazione verso le API GovPay.

## ⚠️ Sicurezza

- I certificati e le chiavi private NON devono essere committati nel repository.
- Assicurati che siano esclusi da Git (es. regole di `.gitignore`). Esempio:
  ```gitignore
  certificate/*
  !certificate/README.md
  ```

## 🔑 Utilizzo con GovPay

Il flusso supportato e consigliato e' **UI first**:

- carica certificato client e chiave privata dal backoffice
- i file vengono salvati nel volume Docker `gil_certs`
- l'applicazione usa i path runtime `/var/www/certificate/govpay-cert.pem` e `/var/www/certificate/govpay-key.pem`

La presenza di questa cartella nel repository serve solo a ospitare questa documentazione. Non e' il percorso operativo da usare in produzione.

### 📋 Provenienza dei certificati

I certificati per l'autenticazione con GovPay vengono tipicamente:

- Forniti dall'amministratore dell'istanza GovPay
- Generati tramite interfaccia GovPay (sezione configurazione/certificati)
- Creati dal gestore dell'infrastruttura PagoPA/GovPay

In produzione, non utilizzare certificati self‑signed: usa solo certificati ufficiali forniti dall'istanza.

## 🔧 Configurazione nel file `.env`

Imposta le seguenti variabili:

```bash
# Autenticazione GovPay
AUTHENTICATION_GOVPAY=sslheader

# Percorsi certificati (nel container Docker)
GOVPAY_TLS_CERT=/var/www/certificate/govpay-cert.pem
GOVPAY_TLS_KEY=/var/www/certificate/govpay-key.pem

# Password chiave privata (se richiesta)
GOVPAY_TLS_KEY_PASSWORD=your_key_password

# URL API GovPay
GOVPAY_PENDENZE_URL=https://your-govpay-instance.example.com
```

`AUTHENTICATION_GOVPAY` puo' essere `ssl` oppure `sslheader`, a seconda della modalita' richiesta dalla tua istanza GovPay. In entrambi i casi i path `GOVPAY_TLS_CERT` e `GOVPAY_TLS_KEY` devono puntare ai file presenti nel container.

## 🛠️ Certificati di test (sviluppo)

Solo per sviluppo locale, se vuoi fare prove manuali fuori dal flusso UI, puoi generare certificati self-signed:

```bash
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout private_key.key \
  -out certificate.cer \
  -subj "/CN=govpay-test/O=Development"
```

## 🔄 Utilizzo nel container Docker

Nel runtime standard `docker-compose.yml`, il path `/var/www/certificate/` e' coperto dal volume Docker `gil_certs`:

- `backoffice` monta `gil_certs` in lettura/scrittura
- `frontoffice` monta `gil_certs` in sola lettura
- la UI salva qui i file caricati

Di conseguenza, l'aggiornamento normale dei certificati GovPay non richiede rebuild immagine: richiede upload via UI nel volume corretto.

Solo in scenari locali non standard, se bypassi la UI e usi file baked nell'immagine o mount manuali, devi riallineare di conseguenza i path e il ciclo di deploy.

## ✅ Checklist

- [ ] Certificato client caricato dal backoffice
- [ ] Chiave privata caricata dal backoffice
- [ ] Variabili `.env` aggiornate ai percorsi corretti
- [ ] `GOVPAY_PENDENZE_URL` impostata
- [ ] Eventuale `GOVPAY_TLS_KEY_PASSWORD` impostata se la chiave e' cifrata
- [ ] File presenti nel volume `gil_certs` e path salvati correttamente nel DB
