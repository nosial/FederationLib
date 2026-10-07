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

# BayesianPlugin is installed in the image alongside the bundled BayesianServer, it's always enabled as the first plugin
# in FEDERATION_PLUGINS, before any plugins configured by the server host (a duplicate entry is dropped)
BAYESIAN_PLUGIN="net.nosial.bayesian_plugin"
PLUGINS_LIST="$BAYESIAN_PLUGIN"
if [ -n "${FEDERATION_PLUGINS:-}" ]; then
    IFS=',' read -ra CONFIGURED_PLUGINS <<< "$FEDERATION_PLUGINS"
    for PLUGIN in "${CONFIGURED_PLUGINS[@]}"; do
        PLUGIN="$(echo "$PLUGIN" | xargs)"
        if [ -z "$PLUGIN" ] || [ "$PLUGIN" = "$BAYESIAN_PLUGIN" ]; then
            continue
        fi

        PLUGINS_LIST="$PLUGINS_LIST,$PLUGIN"
    done
fi
export FEDERATION_PLUGINS="$PLUGINS_LIST"
echo "Enabled plugins: $FEDERATION_PLUGINS"

# BayesianServer stores its model in the "model" directory of the volume, next to the archive (archive.csv) and the
# backups of the model made by `federationlib init` (backups/). Volumes from before this layout contain the model files
# directly, those are moved into the model directory once.
BAYESIAN_DATA="/var/www/bayesian_model"
if [ ! -d "$BAYESIAN_DATA/model" ]; then
    mkdir -p "$BAYESIAN_DATA/model"
    for ENTRY in "$BAYESIAN_DATA"/*; do
        case "$(basename "$ENTRY")" in
            model|backups|archive.csv|\*) continue ;;
        esac

        echo "Moving $ENTRY to the Bayesian model directory"
        mv "$ENTRY" "$BAYESIAN_DATA/model/"
    done
fi

# `federationlib init` starts BayesianServer temporarily to check the Bayesian model, the temporary server is always
# stopped (even if init failed) so that it can't conflict with the BayesianServer of the services
echo "Initializing FederationLib"
if ! env -u LOGLIB_CONSOLE_ENABLED /usr/local/bin/federationlib init; then
    /usr/local/bin/stop_temporary_bayesian.sh || true
    echo "FederationLib failed to initialize, aborting" >&2
    exit 1
fi

if ! /usr/local/bin/stop_temporary_bayesian.sh; then
    echo "Failed to stop the temporary BayesianServer, aborting" >&2
    exit 1
fi

# I wanted to include this cool thumbs up ASCII art
# I don't know who the author is, but good job.
echo "⠀⠀⠀⠀⠀⡠⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⠀⠀⠀⢸⠁⢹⡄⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⠀⠀⠀⢸⣇⡀⠳⣄⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⠀⠀⠀⠀⠻⣯⣢⡈⠓⠦⣀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⠀⠀⠀⠀⠀⠈⠻⣿⣦⠤⡄⠹⢦⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⠀⠀⠀⠀⠀⠀⠀⣻⣿⣷⡥⠀⠀⠙⢤⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⣠⣴⡖⠖⡟⣿⣿⠋⢹⡃⠀⠀⠀⠀⠀⠑⢄⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠸⣿⣻⢿⣦⣌⣷⣿⣷⣀⣧⠀⠀⠀⠀⠀⠀⠈⠳⢄⣀⣀⣀⠀⡀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⣹⡿⠾⠿⢿⡿⣿⣿⡿⣿⣦⣦⡄⠀⠀⠀⢂⢠⠈⠙⠛⠛⠒⠓⠒⠶⠒⠤⠦⠤⠤⢤⢤⣀⣀⡀⠀⠀⠀⠀⠀⠀⢀⡀⣀⣀⣀⡀⡀⠀⠠⠤⠠⠤"
echo "⠸⣟⣙⠋⡙⠂⢀⣸⣿⡿⢿⣿⣏⢿⣦⠀⠀⡨⠟⠀⢀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠄⠀⠀⠙⠛⠋⠉⠉⠛⠉⠋⠉⠉⠁⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⢨⣿⣻⣟⠻⠛⢋⣹⣿⡦⠽⠻⠟⠾⠥⠌⠀⠀⠀⣧⡀⠀⠀⠀⠂⠤⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠁⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⠙⣿⣥⣤⣤⡶⠾⣿⣿⠁⠉⠀⠁⠀⠀⠀⠀⠀⠀⣹⢷⣦⠁⠓⢂⠲⢤⡄⣀⢀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀"
echo "⠀⠀⠀⣼⡝⢃⣠⣴⡿⢻⠀⠀⠀⢀⣀⠀⣀⣈⣡⣀⣿⣦⣿⣸⣹⣶⢏⣿⡼⣷⢯⣿⣆⣶⣆⣠⡠⣀⣄⠈⣀⠀⡀⠀⠀⠀⠀⠀⠀⠀⡀⠀⠂⢨⣹"
echo "⠀⠀⠀⠈⠛⠿⠿⠯⠷⠾⠾⢶⣶⣿⡿⠿⠟⠛⠉⠉⠉⠉⠉⠙⠛⠛⠛⠛⠻⠿⠿⠿⣿⣿⣿⣽⣷⣽⣭⣳⣤⢣⠔⡣⢆⡰⢄⡒⣌⠰⢹⣀⠾⠷⣾"
echo "⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠉⠉⠉⠛⠛⠿⠿⢿⣷⣯⣶⣧⣻⣬⡻⣵⣺⣷⣾⣿"
echo "⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠀⠉⠉⠛⠛⠻⠷⠿⣿⣿⣿"
echo "Everything appears is OK to run"

echo "Starting services with supervisord..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf