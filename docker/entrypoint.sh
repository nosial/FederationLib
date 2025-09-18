#!/bin/bash

# Start the logger so it can capture logs
python3 /logger.py --port=5131 &

# Ensure upload directory has correct permissions
echo "Setting up upload directory permissions..."
mkdir -p /var/www/uploads
chown -R www-data:www-data /var/www/uploads
chmod -R 777 /var/www/uploads

# Initialize FederationServer
echo "Initializing FederationServer..."
/usr/bin/federationlib init --log-level=${LOG_LEVEL-INFO}

# Run supervisord
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf