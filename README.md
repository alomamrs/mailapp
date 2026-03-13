# Mail — PHP Version (cPanel Ready)

A PHP web app for reading Microsoft 365 emails. No Node.js needed — works natively on any cPanel host with PHP 7.4+.

## File Structure

```
mail-php/
├── index.php         ← Main entry point & UI
├── config.php        ← Credentials & session setup
├── auth/
│   └── Auth.php      ← Device code flow & token management
└── api/
    ├── api.php       ← AJAX API router
    └── Graph.php     ← Microsoft Graph API calls
```

---

## Step 1 — Set Your Azure Credentials

Open `config.php` and paste your credentials:

```php
define('CLIENT_ID', 'your-client-id');  // Application (client) ID
define('TENANT_ID', 'common');          // 'common' or your Directory ID
```

---

## Step 2 — Azure App Requirements

Make sure your Azure App Registration has:

1. **Authentication → Allow public client flows → Yes**
2. **API Permissions (Delegated):**
   - `User.Read`
   - `Mail.Read`
   - `offline_access`
   - `openid`, `profile`
3. Admin consent granted

---

## Step 3 — Upload to cPanel

1. Zip the `mail-php` folder
2. Open **cPanel → File Manager**
3. Navigate to `public_html` (or a subdirectory)
4. Upload and extract the zip
5. Visit `https://yourdomain.com/mail-php/` in your browser

That's it — no Node.js, no npm, no setup steps in cPanel.

---

## PHP Requirements

- PHP 7.4 or higher (PHP 8.x recommended)
- `curl` extension enabled (on by default on most hosts)
- `session` support (on by default)

---

## How It Works

1. You visit the app — if not signed in, a device code login screen appears
2. Copy the code, click the Microsoft link, enter the code and sign in
3. The page automatically detects sign-in and loads your emails
4. Sessions keep you logged in for 24 hours
5. Access tokens are refreshed automatically in the background

---

## Security Note

This app is designed for single-user or trusted-user access.
Sessions are stored server-side via PHP's default session handler.
For production multi-user use, consider adding authentication middleware.
