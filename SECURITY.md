# Security Policy

## Reporting a Vulnerability

The security of Laravel Log Shipper is taken seriously. If you discover a security vulnerability, please report it responsibly.

**Please do NOT report security vulnerabilities through public GitHub issues.**

Instead, please send an email to [support@admin-intelligence.de](mailto:support@admin-intelligence.de) with:

- A description of the vulnerability
- Steps to reproduce the issue
- Potential impact of the vulnerability
- Any suggested fixes (if applicable)

## Response Timeline

- **Initial Response**: Within 12 hours
- **Status Update**: Within 3 business days
- **Resolution Target**: Within 7 days (depending on complexity)

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |

## Security Best Practices

When using this package, please ensure:

1. **Keep your `LOG_SHIPPER_KEY` secret** - Never commit it to version control
2. **Use HTTPS endpoints** - Always configure `LOG_SHIPPER_ENDPOINT` with HTTPS
3. **Review sanitization rules** - Ensure sensitive fields specific to your application are added to `sanitize_fields`
4. **Monitor log output** - Regularly audit what data is being shipped to catch any unintended sensitive data exposure

## Acknowledgments

We appreciate the security research community's efforts in helping keep this package secure. Contributors who report valid security issues will be acknowledged (with permission) in our release notes.
