# Procedura break-glass

Con PR obbligatoria, nessun bypass e due check richiesti bloccanti (`ci.yml` → `build-test`
con `composer audit`, `trivy-fs` con Trivy HIGH/CRITICAL), un advisory pubblicato un giorno
qualsiasi blocca anche un hotfix non correlato. Questa procedura dice come sbloccare un merge
urgente senza indebolire le protezioni in modo permanente.

```sh
R=repos/Comune-di-Montesilvano/GovPay-Interaction-Layer
```

## Opzione 1 (preferita): eccezione motivata, nella PR dell'hotfix

Ogni eccezione ha un commento con il motivo e una data di rivalutazione, e una issue aperta per
la correzione vera. La riga si rimuove quando arriva la patch upstream.

- **Trivy (`trivy-fs`)**: aggiungere il CVE a `.trivyignore` (root del repo), es.
  ```
  # <pacchetto>: <perché è accettabile ora> — rivalutare entro 2026-11-15
  CVE-2026-12345
  ```
- **`composer audit` (`build-test`)**: aggiungere l'advisory a `config.audit.ignore` in
  `composer.json`, con il motivo come valore:
  ```json
  "config": {
    "audit": {
      "ignore": { "GHSA-xxxx-xxxx-xxxx": "<perché> — rivalutare entro 2026-11-15" }
    }
  }
  ```

## Opzione 2 (ultima risorsa): ruleset disattivato per il tempo del merge

Solo se l'opzione 1 non basta (es. CI rotta per cause esterne, GitHub Actions down). L'org è sul
piano GitHub Free: la modalità `evaluate` dei ruleset non è disponibile, si usa `disabled`.

1. Snapshot **sempre nuovo**, in una cartella con data e ora (mai riusare uno snapshot esistente:
   potrebbe descrivere uno stato precedente e il ripristino declasserebbe il ruleset):
   ```sh
   D=~/gil-break-glass-$(date +%F-%H%M%S); mkdir -p $D
   gh api $R/rulesets/8751474 -q '{name,target,enforcement,conditions,bypass_actors,rules}' > $D/ruleset-main.json
   cat $D/ruleset-main.json   # controllare: enforcement "active", regola pull_request presente
   ```
2. Disattivare il ruleset "Main Branch" (id `8751474`):
   ```sh
   gh api -X PUT $R/rulesets/8751474 -f enforcement=disabled -q .enforcement   # atteso: disabled
   ```
3. Mergiare l'hotfix.
4. Ripristinare **subito** dallo snapshot del punto 1 e verificare che lo stato coincida per intero:
   ```sh
   gh api -X PUT $R/rulesets/8751474 --input $D/ruleset-main.json >/dev/null
   gh api $R/rulesets/8751474 -q '{name,target,enforcement,conditions,bypass_actors,rules}' > $D/after.json
   diff <(tr -d ' \n' < $D/ruleset-main.json) <(tr -d ' \n' < $D/after.json) && echo OK
   ```
   Nessun output di `diff` + `OK` = ruleset identico a prima. Altrimenti correggere a mano
   confrontando i due file.
5. Annotare nell'issue dell'hotfix: chi, quando, perché.

## Snapshot delle impostazioni

Per salvare lo stato completo (prima di modificare impostazioni o ruleset), sempre in una
cartella nuova con data e ora, fuori dal repo:

```sh
D=~/gil-settings-snapshot-$(date +%F-%H%M%S); mkdir -p $D
gh api $R > $D/repo.json
gh api $R/actions/permissions > $D/actions-permissions.json
gh api $R/rulesets -q '.[] | "\(.id) \(.name)"' > $D/rulesets.txt
for id in $(cut -d' ' -f1 $D/rulesets.txt); do
  gh api $R/rulesets/$id -q '{name,target,enforcement,conditions,bypass_actors,rules}' > $D/ruleset-$id.json
done
gh api $R/code-scanning/default-setup > $D/codeql.json
gh api $R/private-vulnerability-reporting > $D/pvr.json
```
