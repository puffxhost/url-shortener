# Puffx URL Shortener

A lightweight PHP and MySQL link shortener. Create a random short link or choose a custom code, set an optional expiry, copy/share the result, and see recent links and click counts in the same browser session.

## Features

- HTTP/HTTPS destination validation; embedded URL credentials and unsupported schemes are rejected.
- Cryptographically random 8-character codes and optional custom codes (1–32 letters, numbers, `-`, or `_`).
- Optional expiration: 1, 7, 30, or 90 days; links without expiry remain active.
- Temporary HTTP 302 redirects so destination and expiry checks run on every visit.
- Click counter and the latest 10 links created from the same browser session.
- Browser-session ownership tokens are stored as SHA-256 hashes; other visitors sharing a public IP address do not see your recent links.
- CSRF protection, output escaping, prepared SQL statements, session-only creation throttling, and baseline HTTP security headers.
- Copy and native share buttons; no third-party JavaScript or QR service is required.

> This is a small self-hosted tool, not a full multi-user SaaS. Browser history is session-bound, not a login system. Add TLS, backups, monitoring, and stronger abuse controls before operating it as a public high-traffic service.

## Requirements

- PHP 7.4+ with the `mysqli` extension and PHP sessions enabled
- MySQL 5.7+ or MariaDB 10.3+
- Apache with `mod_rewrite` and `AllowOverride FileInfo Options` enabled for clean `/{code}` links
- HTTPS for production; an HTTPS public base URL is required in production

## Install locally

### 1. Get the code

```bash
git clone https://github.com/puffxhost/url-shortener.git
cd url-shortener
```

### 2. Create a database

Create an empty database and a MySQL user with permission to create/read/update rows in it. Import the schema:

```bash
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < IMPORT-DB.sql
```

You can also create the database and import `IMPORT-DB.sql` with phpMyAdmin. Keep database credentials out of Git.

### 3. Configure environment variables

Set these variables in your PHP hosting environment or web-server/PHP-FPM configuration:

| Variable | Example | Description |
|---|---|---|
| `DB_HOST` | `localhost` | MySQL host |
| `DB_USER` | `urlshortener_user` | MySQL user |
| `DB_PASSWORD` | set privately | MySQL password; never commit it |
| `DB_NAME` | `urlshortener` | Database name |
| `APP_BASE_URL` | `https://links.example.com` | Public URL where this app is installed, without a trailing slash |

For local-only testing, `APP_BASE_URL=http://localhost` is acceptable. For a production site, use the exact HTTPS origin or subdirectory where the app is deployed. Do not derive this value from the incoming `Host` header.

### 4. Upload and configure Apache

Upload the repository files to the document root or a dedicated subdirectory. Keep `.htaccess` beside `index.php`. Confirm that PHP can read the environment variables and that Apache allows the rewrite rules. The public web server must not expose SQL or configuration files as plain text.

Open the configured `APP_BASE_URL` in a browser. If PHP reports that configuration is missing, set the required environment variables in the PHP-FPM/cPanel application settings, then restart or reload the application as required by the host.

## cPanel deployment

1. Create a MySQL database and database user in **cPanel → MySQL Database Wizard**; grant the required privileges.
2. Import `IMPORT-DB.sql` using phpMyAdmin.
3. Upload the app to `public_html/links` (or another document-root folder).
4. Configure `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, and `APP_BASE_URL` in the host's PHP/application environment settings. If the plan does not offer protected environment variables, ask the hosting provider for a secure configuration method; do not place secrets in a publicly downloadable file.
5. Ensure Apache `mod_rewrite` is enabled and `.htaccess` overrides are allowed.
6. Enable HTTPS and set `APP_BASE_URL` to the HTTPS address.
7. Open the app, create a test link, then verify that the short link redirects and its click count increases.

## Upgrade an existing installation

1. Take a database backup and keep a copy of your current files.
2. Upload the updated application files.
3. Run `MIGRATE-DB.sql` once in the existing database. It widens the short-code column and adds the session-owner hash/index needed for private browser history.
4. Set the required environment variables and verify that `APP_BASE_URL` matches the deployed HTTPS address.
5. Check an existing short link and create a new one.

The migration leaves old links, click counts, and any legacy IP values in place. This version no longer writes or displays IP addresses. Existing rows have no owner token, so they will still redirect but will not appear in the session-specific recent-links view. The migration makes short codes case-sensitive. Do not run the migration more than once.

## How links and ownership work

1. The form validates the URL, custom code, expiry selection, and per-session creation limit.
2. PHP creates a random code or inserts the chosen custom code using a prepared statement.
3. The destination, expiry, click count, and a hash of a random browser-session token are saved in MySQL.
4. The public short path is routed by Apache to `redirect.php`; the handler checks expiry, increments clicks, and returns a temporary 302 redirect.
5. The recent-links list filters by the browser-session token hash. Clearing browser cookies or switching browsers loses access to that list; the public links remain usable until they expire.

## Security and operation notes

- Use HTTPS, strong unique database credentials, and regular database backups.
- Keep the database user limited to this app's database.
- The per-session limit reduces accidental rapid submissions but is not a robust public abuse-prevention system. For public high-volume use, add server-side IP/network limits, bot protection, moderation/reporting, and monitoring at the reverse proxy.
- Short links redirect to the destination exactly as submitted after HTTP/HTTPS validation. A shortener can be abused to disguise harmful destinations; publish a clear acceptable-use policy before opening it to the public.
- Click counts measure redirect requests, not unique people. Link scanners and previews can also increment the count.
- Do not log full destination URLs if they may contain private query parameters.
- Session history is not account authentication and should not be treated as private account storage.

## Troubleshooting

| Problem | What to check |
|---|---|
| “not configured yet” page | All five environment variables are set in the PHP process environment; reload PHP-FPM/cPanel app after changes. |
| Database unavailable | Host/name/user/password, user privileges, MySQL service, and `mysqli` extension. |
| Short path returns 404 | Apache `mod_rewrite`, `.htaccess` placement, and `AllowOverride` settings. |
| Links point to the wrong host | Set `APP_BASE_URL` to the exact public HTTPS origin; the app intentionally does not trust the request Host header. |
| Custom code already used | Choose another code; codes are unique and case-sensitive in the database collation. |
| Link says expired | Expiry is enforced by the server; ask the sender to create a replacement. |

## Project files

```text
url-shortener/
├── .htaccess       Apache clean-link rewrite rules
├── config.php      Environment loading, session, DB, and security headers
├── index.php       Form, validation, link creation, and browser-session history
├── redirect.php    Expiry checks, click counting, and HTTP 302 redirects
├── IMPORT-DB.sql   Fresh database schema
├── MIGRATE-DB.sql  One-time migration for existing databases
└── assets/style.css Responsive app stylesheet
```

## Support & Contact

- Website: [puffxhost.com/contact](https://puffxhost.com/contact)
- Email: [support@puffxhost.in](mailto:support@puffxhost.in)
- Call: [+91 8602967573](tel:+918602967573)
- WhatsApp: [+91 7354336624](https://wa.me/917354336624)

## License

This project is licensed under the [MIT License](LICENSE).

## Contributing

Contributions are welcome. Please open an issue to discuss substantial changes, then submit a pull request.
