#!/bin/bash
# Setup ZBooks for WooCommerce development/test environment
#
# This script:
# 1. Loads Zoho credentials from .env.local
# 2. Sets up WordPress with the credentials
# 3. Works with both dev (cli) and test (tests-cli) environments
#
# Usage:
#   ./scripts/setup-env.sh         # Setup dev environment
#   ./scripts/setup-env.sh --tests # Setup tests environment

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Parse arguments
USE_TESTS=false
if [[ "$1" == "--tests" ]]; then
    USE_TESTS=true
    CONTAINER="tests-cli"
    ENV_NAME="tests"
else
    CONTAINER="cli"
    ENV_NAME="dev"
fi

echo "Setting up ${ENV_NAME} environment..."

# Load .env.local if it exists
ENV_FILE="${PROJECT_DIR}/.env.local"
if [[ -f "$ENV_FILE" ]]; then
    echo "Loading credentials from .env.local..."
    # Export variables from .env.local
    set -a
    source "$ENV_FILE"
    set +a
else
    echo "No .env.local file found."
    echo "Copy .env.local.example to .env.local and fill in your credentials."
    echo ""
    echo "If you're running in CI, ensure these environment variables are set:"
    echo "  ZOHO_CLIENT_ID"
    echo "  ZOHO_CLIENT_SECRET"
    echo "  ZOHO_REFRESH_TOKEN"
    echo "  ZOHO_ORGANIZATION_ID"
    echo "  ZOHO_DATACENTER (optional, defaults to 'us')"
fi

# Check if required variables are set
if [[ -z "$ZOHO_CLIENT_ID" || -z "$ZOHO_CLIENT_SECRET" || -z "$ZOHO_REFRESH_TOKEN" || -z "$ZOHO_ORGANIZATION_ID" ]]; then
    echo ""
    echo "Warning: Zoho credentials not fully configured."
    echo "E2E tests requiring Zoho will be skipped."
    exit 0
fi

# Check if wp-env is running
if ! npx wp-env run "$CONTAINER" wp core version &>/dev/null; then
    echo "wp-env is not running. Please start it first with: npm run env:start"
    exit 1
fi

echo "Running credential setup in ${ENV_NAME} environment..."

# Run the setup script via WP-CLI with environment variables
npx wp-env run "$CONTAINER" \
    env ZOHO_CLIENT_ID="$ZOHO_CLIENT_ID" \
    env ZOHO_CLIENT_SECRET="$ZOHO_CLIENT_SECRET" \
    env ZOHO_REFRESH_TOKEN="$ZOHO_REFRESH_TOKEN" \
    env ZOHO_ORGANIZATION_ID="$ZOHO_ORGANIZATION_ID" \
    env ZOHO_DATACENTER="${ZOHO_DATACENTER:-us}" \
    wp eval-file /var/www/html/wp-content/plugins/zbooks-for-woocommerce/scripts/setup-zoho-credentials.php

echo ""
echo "Setup complete for ${ENV_NAME} environment!"
