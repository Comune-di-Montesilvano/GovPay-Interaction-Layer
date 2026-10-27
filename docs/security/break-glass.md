# Procedura break-glass

Con PR obbligatoria, nessun bypass e Trivy fs bloccante (`ci.yml` → `trivy-fs`), un CVE
HIGH/CRITICAL fixabile pubblicato un giorno qualsiasi blocca anche un hotfix non correlato.
Questa procedura dice come sbloccare un merge urgente senza indebolire le protezioni in modo
permanente.

```sh
R=repos/Comune-di-Montesilvano/GovPay-Interaction-Layer
```

## Opzione 1 (preferita): eccezione motivata in `.trivyignore`

Nella stessa PR dell'hotfix aggiungere il CVE a `.trivyignore` (root del repo) con un commento:
perché è accettabile temporaneamente e una data di rivalutazione (es. `# rivalutare entro 2026-11-15`).
Aprire una issue per la correzione vera e rimuovere la riga quando arriva la patch upstream.

## Opzione 2 (ultima risorsa): ruleset in `evaluate`

Solo se l'opzione 1 non basta (es. CI rotta per cause esterne, GitHub Actions down).

1. Snapshot del ruleset attuale (se non già fatto oggi):
   ```sh
   D=~/gil-settings-snapshot-$(date +%F); mkdir -p $D
   gh api $R/rulesets/8751474 -q '{name,target,enforcement,conditions,bypass_actors,rules}' > $D/ruleset-main.json
   ```
2. Mettere il ruleset "Main Branch" (id `8751474`) in sola valutazione:
   ```sh
   gh api -X PUT $R/rulesets/8751474 -f enforcement=evaluate
   ```
3. Mergiare l'hotfix.
4. Ripristinare **subito** dallo snapshot:
   ```sh
   gh api -X PUT $R/rulesets/8751474 --input $D/ruleset-main.json
   gh api $R/rulesets/8751474 -q .enforcement   # atteso: active
   ```
5. Annotare nell'issue dell'hotfix: chi, quando, perché (l'operazione resta comunque
   nell'audit log dell'organizzazione).

## Snapshot delle impostazioni

Gli snapshot JSON stanno fuori dal repo, in `~/gil-settings-snapshot-YYYY-MM-DD/`. Per
rigenerarli tutti:

```sh
D=~/gil-settings-snapshot-$(date +%F); mkdir -p $D
gh api $R > $D/repo.json
gh api $R/actions/permissions > $D/actions-permissions.json
gh api $R/rulesets/8751474 -q '{name,target,enforcement,conditions,bypass_actors,rules}' > $D/ruleset-main.json
gh api $R/code-scanning/default-setup > $D/codeql.json
gh api $R/private-vulnerability-reporting > $D/pvr.json
```
