#!/bin/bash
#
#   Starts the bundled BayesianServer temporarily and exits once the server is reachable, leaving it running until
#   stop_temporary_bayesian.sh is executed. The server is run by a dedicated supervisord instance
#   (supervisord.temporary.conf) with the same program definition as the services (supervisord.bayesian.conf), without
#   starting any of the other services. Intended for `federationlib init`, which checks the Bayesian model before the
#   services are started.
#
#   Exits with 0 once the server is reachable, or 1 if it failed to start or did not become reachable within
#   BAYESIAN_START_TIMEOUT seconds (default: 300, a large model takes a while to load and a server that does not become
#   reachable in time is considered broken). The server is not stopped on failure, use stop_temporary_bayesian.sh
#

set -u

CONFIG=/etc/supervisor/temporary_bayesian.conf
HEALTH_URL=http://127.0.0.1:6380/health
TIMEOUT=${BAYESIAN_START_TIMEOUT:-300}

# True if the process exists and is not a zombie
is_running() {
    kill -0 "$1" 2>/dev/null && [ "$(awk '{print $3}' "/proc/$1/stat" 2>/dev/null)" != "Z" ]
}

SUPERVISORD_PID=$(supervisorctl -c "$CONFIG" pid 2>/dev/null) || true
if [[ "$SUPERVISORD_PID" =~ ^[0-9]+$ ]]; then
    echo "The temporary BayesianServer is already running"
else
    mkdir -p /var/log/supervisor
    # supervisord stays in the foreground of this background job, so it (and BayesianServer) writes to the output of
    # this script and keeps running after it exits
    supervisord -c "$CONFIG" </dev/null &
    SUPERVISORD_PID=$!
    echo "Starting BayesianServer temporarily (supervisord pid $SUPERVISORD_PID)"
fi

DEADLINE=$((SECONDS + TIMEOUT))
while (( SECONDS < DEADLINE )); do
    if curl -sf --max-time 2 "$HEALTH_URL" >/dev/null 2>&1; then
        echo "BayesianServer is reachable"
        exit 0
    fi

    if ! is_running "$SUPERVISORD_PID"; then
        echo "The temporary supervisord instance exited before BayesianServer became reachable" >&2
        exit 1
    fi

    # supervisord gave up restarting BayesianServer, eg; it exits immediately because the model can't be loaded
    if [ "$(supervisorctl -c "$CONFIG" status bayesian 2>/dev/null | awk '{print $2}')" = "FATAL" ]; then
        echo "BayesianServer failed to start" >&2
        exit 1
    fi

    sleep 1
done

echo "BayesianServer did not become reachable within $TIMEOUT seconds" >&2
exit 1
