# Release Process

This document outlines the steps to release a new version of ZBooks for WooCommerce.

## Pre-Release Checklist

### 1. Version Bump

Update version numbers in these files:
- [ ] `zbooks-for-woocommerce.php` - Plugin header `Version:` and `ZBOOKS_VERSION` constant
- [ ] `readme.txt` - `Stable tag:` field
- [ ] `package.json` - `version` field (for npm scripts)

### 2. Changelog Updates

- [ ] Add new version section to `CHANGELOG.md` with date
- [ ] Add new version section to `readme.txt` under `== Changelog ==`
- [ ] Update `== Upgrade Notice ==` in `readme.txt` if needed

### 3. Translation Updates

If there are new/changed strings:
- [ ] Run `wp i18n make-pot . languages/zbooks-for-woocommerce.pot`
- [ ] Update .po files for each language
- [ ] Compile .mo files

### 4. Testing

- [ ] Run PHPCS: `./vendor/bin/phpcs`
- [ ] Run PHPUnit: `./vendor/bin/phpunit`
- [ ] Run E2E tests: `npx playwright test`
- [ ] Or run all: `./run_all_tests.sh`

### 5. WordPress.org Compatibility

- [ ] Verify `Tested up to` matches current WordPress version (6.9)
- [ ] Verify `Requires PHP` is accurate (8.2)
- [ ] Verify `Requires at least` is accurate (6.9)

## Creating a Release

### Option A: GitHub Release (Recommended)

1. **Commit all changes**
   ```bash
   git add -A
   git commit -m "Prepare release v1.0.X"
   git push origin main
   ```

2. **Create GitHub Release**
   - Go to [Releases](https://github.com/talas9/zbooks-for-woocommerce/releases)
   - Click "Draft a new release"
   - Create tag: `v1.0.X`
   - Title: `v1.0.X`
   - Description: Copy from CHANGELOG.md
   - Publish release

3. **Automated Actions**
   - `release.yml` workflow builds the zip automatically
   - `deploy-wporg.yml` deploys to WordPress.org SVN (if configured)

### Option B: Manual Build

1. **Install production dependencies only**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. **Create distribution folder**
   ```bash
   mkdir -p dist/zbooks-for-woocommerce
   ```

3. **Copy required files**
   ```bash
   cp zbooks-for-woocommerce.php LICENSE readme.txt composer.json dist/zbooks-for-woocommerce/
   cp -R src assets languages vendor dist/zbooks-for-woocommerce/
   ```

4. **Create zip**
   ```bash
   cd dist
   zip -rq zbooks-for-woocommerce-1.0.X.zip zbooks-for-woocommerce \
     -x "*.DS_Store" \
     -x "*.git*" \
     -x "*.sh"
   ```

5. **Cleanup**
   ```bash
   rm -rf zbooks-for-woocommerce
   cd ..
   ```

6. **Restore dev dependencies**
   ```bash
   composer install
   ```

## WordPress.org Packaging Rules

### Required Files (MUST be in zip)
- `zbooks-for-woocommerce.php` - Main plugin file
- `readme.txt` - WordPress.org readme
- `composer.json` - Required if vendor/ exists
- `LICENSE` - GPL-2.0+ license

### Forbidden Files (will cause rejection)
- `*.sh` - Shell scripts
- `.DS_Store` - macOS files
- `*.md` - Markdown files (except readme.txt)
- `tests/` - Test files
- `.git/`, `.github/` - Git directories
- `node_modules/` - Node packages
- `phpcs.xml*`, `phpunit.xml*` - Config files
- `composer.lock` - Lock file
- `package*.json` - NPM files

### Requirements
- Zip must have `plugin-slug/` as root folder
- Must use `composer install --no-dev` (no dev dependencies)
- Do NOT use `load_plugin_textdomain()` for WordPress.org hosted plugins

## Post-Release

1. **Verify WordPress.org**
   - Check plugin page updates: https://wordpress.org/plugins/zbooks-for-woocommerce/
   - Verify version shows correctly
   - Test download and installation

2. **GitHub**
   - Verify release assets are attached
   - Close related issues/milestones

3. **Announcements**
   - Update any external documentation
   - Notify users if breaking changes

## Rollback

If issues are discovered post-release:

1. **WordPress.org**
   - Previous versions remain available
   - Users can downgrade via plugin installer

2. **GitHub**
   - Delete the release
   - Remove the tag: `git push --delete origin vX.X.X`
   - Fix issues
   - Create new release with same or incremented version

## Files Reference

| File | Purpose |
|------|---------|
| `.distignore` | Files excluded from WordPress.org package |
| `.github/workflows/release.yml` | Automated release build |
| `.github/workflows/deploy-wporg.yml` | WordPress.org SVN deploy |
| `CHANGELOG.md` | Detailed changelog (GitHub only) |
| `readme.txt` | WordPress.org readme with changelog |
