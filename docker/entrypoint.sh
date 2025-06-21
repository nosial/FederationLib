#!/bin/bash

# Start the logger so it can capture logs
python3 /logger.py --port=5131 &

# Fix permissions for the configuration directory
mkdir -p /etc/config
chown -R www-data:www-data /etc/config
chmod -R 777 /etc/config

# Initialize Socialbox
echo "Initializing FederationServer..."
/usr/bin/federationserver init --log-level=${LOG_LEVEL-INFO}

# Run supervisord
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf