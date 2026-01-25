# Contributing to ZBooks for WooCommerce

Thank you for your interest in contributing! This document provides guidelines and instructions for contributing.

## Code of Conduct

By participating in this project, you agree to maintain a respectful and inclusive environment for everyone.

## How to Contribute

### Reporting Bugs

1. Check [existing issues](https://github.com/talas9/zbooks-for-woocommerce/issues) to avoid duplicates
2. Use the [bug report template](https://github.com/talas9/zbooks-for-woocommerce/issues/new?template=bug_report.yml)
3. Include:
   - Plugin, WordPress, WooCommerce, and PHP versions
   - Steps to reproduce
   - Expected vs actual behavior
   - Error logs if available

### Suggesting Features

1. Use the [feature request template](https://github.com/talas9/zbooks-for-woocommerce/issues/new?template=feature_request.yml)
2. Describe the problem you're trying to solve
3. Explain your proposed solution

### Pull Requests

1. Fork the repository
2. Create a feature branch from `main`:
   ```bash
   git checkout -b feature/your-feature-name
   ```
3. Make your changes
4. Run tests and linting:
   ```bash
   composer phpcs
   composer test
   npm run test:e2e
   ```
5. Commit with clear messages
6. Push and create a pull request

## Development Setup

### Requirements

- PHP 8.2+
- Node.js 18+
- Composer
- Docker (for wp-env)

### Local Environment

```bash
# Clone your fork
git clone https://github.com/YOUR-USERNAME/zbooks-for-woocommerce.git
cd zbooks-for-woocommerce

# Install dependencies
composer install
npm install

# Start local WordPress
npm run env:start

# Access at http://localhost:8888
# Admin: admin / password
```

### Running Tests

```bash
# PHP coding standards
composer phpcs

# Auto-fix coding standards
composer phpcbf

# Unit tests
composer test

# E2E tests (requires running environment)
npm run test:e2e
```

## Coding Standards

This project follows [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/):

- Use tabs for indentation in PHP
- Follow WordPress naming conventions
- Use the `zbooks` prefix for functions, hooks, and globals
- Text domain: `zbooks-for-woocommerce`

### File Organization

```
src/
├── Admin/          # Admin pages and settings
├── Api/            # Zoho API client
├── Service/        # Business logic
├── Hooks/          # WooCommerce integration
├── Model/          # Data models
├── Repository/     # Data persistence
└── Cron/           # Scheduled tasks
```

## Commit Messages

Write clear, concise commit messages:

- Use present tense ("Add feature" not "Added feature")
- Keep the first line under 72 characters
- Reference issues when applicable (#123)

Examples:
```
Add reconciliation date range filter

Fix payment sync for partial refunds (#45)

Update Zoho API client for rate limiting
```

## Questions?

- Open a [discussion](https://github.com/talas9/zbooks-for-woocommerce/discussions)
- Check the [documentation](https://talas9.github.io/zbooks-for-woocommerce/)
