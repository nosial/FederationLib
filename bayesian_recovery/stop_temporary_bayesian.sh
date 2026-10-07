#!/bin/bash
#
#   Stops the BayesianServer started by temporary_start_bayesian.sh along with its supervisord instance, BayesianServer
#   processes its learning queue and saves the model when it stops. Does nothing if the temporary server is not
#   running, so it's safe to execute at any time before the services are started.
#
#   Exits with 0 once the server is stopped, or 1 if the server is still reachable afterwards. Processes that did not
#   stop within BAYESIAN_STOP_TIMEOUT seconds (default: 180, longer than the server's stopwaitsecs) are killed.
#

set -u

CONFIG=/etc/supervisor/temporary_bayesian.conf
HEALTH_URL=http://127.0.0.1:6380/health
TIMEOUT=${BAYESIAN_STOP_TIMEOUT:-180}

# True if the process exists and is not a zombie
is_running() {
    kill -0 "$1" 2>/dev/null && [ "$(awk '{print $3}' "/proc/$1/stat" 2>/dev/null)" != "Z" ]
}

SUPERVISORD_PID=$(supervisorctl -c "$CONFIG" pid 2>/dev/null) || true
if [[ "$SUPERVISORD_PID" =~ ^[0-9]+$ ]]; then
    BAYESIAN_PID=$(supervisorctl -c "$CONFIG" pid bayesian 2>/dev/null) || true
    echo "Stopping the temporary BayesianServer"

    # supervisord stops BayesianServer (waiting up to its stopwaitsecs) before it exits
    if ! supervisorctl -c "$CONFIG" shutdown >/dev/null 2>&1; then
        kill -TERM "$SUPERVISORD_PID" 2>/dev/null
    fi

    DEADLINE=$((SECONDS + TIMEOUT))
    while is_running "$SUPERVISORD_PID" && (( SECONDS < DEADLINE )); do
        sleep 1
    done

    if is_running "$SUPERVISORD_PID"; then
        echo "The temporary supervisord instance did not exit within $TIMEOUT seconds, killing it" >&2
        kill -KILL "$SUPERVISORD_PID" 2>/dev/null
    fi

    if [[ "$BAYESIAN_PID" =~ ^[1-9][0-9]*$ ]] && is_running "$BAYESIAN_PID"; then
        echo "BayesianServer did not exit with its supervisord instance, killing it" >&2
        kill -KILL "$BAYESIAN_PID" 2>/dev/null
    fi
fi

# The port must be free for the BayesianServer of the services
DEADLINE=$((SECONDS + 10))
while curl -sf --max-time 2 "$HEALTH_URL" >/dev/null 2>&1; do
    if (( SECONDS >= DEADLINE )); then
        echo "BayesianServer is still reachable at $HEALTH_URL" >&2
        exit 1
    fi

    sleep 1
done

if [[ "$SUPERVISORD_PID" =~ ^[0-9]+$ ]]; then
    echo "The temporary BayesianServer is stopped"
fi

exit 0
