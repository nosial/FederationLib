#!/bin/bash
curl -sf http://localhost:7000/ >/dev/null || exit 1

if [ -e /etc/supervisor/services/bayesian.conf ]; then
    curl -sf http://localhost:6380/ >/dev/null || exit 1
fi

(exec 3<>/dev/tcp/localhost/9003) 2>/dev/null || exit 1
