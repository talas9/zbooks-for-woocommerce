# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |

## Reporting a Vulnerability

We take security seriously. If you discover a security vulnerability, please report it responsibly.

### How to Report

1. **Do NOT** open a public GitHub issue for security vulnerabilities
2. Email the maintainer directly or use [GitHub's private vulnerability reporting](https://github.com/talas9/zbooks-for-woocommerce/security/advisories/new)
3. Include:
   - Description of the vulnerability
   - Steps to reproduce
   - Potential impact
   - Suggested fix (if any)

### What to Expect

- **Acknowledgment**: Within 48 hours
- **Initial Assessment**: Within 7 days
- **Resolution Timeline**: Depends on severity, typically 30-90 days

### Disclosure Policy

- We follow coordinated disclosure
- Security fixes are released as patch versions
- Credit is given to reporters (unless anonymity is requested)

## Security Best Practices

When using this plugin:

### API Credentials

- Store Zoho API credentials securely in WordPress options (encrypted at rest)
- Never commit credentials to version control
- Use environment-specific credentials for development/staging/production

### Access Control

- Only administrators can access plugin settings
- Sync operations require appropriate WooCommerce capabilities
- API tokens are stored with WordPress nonce protection

### Data Handling

- Customer data is transmitted directly to Zoho Books via HTTPS
- No data is stored on third-party servers
- Logs may contain order IDs but not sensitive customer data

## Known Security Considerations

- The plugin requires `ZohoBooks.fullaccess.all` API scope
- Refresh tokens are stored in the WordPress database
- Rate limiting is enforced to prevent API abuse
