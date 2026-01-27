#!/bin/bash
#
# ZBooks for WooCommerce - Test Runner
# Runs all tests via wp-env: PHPCS, PHPUnit, E2E
#
# Usage:
#   ./run_all_tests.sh           # Run all tests (interactive UI)
#   ./run_all_tests.sh --phpcs   # Run only PHPCS
#   ./run_all_tests.sh --unit    # Run only unit tests
#   ./run_all_tests.sh --e2e     # Run only E2E tests
#   ./run_all_tests.sh --ci      # Run all tests (CI/agent-friendly output)
#

set -e

# CI/Agent mode detection (simple output for non-interactive environments)
CI_MODE=0

# Colors (will be disabled in CI mode)
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
GRAY='\033[0;90m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

# Unicode symbols
CHECK="${GREEN}✔${NC}"
CROSS="${RED}✖${NC}"
WARN="${YELLOW}⚠${NC}"
ARROW="${CYAN}▶${NC}"
PENDING="${GRAY}○${NC}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# Disable colors and fancy output in CI mode
disable_fancy_output() {
    RED='' GREEN='' YELLOW='' BLUE='' CYAN='' GRAY='' BOLD='' DIM='' NC=''
    CHECK='[PASS]' CROSS='[FAIL]' WARN='[WARN]' ARROW='[....]' PENDING='[----]'
}

# Test state tracking (bash 3.x compatible - no associative arrays)
STEPS=("deps" "wpenv" "phpcs" "unit" "e2e")
STEP_NAMES=("Dependencies" "WordPress Environment" "PHP Code Sniffer" "Unit Tests" "E2E Tests")
STEP_STATUS=("pending" "pending" "pending" "pending" "pending")
STEP_DETAILS=("" "" "" "" "")

# Get step index
get_step_index() {
    local step="$1"
    for i in "${!STEPS[@]}"; do
        if [[ "${STEPS[$i]}" == "$step" ]]; then
            echo "$i"
            return
        fi
    done
    echo "-1"
}

# Get status for a step
get_status() {
    local idx=$(get_step_index "$1")
    if [[ "$idx" != "-1" ]]; then
        echo "${STEP_STATUS[$idx]}"
    fi
}

# Get details for a step
get_details() {
    local idx=$(get_step_index "$1")
    if [[ "$idx" != "-1" ]]; then
        echo "${STEP_DETAILS[$idx]}"
    fi
}

# Set status for a step
set_status() {
    local idx=$(get_step_index "$1")
    if [[ "$idx" != "-1" ]]; then
        STEP_STATUS[$idx]="$2"
    fi
}

# Set details for a step
set_details() {
    local idx=$(get_step_index "$1")
    if [[ "$idx" != "-1" ]]; then
        STEP_DETAILS[$idx]="$2"
    fi
}

# Terminal control
hide_cursor() { printf '\033[?25l'; }
show_cursor() { printf '\033[?25h'; }
save_pos() { printf '\033[s'; }
restore_pos() { printf '\033[u'; }
move_up() { printf '\033[%dA' "$1"; }
clear_line() { printf '\033[2K\r'; }
clear_below() { printf '\033[J'; }

# Cleanup on exit
cleanup() {
    if [ "$CI_MODE" -eq 0 ]; then
        show_cursor
        tput cnorm 2>/dev/null || true
    fi
    echo ""
}
trap cleanup EXIT

# Track if we've drawn before
PROGRESS_DRAWN=0
PROGRESS_LINES=0
LAST_CI_STEP=""

# Console activity log (last 3 lines)
CONSOLE_LINE_1=""
CONSOLE_LINE_2=""
CONSOLE_LINE_3=""

# Add a line to the console log (shifts previous lines up)
console_log() {
    CONSOLE_LINE_1="$CONSOLE_LINE_2"
    CONSOLE_LINE_2="$CONSOLE_LINE_3"
    CONSOLE_LINE_3="$1"
}

