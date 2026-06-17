#!/bin/bash
set -e

# CloudPanel Git Addon - Deploy wrapper with logging
# https://github.com/Jefrien/cloudpanel-git-addon
#
# This script is invoked by the webhook server (running as the clp user).
# It runs the site's deploy script as the site user, optionally under an
# ssh-agent with the site's SSH key, and captures stdout/stderr to a per-site
# log file owned by clp.

DOMAIN="${1:-}"
SCRIPT_PATH="${2:-}"
SITE_USER="${3:-}"
KEY_PATH="${4:-}"

if [ -z "$DOMAIN" ] || [ -z "$SCRIPT_PATH" ] || [ -z "$SITE_USER" ]; then
    echo "Usage: $0 <domain> <deploy-script-path> <site-user> [key-path]" >&2
    exit 1
fi

LOG_DIR="/opt/clp-git-addon/logs"
LOG_FILE="$LOG_DIR/deploy-${DOMAIN}.log"

# Ensure the log directory exists and is writable by the clp user
mkdir -p "$LOG_DIR"

# Rotate log if it's larger than 1MB
if [ -f "$LOG_FILE" ] && [ "$(stat -c%s "$LOG_FILE" 2>/dev/null || echo 0)" -gt 1048576 ]; then
    mv "$LOG_FILE" "${LOG_FILE}.old"
fi

{
    echo "=== Deploy started at $(date -u +"%Y-%m-%d %H:%M:%S UTC") ==="
    echo "Domain: $DOMAIN"
    echo "Script: $SCRIPT_PATH"
    echo "User: $SITE_USER"
    echo "Key: $KEY_PATH"
    echo ""

    if [ ! -f "$SCRIPT_PATH" ]; then
        echo "ERROR: Deploy script not found: $SCRIPT_PATH"
        echo "=== Deploy finished at $(date -u +"%Y-%m-%d %H:%M:%S UTC") with exit code 1 ==="
        exit 1
    fi

    # Run the deploy script as the site user. If a key path is provided,
    # start an ssh-agent, add the key, and run the script in that environment
    # so plain git commands work without explicit SSH options.
    if [ -n "$KEY_PATH" ]; then
        sudo -u "$SITE_USER" bash -c 'eval $(ssh-agent -s) >/dev/null && ssh-add "$1" >/dev/null 2>&1 && bash "$2"' bash "$KEY_PATH" "$SCRIPT_PATH"
    else
        sudo -u "$SITE_USER" bash "$SCRIPT_PATH"
    fi
    EXIT_CODE=$?

    echo ""
    echo "=== Deploy finished at $(date -u +"%Y-%m-%d %H:%M:%S UTC") with exit code $EXIT_CODE ==="
    exit $EXIT_CODE
} >> "$LOG_FILE" 2>&1
