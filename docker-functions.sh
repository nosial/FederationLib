#!/bin/bash
# Prints "true" or "false" for the boolean environment variable named $1: true, 1, yes or on, and false, 0, no or off,
# in any case. An unset or empty variable is $2 (default: false). Fails (prints nothing) if the value is not a boolean.
parse_bool() {
    local value="${!1:-${2:-false}}"
    case "${value,,}" in
        true|1|yes|on) echo true ;;
        false|0|no|off) echo false ;;
        *) return 1 ;;
    esac
}

# Succeeds if the boolean environment variable named $1 is true (see parse_bool), an unset variable is $2 (default:
# false). A value that is not a boolean is false.
is_true() {
    [ "$(parse_bool "$1" "${2:-false}")" = true ]
}