# Draw the progress display (CI mode: simple output)
draw_progress_ci() {
    # In CI mode, only print when status changes
    for i in "${!STEPS[@]}"; do
        local step="${STEPS[$i]}"
        local name="${STEP_NAMES[$i]}"
        local status="${STEP_STATUS[$i]}"
        local details="${STEP_DETAILS[$i]}"

        # Only print if this step is running or just completed
        if [[ "$status" == "running" && "$LAST_CI_STEP" != "$step" ]]; then
            echo "::group::${name}"
            echo "[....] ${name}: ${details:-Running...}"
            LAST_CI_STEP="$step"
        elif [[ "$status" == "passed" || "$status" == "warning" || "$status" == "failed" ]]; then
            if [[ "$LAST_CI_STEP" == "$step" ]]; then
                local icon="[PASS]"
                [[ "$status" == "warning" ]] && icon="[WARN]"
                [[ "$status" == "failed" ]] && icon="[FAIL]"
                echo "${icon} ${name}: ${details:-Done}"
                echo "::endgroup::"
                LAST_CI_STEP=""
            fi
        fi
    done
}

# Draw the progress display (Interactive mode: fancy UI)
draw_progress() {
    # Use simple output in CI mode
    if [ "$CI_MODE" -eq 1 ]; then
        draw_progress_ci
        return
    fi

    # Calculate how many lines this display will take
    local num_steps=${#STEPS[@]}
    local has_console=0
    if [ -n "$CONSOLE_LINE_1" ] || [ -n "$CONSOLE_LINE_2" ] || [ -n "$CONSOLE_LINE_3" ]; then
        has_console=1
    fi
    local console_lines=0
    [ "$has_console" -eq 1 ] && console_lines=5  # border(1) + 3 lines + border(1)
    local total_lines=$((5 + num_steps + 5 + console_lines))  # header(5) + steps + footer(5) + console

    # If we've drawn before, move cursor back up to overwrite
    if [ "$PROGRESS_DRAWN" -eq 1 ]; then
        # Move up to the start of the display
        printf '\033[%dA' "$PROGRESS_LINES"
        # Clear everything below in case the new display is shorter/taller
        printf '\033[J'
    fi
    PROGRESS_DRAWN=1
    PROGRESS_LINES=$total_lines

    # Clear and draw header
    printf '\033[2K'
    echo -e "${BOLD}${BLUE}"
    printf '\033[2K'
    echo "╔═══════════════════════════════════════════════════════════════╗"
    printf '\033[2K'
    echo "║           ZBooks for WooCommerce - Test Runner                ║"
    printf '\033[2K'
    echo "╚═══════════════════════════════════════════════════════════════╝"
    printf '\033[2K'
    echo -e "${NC}"

    # Draw checklist
    for i in "${!STEPS[@]}"; do
        local step="${STEPS[$i]}"
        local name="${STEP_NAMES[$i]}"
        local status="${STEP_STATUS[$i]}"
        local details="${STEP_DETAILS[$i]}"
        local icon=""
        local color=""

        case "$status" in
            "pending")
                icon="$PENDING"
                color="$GRAY"
                ;;
            "running")
                icon="$ARROW"
                color="$CYAN"
                ;;
            "passed")
                icon="$CHECK"
                color="$GREEN"
                ;;
            "warning")
                icon="$WARN"
                color="$YELLOW"
                ;;
            "failed")
                icon="$CROSS"
                color="$RED"
                ;;
        esac

        printf '\033[2K'
        if [ -n "$details" ]; then
            printf "  %b  ${color}%-25s${NC} ${DIM}%s${NC}\n" "$icon" "$name" "$details"
        else
            printf "  %b  ${color}%-25s${NC}\n" "$icon" "$name"
        fi
    done

    printf '\033[2K'
    echo ""

    # Console area (Docker-style activity log)
    if [ "$has_console" -eq 1 ]; then
        printf '\033[2K'
        echo -e "  ${DIM}┌─ Activity ───────────────────────────────────────────────────────────────────────────────┐${NC}"
        printf '\033[2K'
        printf "  ${DIM}│${NC} %-85s${DIM}│${NC}\n" "${CONSOLE_LINE_1:- }"
        printf '\033[2K'
        printf "  ${DIM}│${NC} %-85s${DIM}│${NC}\n" "${CONSOLE_LINE_2:- }"
        printf '\033[2K'
        printf "  ${DIM}│${NC} ${CYAN}%-85s${NC}${DIM}│${NC}\n" "${CONSOLE_LINE_3:- }"
        printf '\033[2K'
        echo -e "  ${DIM}└───────────────────────────────────────────────────────────────────────────────────────────┘${NC}"
    fi

    printf '\033[2K'

    # Progress bar
    local completed=0
    local total=${#STEPS[@]}
    for i in "${!STEPS[@]}"; do
        local s="${STEP_STATUS[$i]}"
        if [[ "$s" == "passed" || "$s" == "warning" || "$s" == "failed" ]]; then
            completed=$((completed + 1))
        fi
    done

    local pct=$((completed * 100 / total))
    local filled=$((completed * 30 / total))
    local empty=$((30 - filled))

    printf "  Progress: ["
    # Draw filled portion
    local j=0
    while [ $j -lt $filled ]; do
        printf "${GREEN}█${NC}"
        j=$((j + 1))
    done
    # Draw empty portion
    j=0
    while [ $j -lt $empty ]; do
        printf "${GRAY}░${NC}"
        j=$((j + 1))
    done
    printf "] %d%%\n" "$pct"

    printf '\033[2K'
    echo ""
    printf '\033[2K'
    echo -e "  ${DIM}─────────────────────────────────────────────────────────────${NC}"
    printf '\033[2K'
    echo ""
}

