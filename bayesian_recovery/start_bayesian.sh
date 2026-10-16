#!/bin/bash
set -u

MAX_MEMORY="${BAYESIAN_MAX_MEMORY:-4g}"
if [[ "$MAX_MEMORY" =~ ^([0-9]+(\.[0-9]+)?)%$ ]]; then
    HEAP_OPTION="-XX:MaxRAMPercentage=${BASH_REMATCH[1]}"
elif [[ "$MAX_MEMORY" =~ ^[1-9][0-9]*[kKmMgGtT]$ ]]; then
    HEAP_OPTION="-Xmx$MAX_MEMORY"
else
    echo "Invalid BAYESIAN_MAX_MEMORY '$MAX_MEMORY', expected a size such as 4g or 16384m, or a percentage such as 75%" >&2
    exit 1
fi

exec java "$HEAP_OPTION" -jar /usr/bin/bayesian.jar "$@"
