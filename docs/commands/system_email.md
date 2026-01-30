# kvs system:email

**[EXPERIMENTAL]** Manage KVS email settings.

## Synopsis

```bash
kvs system:email [<action>] [options]
```

## Description

The `system:email` command manages email configuration for KVS, including SMTP settings, testing email delivery, and viewing email logs.

## Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `action` | No | Action: `show`, `test`, `set`, `log`, `templates` (default: `show`) |

## Options

### General Options

| Option | Description |
|--------|-------------|
| `--format=FORMAT` | Output format: `table`, `json` |
| `--lines=N` | Number of log lines to show (default: 50) |
| `--force` | Skip experimental feature confirmation |

### SMTP Configuration

| Option | Description |
|--------|-------------|
| `--smtp-host=HOST` | SMTP server hostname |
| `--smtp-port=PORT` | SMTP server port |
| `--smtp-user=USER` | SMTP username |
| `--smtp-pass=PASS` | SMTP password |
| `--smtp-security=TYPE` | Security type (`tls`, `ssl`) |
| `--smtp-timeout=SEC` | Connection timeout in seconds |

### From Address

| Option | Description |
|--------|-------------|
| `--from-email=EMAIL` | From email address |
| `--from-name=NAME` | From display name |

### Mailer Configuration

| Option | Description |
|--------|-------------|
| `--mailer=TYPE` | Mailer type (`php`, `smtp`, `custom`) |
| `--debug=LEVEL` | Debug level (0=none, 1=basic, 2=extended) |

### Test Email Options

| Option | Description |
|--------|-------------|
| `--to=EMAIL` | Test email recipient |
| `--subject=TEXT` | Test email subject |
| `--body=TEXT` | Test email body |

## Actions

### show

Display current email settings.

```bash
kvs email show
kvs email show --format=json
```

### test

Send a test email to verify configuration.

```bash
kvs email test --to=test@example.com
kvs email test --to=test@example.com --subject="Test" --body="Hello"
```

### set

Update email settings.

```bash
# Set mailer type
kvs email set --mailer=smtp

# Configure SMTP server
kvs email set --smtp-host=smtp.gmail.com --smtp-port=587

# Set from address
kvs email set --from-email=noreply@example.com --from-name="My Site"

# Enable debug logging
kvs email set --debug=1
```

### log

View email sending log (requires debug mode enabled).

```bash
kvs email log
kvs email log --lines=100
```

### templates

List available email templates.

```bash
kvs email templates
```

## Mailer Types

| Type | Description | Use Case |
|------|-------------|----------|
| `php` | PHP mail() function | Simple hosting, no SMTP |
| `smtp` | SMTP server | Full control, recommended |
| `custom` | Custom mail script | Advanced customization |

## SMTP Security

| Type | Description | Port |
|------|-------------|------|
| `tls` | TLS encryption (recommended) | 587 |
| `ssl` | SSL encryption | 465 |

## Debug Levels

| Level | Description | Output |
|-------|-------------|--------|
| 0 | None (default) | No logging |
| 1 | Basic logging | Sent emails, errors |
| 2 | Extended logging | Full SMTP conversation |

## Examples

### View Current Settings

```bash
# Table format
kvs email show

# JSON format
kvs email show --format=json
```

### Configure SMTP

```bash
# Gmail example
kvs email set \
  --mailer=smtp \
  --smtp-host=smtp.gmail.com \
  --smtp-port=587 \
  --smtp-user=user@gmail.com \
  --smtp-pass="app-password" \
  --smtp-security=tls \
  --from-email=noreply@mysite.com

# Generic SMTP
kvs email set \
  --smtp-host=mail.example.com \
  --smtp-port=587 \
  --smtp-security=tls
```

### Test Email Delivery

```bash
# Basic test
kvs email test --to=admin@example.com

# Custom message
kvs email test \
  --to=test@example.com \
  --subject="KVS Test Email" \
  --body="This is a test email from KVS CLI"
```

### Enable Debugging

```bash
# Enable basic logging
kvs email set --debug=1

# View log
kvs email log

# View more lines
kvs email log --lines=200

# Disable logging
kvs email set --debug=0
```

### Configure From Address

```bash
kvs email set \
  --from-email=noreply@mysite.com \
  --from-name="My Video Site"
```

## Common SMTP Providers

### Gmail

```bash
kvs email set \
  --smtp-host=smtp.gmail.com \
  --smtp-port=587 \
  --smtp-security=tls \
  --smtp-user=user@gmail.com \
  --smtp-pass="app-password"
```

**Note:** Use [App Passwords](https://support.google.com/accounts/answer/185833), not your regular password.

### Outlook/Office 365

```bash
kvs email set \
  --smtp-host=smtp.office365.com \
  --smtp-port=587 \
  --smtp-security=tls \
  --smtp-user=user@outlook.com
```

### SendGrid

```bash
kvs email set \
  --smtp-host=smtp.sendgrid.net \
  --smtp-port=587 \
  --smtp-security=tls \
  --smtp-user=apikey \
  --smtp-pass="your-api-key"
```

### Mailgun

```bash
kvs email set \
  --smtp-host=smtp.mailgun.org \
  --smtp-port=587 \
  --smtp-security=tls
```

## Sample Output

### show

```
Email Settings
==============

Mailer
------
 Type             SMTP
 Debug Level      0 (None)

SMTP Configuration
------------------
 Host             smtp.gmail.com
 Port             587
 Security         TLS
 Username         user@gmail.com
 Timeout          30 seconds

From Address
------------
 Email            noreply@mysite.com
 Name             My Video Site
```

## Aliases

- `kvs email`

## Notes

- This command is **EXPERIMENTAL** - requires confirmation or `--force` flag
- Use `test` action to verify settings before enabling
- Debug level 2 logs passwords - use carefully
- Gmail requires "App Passwords" with 2FA enabled
- Always use TLS for security

## Troubleshooting

### Test Email Fails

```bash
# Enable debug logging
kvs email set --debug=2

# Send test email
kvs email test --to=test@example.com

# View detailed log
kvs email log --lines=100
```

### SMTP Connection Issues

- Verify hostname and port
- Check firewall rules (port 587 or 465)
- Confirm username/password
- Try different security type (TLS vs SSL)

## See Also

- [`system:check`](system_check.md) - System health checks
- [`system:status`](system_status.md) - Overall system status
