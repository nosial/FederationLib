#!/bin/bash

# Start the logger so it can capture logs
python3 /logger.py --port=5131 &

# Initialize Socialbox
echo "Initializing FederationServer..."
/usr/bin/federationserver init --log-level=${LOG_LEVEL-INFO}

# Run supervisord
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf