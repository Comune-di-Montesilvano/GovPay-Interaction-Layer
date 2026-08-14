#!/bin/bash

set -e

CONFIG_FILE="api_config.json"
OUTPUT_DIR="generated-clients"

mkdir -p "$OUTPUT_DIR"

if ! command -v jq &> /dev/null
then
    echo "Errore: 'jq' non è installato. Installalo (es. sudo apt install jq)."
    exit 1
fi

NUM_APIS=$(jq length "$CONFIG_FILE")

for i in $(seq 0 $((NUM_APIS - 1))); do
    API_NAME=$(jq -r ".[$i].name" "$CONFIG_FILE")
    API_VERSION=$(jq -r ".[$i].version" "$CONFIG_FILE")
    BASE_URL=$(jq -r ".[$i].base_url" "$CONFIG_FILE")
    MAIN_FILE=$(jq -r ".[$i].main_file" "$CONFIG_FILE")
    CLIENT_DIR=$(jq -r ".[$i].client_dir" "$CONFIG_FILE")
    CLIENT_NAMESPACE=$(jq -r ".[$i].client_namespace" "$CONFIG_FILE")
    PACKAGE_NAME=$(jq -r ".[$i].package_name" "$CONFIG_FILE")

    WORKING_DIR="$OUTPUT_DIR/$API_NAME-$API_VERSION"
    BUNDLED_FILE="$API_NAME.bundled.json"

    echo "====================================================================="
    echo "INIZIO PROCESSO: $API_NAME ($API_VERSION) - Pacchetto: $PACKAGE_NAME"
    echo "====================================================================="

    mkdir -p "$WORKING_DIR"
    echo "   > Download $MAIN_FILE da $BASE_URL..."
    curl -s -o "$WORKING_DIR/$MAIN_FILE" "$BASE_URL/$MAIN_FILE"

    echo "   > Bundling (nessuna dipendenza attesa)..."
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -v "$(pwd)/$WORKING_DIR:/data" \
        redocly/cli:latest bundle \
        "/data/$MAIN_FILE" \
        --output "/data/$BUNDLED_FILE"

    echo "   > Generazione Client PHP ($CLIENT_DIR)..."
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -v "$(pwd)/$WORKING_DIR:/local" \
        openapitools/openapi-generator-cli generate \
        -i "/local/$BUNDLED_FILE" \
        -g php \
        -o "/local/$CLIENT_DIR" \
        --invoker-package "$CLIENT_NAMESPACE" \
        --additional-properties packageName="$CLIENT_NAMESPACE"

    echo "   > Correzione output: GuzzleHttp\\Utils::jsonEncode() -> json_encode() nativo"
    find "$WORKING_DIR/$CLIENT_DIR" -name '*.php' -print0 | xargs -0 sed -i -E \
        's/\\\\GuzzleHttp\\\\Utils::jsonEncode\(/json_encode(/g'

    # Validazione output: proprietà duplicate nello spec (es. PaymentInfo.iur nel Biz Events
    # pagoPA) producono getter/setter duplicati -> PHP Fatal "Cannot redeclare" al primo
    # autoload. Meglio fallire qui che scoprirlo in produzione.
    if command -v php &> /dev/null; then
        echo "   > Verifica sintattica (php -l) dei file generati..."
        LINT_FAILED=0
        while IFS= read -r -d '' phpfile; do
            if ! php -l "$phpfile" > /tmp/openapi_lint_out 2>&1; then
                echo "   > ERRORE DI SINTASSI in $phpfile:"
                cat /tmp/openapi_lint_out
                LINT_FAILED=1
            fi
        done < <(find "$WORKING_DIR/$CLIENT_DIR" -name '*.php' -print0)
        if [ "$LINT_FAILED" -eq 1 ]; then
            echo "Generazione client $API_NAME interrotta: uno o più file generati non passano php -l."
            echo "Probabile causa: proprietà duplicata nello spec OpenAPI sorgente."
            exit 1
        fi
    else
        echo "   > 'php' non trovato in PATH, salto la verifica sintattica dei file generati."
    fi

    COMPOSER_FILE="$WORKING_DIR/$CLIENT_DIR/composer.json"
    if [ -f "$COMPOSER_FILE" ]; then
        echo "   > Correzione composer.json: iniezione name/autoload"
        jq --arg name "$PACKAGE_NAME" '. + {name: $name}' "$COMPOSER_FILE" > temp.json && mv temp.json "$COMPOSER_FILE"

        NAMESPACE_KEY="${CLIENT_NAMESPACE}\\\\"
        jq ".autoload.psr-4 += {\"$NAMESPACE_KEY\": \"lib/\"}" "$COMPOSER_FILE" > temp.json && mv temp.json "$COMPOSER_FILE"
    else
        echo "   > ATTENZIONE: composer.json non trovato in $COMPOSER_FILE"
    fi

    echo "OK: client $API_NAME generato in $WORKING_DIR/$CLIENT_DIR"

done

echo "TUTTI I CLIENT PAGOpa GENERATI"