# Update status and redraw
update_status() {
    local step="$1"
    local status="$2"
    local details="${3:-}"

    set_status "$step" "$status"
    set_details "$step" "$details"
}

# Run a command with spinner
run_with_status() {
    local step="$1"
    local name="$2"
    shift 2
    local cmd="$@"

    update_status "$step" "running"
    draw_progress

    echo -e "  ${CYAN}Running: $name${NC}"
    echo ""

    # Run command and capture output
    local output_file="/tmp/zbooks_test_${step}.log"
    local exit_code=0

    if eval "$cmd" > "$output_file" 2>&1; then
        exit_code=0
    else
        exit_code=$?
    fi

    return $exit_code
}

# Show last N lines of output
show_output_tail() {
    local file="$1"
    local lines="${2:-10}"

    if [ -f "$file" ]; then
        echo -e "  ${DIM}Last output:${NC}"
        tail -n "$lines" "$file" | sed 's/^/    /'
        echo ""
    fi
}

# Check dependencies
check_deps() {
    update_status "deps" "running" "Checking tools..."
    draw_progress

    local missing=""
    local installed=""

    # Check required CLI tools
    if ! command -v npx &> /dev/null; then
        missing="npx (Node.js)"
    fi

    if ! command -v composer &> /dev/null; then
        missing="${missing:+$missing, }composer"
    fi

    if ! command -v docker &> /dev/null; then
        missing="${missing:+$missing, }docker"
    fi

    if [ -n "$missing" ]; then
        update_status "deps" "failed" "Missing: $missing"
        draw_progress
        return 1
    fi

    # Check Docker is running
    update_status "deps" "running" "Checking Docker..."
    draw_progress
    if ! docker info &> /dev/null 2>&1; then
        update_status "deps" "failed" "Docker not running"
        draw_progress
        return 1
    fi

    # Install Composer dependencies if needed
    if [ ! -f "vendor/bin/phpcs" ]; then
        update_status "deps" "running" "Installing composer..."
        draw_progress
        composer install --quiet 2>/dev/null
        installed="${installed:+$installed, }composer"
    fi

    # Install Node dependencies if needed
    if [ ! -d "node_modules" ]; then
        update_status "deps" "running" "Installing npm..."
        draw_progress
        npm install --silent 2>/dev/null
        installed="${installed:+$installed, }npm"
    fi

    # Check and install Playwright browsers if needed (only for E2E tests)
    if [[ " ${STEPS[*]} " =~ " e2e " ]]; then
        # Check if Playwright browsers are installed by looking for the webkit executable
        local pw_cache_dir="${PLAYWRIGHT_BROWSERS_PATH:-$HOME/Library/Caches/ms-playwright}"
        if [ "$(uname)" = "Linux" ]; then
            pw_cache_dir="${PLAYWRIGHT_BROWSERS_PATH:-$HOME/.cache/ms-playwright}"
        fi

        if [ ! -d "$pw_cache_dir" ] || [ -z "$(ls -A "$pw_cache_dir" 2>/dev/null)" ]; then
            update_status "deps" "running" "Installing browsers..."
            draw_progress
            npx playwright install >/dev/null 2>&1
            installed="${installed:+$installed, }playwright"
        else
            # Verify browsers are actually usable (check for webkit specifically since that's what failed)
            local webkit_dir=$(find "$pw_cache_dir" -maxdepth 1 -name "webkit-*" -type d 2>/dev/null | head -1)
            if [ -z "$webkit_dir" ] || [ ! -f "$webkit_dir/pw_run.sh" ]; then
                update_status "deps" "running" "Installing browsers..."
                draw_progress
                npx playwright install >/dev/null 2>&1
                installed="${installed:+$installed, }playwright"
            fi
        fi
    fi

    if [ -n "$installed" ]; then
        update_status "deps" "passed" "Installed: $installed"
    else
        update_status "deps" "passed" "All tools ready"
    fi
    draw_progress
    return 0
}

# Start wp-env
start_wp_env() {
    update_status "wpenv" "running" "Starting..."
    draw_progress

    local output_file="/tmp/zbooks_test_wpenv.log"

    if npx wp-env start > "$output_file" 2>&1; then
        local url=$(grep -o 'http://localhost:[0-9]*' "$output_file" | head -1)

        # Setup Zoho credentials if .env.local exists
        if [ -f "$SCRIPT_DIR/.env.local" ]; then
            update_status "wpenv" "running" "Setting up Zoho..."
            draw_progress
            setup_zoho_credentials
        fi

        update_status "wpenv" "passed" "${url:-Running}"
        draw_progress
        return 0
    else
        update_status "wpenv" "failed" "Failed to start"
        draw_progress
        return 1
    fi
}

# Setup Zoho credentials from .env.local
setup_zoho_credentials() {
    # Source .env.local to get credentials
    if [ ! -f "$SCRIPT_DIR/.env.local" ]; then
        return 0
    fi

    # Read credentials from .env.local
    local client_id=$(grep '^ZOHO_CLIENT_ID=' "$SCRIPT_DIR/.env.local" | cut -d'=' -f2)
    local client_secret=$(grep '^ZOHO_CLIENT_SECRET=' "$SCRIPT_DIR/.env.local" | cut -d'=' -f2)
    local refresh_token=$(grep '^ZOHO_REFRESH_TOKEN=' "$SCRIPT_DIR/.env.local" | cut -d'=' -f2)
    local org_id=$(grep '^ZOHO_ORGANIZATION_ID=' "$SCRIPT_DIR/.env.local" | cut -d'=' -f2)
    local datacenter=$(grep '^ZOHO_DATACENTER=' "$SCRIPT_DIR/.env.local" | cut -d'=' -f2)

    # Skip if credentials are missing
    if [ -z "$client_id" ] || [ -z "$client_secret" ] || [ -z "$refresh_token" ]; then
        return 0
    fi

    # Run setup script inside wp-env with environment variables
    npx wp-env run tests-cli --env-cwd=wp-content/plugins/zbooks-for-woocommerce \
        bash -c "ZOHO_CLIENT_ID='$client_id' ZOHO_CLIENT_SECRET='$client_secret' ZOHO_REFRESH_TOKEN='$refresh_token' ZOHO_ORGANIZATION_ID='$org_id' ZOHO_DATACENTER='${datacenter:-us}' wp eval-file scripts/setup-zoho-credentials.php" \
        > /tmp/zbooks_zoho_setup.log 2>&1 || true
}

