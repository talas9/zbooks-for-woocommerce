# Release Script

Automated WordPress plugin release validation and packaging script.

## Usage

```bash
./bin/release.sh [version]
```

If no version is specified, uses the current version from the main plugin file.

### Examples

```bash
# Use current version
./bin/release.sh

# Specify version
./bin/release.sh 1.0.16
```

## What it Does

The script performs comprehensive checks before creating a release package:

### 1. Version Consistency Check ✓
- Validates version format (X.Y.Z)
- Checks version consistency across:
  - Main plugin file header
  - Plugin version constant  
  - readme.txt stable tag
  - package.json version

### 2. PHP Syntax Validation ✓
- Checks all PHP files for syntax errors
- Ensures code will run without parse errors

### 3. WordPress.org Compliance ✓
- **Heredoc/Nowdoc Check**: Ensures no `<<<` syntax (not allowed by WordPress.org)
- **eval() Check**: Warns about eval() usage
- **Encoding Check**: Warns about suspicious base64_decode() usage
- **Hidden Files Check**: Validates no .env files in distribution

### 4. WordPress Coding Standards (Optional)
- Runs PHPCS with WordPress standards
- Shows fixable issues
- Continues even if there are style warnings

### 5. Unit Tests (Optional)
- Runs PHPUnit tests if configured
- Skips gracefully if test environment not set up

### 6. Plugin Check (Optional)  
- Runs WordPress Plugin Check if WP-CLI available
- Validates against WordPress.org requirements

### 7. Package Creation ✓
- Creates production-ready zip file
- Excludes files per `.distignore`
- Installs production dependencies only
- Validates final package contents
- Performs final heredoc and .env checks

## Requirements

### Required
- Bash (works on Mac and Linux)
- PHP CLI
- composer (for dependency installation)

### Optional
- PHPCS (WordPress Coding Standards)
- PHPUnit (unit testing)
- WP-CLI + Plugin Check plugin

## Output

Creates a distribution-ready zip file:
```
build/zbooks-for-woocommerce-{version}.zip
```

This file is ready to upload to WordPress.org!

## Exit Codes

- `0`: Success - all critical checks passed
- `1`: Failure - critical checks failed

Optional checks (PHPCS, tests) won't fail the build, only show warnings.

## WordPress.org Submission

After running the script successfully:

1. Test the package locally
2. Upload to: https://wordpress.org/plugins/developers/
3. Or create GitHub release:
   ```bash
   gh release create v1.0.16 build/zbooks-for-woocommerce-1.0.16.zip
   ```

## Troubleshooting

### "PHPCS not found"
Install development dependencies:
```bash
composer install
```

### "PHPUnit not found" or "WordPress test suite not found"
This is normal if you haven't set up the test environment. The script will continue.

### "PHPCS checks failed"
Auto-fix style issues:
```bash
./vendor/bin/phpcbf
```

Then run the release script again.

## Configuration

The script uses:
- `.distignore` - Files to exclude from distribution
- `phpcs.xml.dist` - Coding standards configuration
- `phpunit.xml.dist` - Test configuration

## Checks Performed

| Check | Critical | Skips If Missing |
|-------|----------|------------------|
| Version consistency | ✓ | ✗ |
| PHP syntax | ✓ | ✗ |
| WordPress.org compliance | ✓ | ✗ |
| No heredoc syntax | ✓ | ✗ |
| No .env files in package | ✓ | ✗ |
| PHPCS | ✗ | ✓ |
| Unit tests | ✗ | ✓ |
| Plugin Check | ✗ | ✓ |

## Based On

This script incorporates best practices from:
- [deliciousbrains/wp-plugin-build](https://github.com/deliciousbrains/wp-plugin-build)
- [WordPress Plugin Check](https://wordpress.org/plugins/plugin-check/)
- WordPress.org Plugin Guidelines
