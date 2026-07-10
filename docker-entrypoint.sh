#!/bin/bash
set -e
echo "Initializing FederationLib"
/usr/local/bin/federationlib init
echo "Starting services with supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf