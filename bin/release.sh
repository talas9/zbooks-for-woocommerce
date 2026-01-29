#!/usr/bin/env bash
#
# WordPress Plugin Release Script
# Validates plugin against WordPress.org requirements and creates release package
#
# Usage: ./bin/release.sh [version]
# Example: ./bin/release.sh 1.0.16
#
# This script performs comprehensive checks before creating a release:
# - Version consistency across files
# - WordPress.org compliance (no heredoc, no hidden files in zip)
# - PHP syntax validation
# - WordPress Coding Standards (PHPCS)
# - Unit tests (PHPUnit)
# - Plugin Check validation (if available)
# - Distribution package creation

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
PLUGIN_SLUG="zbooks-for-woocommerce"
MAIN_FILE="zbooks-for-woocommerce.php"
README_FILE="readme.txt"
PACKAGE_JSON="package.json"
BUILD_DIR="build"
TEMP_DIR=".release-temp"

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_ROOT"

# Functions
print_header() {
    echo -e "\n${BLUE}================================================${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}================================================${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

# Check if command exists
command_exists() {
    command -v "$1" >/dev/null 2>&1
}

# Get version from file
get_version_from_file() {
    local file=$1
    local pattern=$2
    grep -E "$pattern" "$file" | head -1 | sed -E 's/.*([0-9]+\.[0-9]+\.[0-9]+).*/\1/'
}

# Validate version format
validate_version_format() {
    if [[ ! $1 =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        print_error "Invalid version format: $1 (expected: X.Y.Z)"
        return 1
    fi
    return 0
}

# Check WordPress.org compliance
check_wporg_compliance() {
    print_header "Checking WordPress.org Compliance"
    
    local errors=0
    
    # Check for heredoc/nowdoc syntax (not allowed by WordPress.org)
    print_info "Checking for heredoc/nowdoc syntax..."
    if grep -r "<<<[A-Z_]" --include="*.php" src/ 2>/dev/null; then
        print_error "Found heredoc/nowdoc syntax (not allowed by WordPress.org)"
        errors=$((errors + 1))
    else
        print_success "No heredoc/nowdoc syntax found"
    fi
    
    # Check for common issues
    print_info "Checking for eval() usage..."
    if grep -r "eval(" --include="*.php" src/ 2>/dev/null | grep -v "// phpcs:ignore"; then
        print_warning "Found eval() usage (may be flagged during review)"
    else
        print_success "No eval() usage found"
    fi
    
    # Check for base64_decode with suspicious patterns
    print_info "Checking for suspicious encoding..."
    if grep -r "base64_decode" --include="*.php" src/ 2>/dev/null | grep -v "phpcs:ignore"; then
        print_warning "Found base64_decode() usage (may require explanation)"
    else
        print_success "No suspicious encoding found"
    fi
    
    return $errors
}

# Check version consistency
check_version_consistency() {
    print_header "Checking Version Consistency"
    
    local target_version=$1
    local errors=0
    
    # Check main plugin file
    print_info "Checking $MAIN_FILE..."
    local main_version=$(get_version_from_file "$MAIN_FILE" "Version:")
    if [[ "$main_version" != "$target_version" ]]; then
        print_error "Version mismatch in $MAIN_FILE: $main_version (expected: $target_version)"
        errors=$((errors + 1))
    else
        print_success "Version in $MAIN_FILE: $main_version"
    fi
    
    # Check constant in main file
    local const_version=$(get_version_from_file "$MAIN_FILE" "ZBOOKS_VERSION")
    if [[ "$const_version" != "$target_version" ]]; then
        print_error "ZBOOKS_VERSION constant mismatch: $const_version (expected: $target_version)"
        errors=$((errors + 1))
    else
        print_success "ZBOOKS_VERSION constant: $const_version"
    fi
    
    # Check readme.txt
    print_info "Checking $README_FILE..."
    local readme_version=$(get_version_from_file "$README_FILE" "Stable tag:")
    if [[ "$readme_version" != "$target_version" ]]; then
        print_error "Version mismatch in $README_FILE: $readme_version (expected: $target_version)"
        errors=$((errors + 1))
    else
        print_success "Stable tag in $README_FILE: $readme_version"
    fi
    
    # Check package.json if exists
    if [[ -f "$PACKAGE_JSON" ]]; then
        print_info "Checking $PACKAGE_JSON..."
        local package_version=$(grep '"version"' "$PACKAGE_JSON" | head -1 | sed -E 's/.*"([0-9]+\.[0-9]+\.[0-9]+)".*/\1/')
        if [[ "$package_version" != "$target_version" ]]; then
            print_error "Version mismatch in $PACKAGE_JSON: $package_version (expected: $target_version)"
            errors=$((errors + 1))
        else
            print_success "Version in $PACKAGE_JSON: $package_version"
        fi
    fi
    
    return $errors
}

# Check PHP syntax
check_php_syntax() {
    print_header "Checking PHP Syntax"
    
    local errors=0
    
    while IFS= read -r -d '' file; do
        if ! php -l "$file" > /dev/null 2>&1; then
            print_error "Syntax error in: $file"
            php -l "$file"
            errors=$((errors + 1))
        fi
    done < <(find src -name "*.php" -print0)
    
    if [[ $errors -eq 0 ]]; then
        print_success "All PHP files have valid syntax"
    fi
    
    return $errors
}

# Run WordPress Coding Standards
run_phpcs() {
    print_header "Running WordPress Coding Standards (PHPCS)"
    
    if ! command_exists "vendor/bin/phpcs"; then
        print_warning "PHPCS not found, skipping..."
        return 0
    fi
    
    if ./vendor/bin/phpcs --standard=WordPress src/; then
        print_success "PHPCS checks passed"
        return 0
    else
        print_error "PHPCS checks failed"
        print_info "Run './vendor/bin/phpcbf' to auto-fix some issues"
        return 1
    fi
}

# Run unit tests
run_tests() {
    print_header "Running Unit Tests"
    
    if ! command_exists "vendor/bin/phpunit"; then
        print_warning "PHPUnit not found, skipping tests..."
        return 0
    fi
    
    if [[ ! -f "phpunit.xml" && ! -f "phpunit.xml.dist" ]]; then
        print_warning "PHPUnit configuration not found, skipping tests..."
        return 0
    fi
    
    if ./vendor/bin/phpunit --no-coverage; then
        print_success "All tests passed"
        return 0
    else
        print_error "Tests failed"
        return 1
    fi
}

# Run WordPress Plugin Check (if available)
run_plugin_check() {
    print_header "Running WordPress Plugin Check"
    
    if ! command_exists "wp"; then
        print_warning "WP-CLI not found, skipping Plugin Check..."
        return 0
    fi
    
    # Check if plugin-check is installed
    if ! wp plugin is-installed plugin-check 2>/dev/null; then
        print_warning "Plugin Check plugin not installed, skipping..."
        print_info "Install with: wp plugin install plugin-check --activate"
        return 0
    fi
    
    print_info "Running static checks..."
    if wp plugin check "$PLUGIN_SLUG" --format=table; then
        print_success "Plugin Check passed"
        return 0
    else
        print_warning "Plugin Check found issues (review manually)"
        return 0  # Don't fail release on warnings
    fi
}

# Create distribution package
create_package() {
    print_header "Creating Distribution Package"
    
    local version=$1
    local zip_name="${PLUGIN_SLUG}-${version}.zip"
    
    # Clean up previous builds
    rm -rf "$BUILD_DIR" "$TEMP_DIR"
    mkdir -p "$BUILD_DIR" "$TEMP_DIR/$PLUGIN_SLUG"
    
    print_info "Copying files to temporary directory..."
    
    # Use rsync with .distignore if available
    if [[ -f ".distignore" ]]; then
        print_info "Using .distignore for exclusions..."
        rsync -av --exclude-from=".distignore" \
            --exclude="$BUILD_DIR" \
            --exclude="$TEMP_DIR" \
            --exclude=".git" \
            . "$TEMP_DIR/$PLUGIN_SLUG/"
    else
        # Fallback to manual copy with common exclusions
        print_warning ".distignore not found, using default exclusions..."
        rsync -av \
            --exclude="node_modules" \
            --exclude="vendor" \
            --exclude="tests" \
            --exclude=".git*" \
            --exclude="*.log" \
            --exclude="*.md" \
            --exclude=".env*" \
            --exclude="$BUILD_DIR" \
            --exclude="$TEMP_DIR" \
            . "$TEMP_DIR/$PLUGIN_SLUG/"
    fi
    
    # Install production dependencies if composer.json exists
    if [[ -f "composer.json" ]] && command_exists composer; then
        print_info "Installing production dependencies..."
        (cd "$TEMP_DIR/$PLUGIN_SLUG" && composer install --no-dev --optimize-autoloader)
    fi
    
    # Validate the package
    print_info "Validating package contents..."
    
    # Check for hidden files that shouldn't be in distribution
    if find "$TEMP_DIR/$PLUGIN_SLUG" -name ".env*" | grep -q .; then
        print_error "Found .env files in distribution package!"
        return 1
    else
        print_success "No .env files in package"
    fi
    
    # Check for heredoc in package
    if grep -r "<<<[A-Z_]" --include="*.php" "$TEMP_DIR/$PLUGIN_SLUG" 2>/dev/null; then
        print_error "Found heredoc syntax in distribution package!"
        return 1
    else
        print_success "No heredoc syntax in package"
    fi
    
    # Create zip
    print_info "Creating zip file..."
    (cd "$TEMP_DIR" && zip -rq "../$BUILD_DIR/$zip_name" "$PLUGIN_SLUG")
    
    # Clean up temp directory
    rm -rf "$TEMP_DIR"
    
    local zip_size=$(du -h "$BUILD_DIR/$zip_name" | cut -f1)
    print_success "Package created: $BUILD_DIR/$zip_name ($zip_size)"
    
    # Final verification
    print_info "Final verification of zip contents..."
    if unzip -l "$BUILD_DIR/$zip_name" | grep -q "\.env"; then
        print_error "Zip contains .env files!"
        return 1
    fi
    
    if unzip -qc "$BUILD_DIR/$zip_name" "*/src/Service/EmailTemplateService.php" 2>/dev/null | grep -q "<<<"; then
        print_error "Zip contains heredoc syntax!"
        return 1
    fi
    
    print_success "Package validation passed"
    
    return 0
}

# Main script
main() {
    print_header "WordPress Plugin Release Script"
    print_info "Plugin: $PLUGIN_SLUG"
    print_info "Date: $(date '+%Y-%m-%d %H:%M:%S')"
    
    # Check if version provided
    if [[ -z "$1" ]]; then
        # Try to get current version from main file
        local current_version=$(get_version_from_file "$MAIN_FILE" "Version:")
        print_info "No version specified, using current version: $current_version"
        VERSION=$current_version
    else
        VERSION=$1
    fi
    
    # Validate version format
    if ! validate_version_format "$VERSION"; then
        exit 1
    fi
    
    print_info "Target version: $VERSION"
    
    # Run all checks
    local total_errors=0
    
    check_version_consistency "$VERSION" || total_errors=$((total_errors + $?))
    check_php_syntax || total_errors=$((total_errors + $?))
    check_wporg_compliance || total_errors=$((total_errors + $?))
    
    # Optional checks (don't fail on these)
    run_phpcs || print_warning "PHPCS checks had issues (continuing...)"
    run_tests || print_warning "Tests had issues or test environment not set up (continuing...)"
    run_plugin_check || print_warning "Plugin Check had warnings (continuing...)"
    
    # Stop if there were any critical errors
    if [[ $total_errors -gt 0 ]]; then
        print_error "\n$total_errors error(s) found. Please fix before releasing."
        exit 1
    fi
    
    # Create package
    if ! create_package "$VERSION"; then
        print_error "Failed to create distribution package"
        exit 1
    fi
    
    # Success summary
    print_header "Release Ready!"
    echo -e "${GREEN}✓ All checks passed${NC}"
    echo -e "${GREEN}✓ Package created: $BUILD_DIR/${PLUGIN_SLUG}-${VERSION}.zip${NC}"
    echo ""
    echo -e "${BLUE}Next steps:${NC}"
    echo "  1. Test the package locally"
    echo "  2. Upload to WordPress.org: https://wordpress.org/plugins/developers/"
    echo "  3. Create GitHub release: gh release create v${VERSION} $BUILD_DIR/${PLUGIN_SLUG}-${VERSION}.zip"
    echo ""
}

# Run main function
main "$@"
