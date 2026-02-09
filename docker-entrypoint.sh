#!/bin/bash
set -e

echo "Initializing FederationLib"
federationlib init

echo "Starting services with supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf