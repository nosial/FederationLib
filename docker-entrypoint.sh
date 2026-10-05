#!/bin/bash
set -e
echo "▗▄▄▄▖▗▄▄▄▖▗▄▄▄ ▗▄▄▄▖▗▄▄▖  ▗▄▖▗▄▄▄▖▗▄▄▄▖ ▗▄▖ ▗▖  ▗▖"
echo "▐▌   ▐▌   ▐▌  █▐▌   ▐▌ ▐▌▐▌ ▐▌ █    █  ▐▌ ▐▌▐▛▚▖▐▌"
echo "▐▛▀▀▘▐▛▀▀▘▐▌  █▐▛▀▀▘▐▛▀▚▖▐▛▀▜▌ █    █  ▐▌ ▐▌▐▌ ▝▜▌"
echo "▐▌   ▐▙▄▄▖▐▙▄▄▀▐▙▄▄▖▐▌ ▐▌▐▌ ▐▌ █  ▗▄█▄▖▝▚▄▞▘▐▌  ▐▌"

# AUTOSTART is the path to a shell script executed on every container start, before the plugins are installed and
# before FederationLib is initialized. It allows server hosts to prepare the environment, eg; installing additional
# services or dependencies required by plugins. The script is executed directly if it is executable (honoring its
# shebang), otherwise with bash. The container does not start if the script is missing or exits with a non-zero code.
if [ -n "${AUTOSTART:-}" ]; then
    if [ ! -f "$AUTOSTART" ]; then
        echo "The autostart script $AUTOSTART does not exist, aborting" >&2
        exit 1
    fi

    echo "Executing autostart script: $AUTOSTART"
    if [ -x "$AUTOSTART" ]; then
        AUTOSTART_COMMAND=("$AUTOSTART")
    else
        AUTOSTART_COMMAND=(bash "$AUTOSTART")
    fi

    if ! "${AUTOSTART_COMMAND[@]}"; then
        echo "The autostart script $AUTOSTART failed, aborting" >&2
        exit 1
    fi
fi

# REQUIRE_PLUGINS is a comma-separated list of ncc packages to install (or update) before FederationLib is
# initialized, each entry is anything `ncc install` accepts, eg; a remote package "nosial/plugin1@github" or the path
# to a local .ncc package. This only prepares the plugins, FederationLib loads the plugins listed by package name in
# the "plugins" configuration (FEDERATION_PLUGINS).
if [ -n "${REQUIRE_PLUGINS:-}" ]; then
    IFS=',' read -ra PLUGINS <<< "$REQUIRE_PLUGINS"
    for PLUGIN in "${PLUGINS[@]}"; do
        # Trim surrounding whitespace
        PLUGIN="$(echo "$PLUGIN" | xargs)"
        if [ -z "$PLUGIN" ]; then
            continue
        fi

        echo "Installing plugin: $PLUGIN"
        if ! ncc install --package="$PLUGIN" --yes --reinstall; then
            echo "Failed to install the plugin $PLUGIN, aborting" >&2
            exit 1
        fi
    done
fi

echo "Initializing FederationLib"
env -u LOGLIB_CONSOLE_ENABLED /usr/local/bin/federationlib init

echo "Starting services with supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf