# Mail System Sendmail/Windows Fix

## Problem Description

When testing SMTP connection in Pagekit admin panel without providing any configuration data, or when using the mail driver on Windows systems with Mailpit, the following error occurred:

```
E-Mail Zustellung fehlgeschlagen! 
(Unsupported sendmail command flags "C:/laragon/bin/mailpit/1.22.3/mailpit.exe sendmail"; 
must be one of "-bs" or "-t" but can include additional flags.)
```

Additionally, when testing SMTP with valid credentials, a 500 Internal Server Error was returned.

## Root Cause

1. **Sendmail Path Issue**: On Windows systems with Laragon/Mailpit, the `ini_get('sendmail_path')` returns the path to Mailpit executable without the required flags (`-bs` or `-t`) that Symfony Mailer's SendmailTransport expects.

2. **Empty Parameter Validation**: The SMTP test action didn't properly validate empty parameters, causing it to attempt connection with null values and falling back to sendmail transport.

## Solution

### 1. Fixed Sendmail Path Processing (`app/system/modules/mail/index.php`)

Added automatic flag detection and appending:
- Checks if sendmail path has required flags (`-bs` or `-t`)
- For Windows/Mailpit systems: Appends `-t` flag
- For Unix-like systems: Appends `-bs` flag

```php
if ($sendMailPath && !preg_match('/\s+-(bs|t)(\s|$)/', $sendMailPath)) {
    if (strpos($sendMailPath, 'mailpit') !== false || stripos(PHP_OS, 'WIN') === 0) {
        $sendMailPath .= ' -t';
    } else {
        $sendMailPath .= ' -bs';
    }
}
```

### 2. Improved SMTP Action Validation (`app/system/modules/mail/src/Controller/MailController.php`)

Added proper validation for SMTP parameters:
- Returns clear error message when host is not provided
- Uses null coalescing operator for optional parameters
- Prevents attempting connection with invalid configuration

```php
if (empty($option['host'])) {
    return ['success' => false, 'message' => __('SMTP host is required for connection testing.')];
}
```

## Testing

### Test Cases Added

1. **Sendmail with Mailpit Path**: Verifies that Mailpit paths get proper flags
2. **Valid Sendmail Paths**: Tests various valid sendmail configurations
3. **Empty SMTP Options**: Ensures proper error handling for empty configuration
4. **Partial SMTP Options**: Tests behavior with incomplete configuration

### Manual Testing

1. **Test empty configuration**:
   - Go to System → Settings → Mail
   - Click "Check Connection" without entering data
   - Should show: "SMTP host is required for connection testing."

2. **Test with SMTP configuration**:
   - Enter valid SMTP credentials
   - Click "Check Connection"
   - Should show: "Connection established!" or specific error

3. **Test email sending** (if using mail driver on Windows):
   - Configure mail driver
   - Send test email
   - Should work without sendmail flag errors

## Affected Systems

- Windows systems with Laragon/XAMPP/WAMP
- Systems using Mailpit for local mail testing
- Any system where sendmail_path doesn't include required flags

## Backward Compatibility

✅ **Fully backward compatible**
- Existing configurations continue to work
- Only adds flags when missing
- Doesn't modify paths that already have valid flags

## Files Changed

1. `app/system/modules/mail/index.php` - Added sendmail path flag processing
2. `app/system/modules/mail/src/Controller/MailController.php` - Added parameter validation
3. `app/system/modules/mail/src/Tests/SendmailTransportTest.php` - Added test coverage

## Recommendations

For local development with Mailpit:
1. Use SMTP driver with Mailpit's SMTP server (usually port 1025)
2. Or ensure mail driver is configured with proper sendmail path

Example Mailpit SMTP configuration:
- Host: localhost
- Port: 1025
- Encryption: none
- Username/Password: (leave empty for Mailpit)