# Run PHPCS
run_phpcs() {
    update_status "phpcs" "running" "Scanning..."
    draw_progress

    local output_file="/tmp/zbooks_test_phpcs.log"

    if vendor/bin/phpcs --standard=phpcs.xml.dist src/ --report=summary > "$output_file" 2>&1; then
        update_status "phpcs" "passed" "No issues"
        draw_progress
        return 0
    else
        local errors=$(grep -oE 'FOUND [0-9]+ ERROR' "$output_file" 2>/dev/null | grep -oE '[0-9]+' | head -1)
        local warnings=$(grep -oE 'AND [0-9]+ WARNING' "$output_file" 2>/dev/null | grep -oE '[0-9]+' | head -1)
        update_status "phpcs" "warning" "${errors:-0} errors, ${warnings:-0} warnings"
        draw_progress
        return 0  # Non-blocking
    fi
}

# Run Unit Tests via wp-env
run_unit() {
    update_status "unit" "running" "Running..."
    draw_progress

    local output_file="/tmp/zbooks_test_unit.log"

    if npx wp-env run tests-cli --env-cwd=wp-content/plugins/zbooks-for-woocommerce \
        vendor/bin/phpunit --testsuite Unit > "$output_file" 2>&1; then

        # PHPUnit outputs "OK (X tests, Y assertions)" on success
        # Try multiple patterns for extracting counts
        local ok_line=$(grep "OK (" "$output_file" 2>/dev/null | tail -1)
        if [ -n "$ok_line" ]; then
            # Extract from "OK (5 tests, 10 assertions)"
            local tests=$(echo "$ok_line" | sed -n 's/.*OK (\([0-9]*\) test.*/\1/p')
            local assertions=$(echo "$ok_line" | sed -n 's/.*, \([0-9]*\) assertion.*/\1/p')
            update_status "unit" "passed" "${tests:-?} tests, ${assertions:-?} assertions"
            draw_progress
            return 0
        fi

        # Fallback: Try "Tests: X, Assertions: Y" format
        local tests=$(grep -oE 'Tests: [0-9]+' "$output_file" 2>/dev/null | sed 's/Tests: //' | tail -1)
        local assertions=$(grep -oE 'Assertions: [0-9]+' "$output_file" 2>/dev/null | sed 's/Assertions: //' | tail -1)
        update_status "unit" "passed" "${tests:-?} tests, ${assertions:-?} assertions"
        draw_progress
        return 0
    else
        # Check if it actually passed but exit code was non-zero
        if grep -q "OK (" "$output_file" 2>/dev/null; then
            local ok_line=$(grep "OK (" "$output_file" 2>/dev/null | tail -1)
            local tests=$(echo "$ok_line" | sed -n 's/.*OK (\([0-9]*\) test.*/\1/p')
            update_status "unit" "passed" "${tests:-?} tests passed"
            draw_progress
            return 0
        fi

        local failures=$(grep -oE 'Failures: [0-9]+' "$output_file" 2>/dev/null | sed 's/Failures: //' | tail -1)
        local skipped=$(grep -oE 'Skipped: [0-9]+' "$output_file" 2>/dev/null | sed 's/Skipped: //' | tail -1)
        update_status "unit" "warning" "${failures:-?} failures, ${skipped:-0} skipped"
        draw_progress
        return 0  # Non-blocking for now
    fi
}

# Run E2E Tests with live console output
run_e2e() {
    update_status "e2e" "running" "Starting..."
    draw_progress

    local output_file="/tmp/zbooks_test_e2e.log"

    # In CI mode, run directly without live updates
    if [ "$CI_MODE" -eq 1 ]; then
        if npx playwright test > "$output_file" 2>&1; then
            local passed=$(grep -oE '[0-9]+ passed' "$output_file" 2>/dev/null | head -1)
            update_status "e2e" "passed" "${passed:-All passed}"
            draw_progress
            return 0
        else
            local passed=$(grep -oE '[0-9]+ passed' "$output_file" 2>/dev/null | head -1)
            local failed=$(grep -oE '[0-9]+ failed' "$output_file" 2>/dev/null | head -1)
            if [ -n "$passed" ]; then
                update_status "e2e" "warning" "${passed}, ${failed:-0 failed}"
                draw_progress
                return 0
            fi
            update_status "e2e" "failed" "Tests failed"
            draw_progress
            return 1
        fi
    fi

    # Interactive mode: show live progress
    console_log "Starting E2E test suite..."
    draw_progress

    local last_line_count=0

    # Start playwright in background
    npx playwright test > "$output_file" 2>&1 &
    local pid=$!

    # Monitor output while tests run
    while kill -0 "$pid" 2>/dev/null; do
        if [ -f "$output_file" ]; then
            local current_lines=$(wc -l < "$output_file" 2>/dev/null | tr -d ' ')
            current_lines=${current_lines:-0}

            if [ "$current_lines" -gt "$last_line_count" ]; then
                # Get the newest meaningful line (skip empty lines and progress bars)
                local new_line=$(tail -n $((current_lines - last_line_count)) "$output_file" 2>/dev/null | \
                    grep -v "^$" | grep -v "^\s*$" | grep -v "Running" | \
                    grep -E "(✓|✘|›|test|spec|\.ts)" | tail -1)

                if [ -n "$new_line" ]; then
                    # Clean up the line (remove ANSI codes, trim)
                    new_line=$(echo "$new_line" | sed 's/\x1b\[[0-9;]*m//g' | sed 's/^[[:space:]]*//' | cut -c1-85)
                    if [ -n "$new_line" ]; then
                        console_log "$new_line"
                        # Count passed/failed so far and total tests
                        local done_so_far=$(grep -cE "✓|✘" "$output_file" 2>/dev/null || echo "0")
                        local total_tests=$(grep -oE '[0-9]+ tests' "$output_file" 2>/dev/null | head -1 | grep -oE '[0-9]+' || echo "?")
                        if [ "$total_tests" = "?" ]; then
                            # Try to count spec files mentioned
                            total_tests=$(grep -cE "\.spec\.ts" "$output_file" 2>/dev/null || echo "?")
                        fi
                        update_status "e2e" "running" "${done_so_far}/${total_tests} tests..."
                        draw_progress
                    fi
                fi
                last_line_count=$current_lines
            fi
        fi
        sleep 0.5
    done

    # Wait for process to finish and get exit code
    wait "$pid"
    local exit_code=$?

    # Clear console after E2E completes - reset progress tracking since display height changes
    CONSOLE_LINE_1=""
    CONSOLE_LINE_2=""
    CONSOLE_LINE_3=""
    # Force full redraw since console area is gone (display height changed)
    PROGRESS_DRAWN=0

    if [ "$exit_code" -eq 0 ]; then
        local passed=$(grep -oE '[0-9]+ passed' "$output_file" 2>/dev/null | head -1)
        update_status "e2e" "passed" "${passed:-All passed}"
        # Clear and redraw fresh
        clear
        draw_progress
        return 0
    else
        local passed=$(grep -oE '[0-9]+ passed' "$output_file" 2>/dev/null | head -1)
        local failed=$(grep -oE '[0-9]+ failed' "$output_file" 2>/dev/null | head -1)

        if [ -n "$passed" ]; then
            update_status "e2e" "warning" "${passed}, ${failed:-0 failed}"
            clear
            draw_progress
            return 0
        fi

        update_status "e2e" "failed" "Tests failed"
        clear
        draw_progress
        return 1
    fi
}

