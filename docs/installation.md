# Installation and operation

## Web root and private storage

Serve only `public/`. Never expose the repository root, `storage/`, `.env`, Python scripts or SQLite files through the web server. With Apache, enable `mod_rewrite` and allow the supplied `public/.htaccess`. With Nginx, send non-file requests to `public/index.php` and execute only the front controller through PHP-FPM.

The first-run wizard uses the default `storage/` directory outside the document root. Create it with permissions appropriate for the PHP worker; it holds private configuration, databases, sessions and image processing output. The application also supports `TRIPOVELA_STORAGE` for an already configured installation; configure it consistently for PHP and background jobs. Do not set it before running the default wizard.

Use HTTPS in production. The installer accepts HTTP only for loopback hostnames. The URL must be the site origin, without credentials, query parameters or a path prefix.

## First administrator

Run `php bin/install-token.php` as the PHP worker's OS user. Open the printed path on your site. Never commit, email publicly or screenshot that token. It is stored only as a hash, expires, and is removed after installation. The form requires a username of 3–64 ASCII letters, digits, dots, underscores or hyphens and a password of 12–200 characters. Passwords are hashed by PHP's password API.

The wizard refuses to overwrite existing SQLite databases. If setup fails, inspect the server log and the private storage directory; do not delete an existing database to get past the guard. An operator should resolve the cause before retrying.

## Python and optional tools

Set `TRIPOVELA_PYTHON` to the absolute path of the Python executable that has `requirements.txt` installed. On Windows use the virtual environment's `Scripts/python.exe`; on Linux use `bin/python`. PHP-FPM may clear the inherited environment, so configure the variable in its pool configuration. Import/export tools also use the PHP CLI; full site translation-package generation requires Node.js on PATH.

The default installation has no provider inventory or travel content. Add your own content through management. Importing a package is an explicit operation; passwords and authentication secrets are not part of content exchange.

## Configuration

The wizard writes `storage/site.env`. A root `.env`, when provided for a manual installation, takes precedence. Environment variables `TRIPOVELA_URL`, `TRIPOVELA_ENV` and `TRIPOVELA_STORAGE` override file settings. Keep all these files out of version control.

Set the public contact details and review policies for your own operation before opening registration. Replace `contact@example.com` placeholders. The bundled policy text is application content, not a completed policy for every operator.

No original operator's GA4, Reddit advertising pixel, API credential or affiliate tracking identity is included. Measurement configuration is disabled in `content/measurement.json`. Configure integrations only with your own credentials and consent settings.

## Upgrading an existing site

Do not run the fresh installer over a populated site. Keep runtime storage separate, preserve the current configuration, review code changes and take consistent database snapshots. Copy code files only; never replace existing storage with a checkout or demo fixture.
