#!/bin/bash
set -e
echo "▗▄▄▄▖▗▄▄▄▖▗▄▄▄ ▗▄▄▄▖▗▄▄▖  ▗▄▖▗▄▄▄▖▗▄▄▄▖ ▗▄▖ ▗▖  ▗▖"
echo "▐▌   ▐▌   ▐▌  █▐▌   ▐▌ ▐▌▐▌ ▐▌ █    █  ▐▌ ▐▌▐▛▚▖▐▌"
echo "▐▛▀▀▘▐▛▀▀▘▐▌  █▐▛▀▀▘▐▛▀▚▖▐▛▀▜▌ █    █  ▐▌ ▐▌▐▌ ▝▜▌"
echo "▐▌   ▐▙▄▄▖▐▙▄▄▀▐▙▄▄▖▐▌ ▐▌▐▌ ▐▌ █  ▗▄█▄▖▝▚▄▞▘▐▌  ▐▌"

echo "Initializing FederationLib"
env -u LOGLIB_CONSOLE_ENABLED /usr/local/bin/federationlib init

echo "Starting services with supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf