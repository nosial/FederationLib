#!/bin/bash
set -u
source /usr/local/lib/federationlib/docker-functions.sh

MAX_MEMORY="${BAYESIAN_MAX_MEMORY:-4g}"
if [[ "$MAX_MEMORY" =~ ^([0-9]+(\.[0-9]+)?)%$ ]]; then
    HEAP_OPTION="-XX:MaxRAMPercentage=${BASH_REMATCH[1]}"
elif [[ "$MAX_MEMORY" =~ ^[1-9][0-9]*[kKmMgGtT]$ ]]; then
    HEAP_OPTION="-Xmx$MAX_MEMORY"
else
    echo "Invalid BAYESIAN_MAX_MEMORY '$MAX_MEMORY', expected a size such as 4g or 16384m, or a percentage such as 75%" >&2
    exit 1
fi

# BAYESIAN_EMBEDDINGS adds the sentence embedding model docker-entrypoint.sh downloaded to the logistic model
EMBEDDING_OPTIONS=()
EMBEDDING_DIR=/var/www/bayesian_model/embedding
if is_true BAYESIAN_EMBEDDINGS; then
    if [ -s "$EMBEDDING_DIR/model.onnx" ] && [ -s "$EMBEDDING_DIR/tokenizer.json" ]; then
        EMBEDDING_OPTIONS=(--embedding-model "$EMBEDDING_DIR")
    else
        echo "The embedding model is not in $EMBEDDING_DIR, starting BayesianServer without it" >&2
    fi
fi

# BAYESIAN_PLACEHOLDER_RULES is the placeholder rules file of BayesianServer, by default the bundled rules for Telegram
# links; "none" uses BayesianServer's generic rules only
PLACEHOLDER_OPTIONS=()
PLACEHOLDER_RULES="${BAYESIAN_PLACEHOLDER_RULES:-/etc/federation/bayesian_placeholder_rules.json}"
if [ "$PLACEHOLDER_RULES" != "none" ]; then
    if [ ! -f "$PLACEHOLDER_RULES" ]; then
        echo "The placeholder rules $PLACEHOLDER_RULES do not exist" >&2
        exit 1
    fi

    PLACEHOLDER_OPTIONS=(--placeholder-rules "$PLACEHOLDER_RULES")
fi

exec java "$HEAP_OPTION" -jar /usr/bin/bayesian.jar "$@" "${EMBEDDING_OPTIONS[@]}" "${PLACEHOLDER_OPTIONS[@]}"