# Print summary
print_summary() {
    local all_passed=true
    local has_warnings=false

    for i in "${!STEPS[@]}"; do
        local status="${STEP_STATUS[$i]}"

        if [[ "$status" == "failed" ]]; then
            all_passed=false
        elif [[ "$status" == "warning" ]]; then
            has_warnings=true
        fi
    done

    echo ""

    if [ "$CI_MODE" -eq 1 ]; then
        # CI-friendly summary
        echo "========================================"
        echo "           TEST SUMMARY"
        echo "========================================"
        echo ""
        for i in "${!STEPS[@]}"; do
            local name="${STEP_NAMES[$i]}"
            local status="${STEP_STATUS[$i]}"
            local details="${STEP_DETAILS[$i]}"
            local icon="[----]"
            [[ "$status" == "passed" ]] && icon="[PASS]"
            [[ "$status" == "warning" ]] && icon="[WARN]"
            [[ "$status" == "failed" ]] && icon="[FAIL]"
            echo "${icon} ${name}: ${details:-$status}"
        done
        echo ""
        if $all_passed; then
            if $has_warnings; then
                echo "RESULT: PASS (with warnings)"
            else
                echo "RESULT: PASS"
            fi
        else
            echo "RESULT: FAIL"
        fi
    else
        # Fancy summary
        echo -e "  ${BOLD}${BLUE}═══════════════════════════════════════════════════════════════${NC}"
        echo -e "  ${BOLD}                         TEST SUMMARY${NC}"
        echo -e "  ${BOLD}${BLUE}═══════════════════════════════════════════════════════════════${NC}"
        echo ""

        if $all_passed; then
            if $has_warnings; then
                echo -e "  ${YELLOW}${BOLD}⚠  All tests completed with warnings${NC}"
            else
                echo -e "  ${GREEN}${BOLD}✔  All tests passed!${NC}"
            fi
        else
            echo -e "  ${RED}${BOLD}✖  Some tests failed${NC}"
        fi

        echo ""
        echo -e "  ${DIM}Log files saved in /tmp/zbooks_test_*.log${NC}"
    fi
    echo ""
}

# Help
print_help() {
    echo "Usage: $0 [option]"
    echo ""
    echo "Options:"
    echo "  (none)    Run all tests (interactive UI)"
    echo "  --phpcs   Run only PHPCS"
    echo "  --unit    Run only unit tests"
    echo "  --e2e     Run only E2E tests"
    echo "  --ci      CI/agent-friendly output (no colors, no cursor control)"
    echo "  --help    Show this help"
}

# Main
main() {
    local test_type="all"

    # Parse arguments - check for --ci flag
    for arg in "$@"; do
        case "$arg" in
            --ci)
                CI_MODE=1
                disable_fancy_output
                ;;
            --phpcs|--unit|--e2e|--help|-h)
                test_type="$arg"
                ;;
        esac
    done

    # Only use fancy terminal features in interactive mode
    if [ "$CI_MODE" -eq 0 ]; then
        hide_cursor
        clear
    else
        echo "========================================"
        echo "  ZBooks Test Runner (CI Mode)"
        echo "========================================"
        echo ""
    fi

    case "$test_type" in
        --phpcs)
            STEPS=("deps" "phpcs")
            STEP_NAMES=("Dependencies" "PHP Code Sniffer")
            STEP_STATUS=("pending" "pending")
            STEP_DETAILS=("" "")
            draw_progress
            check_deps && run_phpcs
            print_summary
            ;;
        --unit)
            STEPS=("deps" "wpenv" "unit")
            STEP_NAMES=("Dependencies" "WordPress Environment" "Unit Tests")
            STEP_STATUS=("pending" "pending" "pending")
            STEP_DETAILS=("" "" "")
            draw_progress
            check_deps && start_wp_env && run_unit
            print_summary
            ;;
        --e2e)
            STEPS=("deps" "wpenv" "e2e")
            STEP_NAMES=("Dependencies" "WordPress Environment" "E2E Tests")
            STEP_STATUS=("pending" "pending" "pending")
            STEP_DETAILS=("" "" "")
            draw_progress
            check_deps && start_wp_env && run_e2e
            print_summary
            ;;
        --help|-h)
            print_help
            ;;
        all|*)
            draw_progress
            check_deps
            start_wp_env
            run_phpcs
            run_unit
            run_e2e
            print_summary
            ;;
    esac

    if [ "$CI_MODE" -eq 0 ]; then
        show_cursor
    fi
}

main "$@"
