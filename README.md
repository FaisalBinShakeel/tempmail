# TempMail

A disposable email service. A visitor lands on the site, gets a random address
on your domain immediately, and reads incoming mail in a live-updating inbox.
No signup, no login, no framework, no build step.

- PHP 8.1+ / MySQL 8 (or MariaDB 10.6+) / nginx + PHP-FPM
- Vanilla JS and one CSS file — the page is server-rendered and works with
  JavaScript disabled
- Mail arrives through a Postfix pipe into `receive.php`

## How it works

```
Postfix ──pipe──► receive.php ──► emails table
                                      ▲
Browser ──poll every 5s──► api/messages.php ──┘
```

An inbox is owned by a 64-character token stored in the `tempmail_token`
cookie. Every API call resolves the inbox from that cookie; an address or
message id sent by the client is never used to decide what it may read.

## Requirements

| Component | Notes |
|---|---|
| PHP 8.1+ (FPM + CLI) | extensions: `pdo_mysql`, `mbstring`, `json`, `session` |
| `ext-mailparse` | optional but recommended — `receive.php` uses php-mime-mail-parser when it is present, and a smaller built-in parser when it is not |
| MySQL 8 / MariaDB 10.6+ | |
| nginx | config in `deploy/nginx.conf` |
| Composer | for the MIME parser |
| Postfix | already accepting mail for your domain |

## Install

```bash
# 1. Code
sudo git clone <your-repo> /var/www/tempmail
cd /var/www/tempmail
composer install --no-dev --optimize-autoloader

# 2. Database
sudo mysql -e "CREATE DATABASE tempmail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER 'tempmail'@'127.0.0.1' IDENTIFIED BY 'a-strong-password';"
sudo mysql -e "GRANT SELECT, INSERT, UPDATE, DELETE ON tempmail.* TO 'tempmail'@'127.0.0.1';"
sudo mysql tempmail < schema.sql

# 3. Configuration
cp config.example.php config.php
sudo nano config.php            # DB credentials, DOMAIN, lifetimes

# 4. Permissions (see below)
sudo chown -R root:www-data /var/www/tempmail
sudo chmod -R o-rwx /var/www/tempmail
sudo chmod 640 /var/www/tempmail/config.php
sudo install -d -o www-data -g www-data -m 750 /var/www/tempmail/logs

# 5. Web server
sudo cp deploy/nginx.conf /etc/nginx/sites-available/tempmail
sudo nano /etc/nginx/sites-available/tempmail    # server_name + PHP-FPM socket
sudo ln -s /etc/nginx/sites-available/tempmail /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d mail.example.com

# 6. Check the install before trusting it
php verify.php
```

`verify.php` prints one PASS/FAIL line per check (database, tables, indexes,
config constants, writable log directory, Composer dependencies). Fix every
FAIL before debugging anything else.

### Postfix: deliver mail into the app

Add a transport in `/etc/postfix/master.cf` (the leading spaces on the second
line matter):

```
tempmail  unix  -       n       n       -       -       pipe
  flags=DRhu user=tempmail argv=/usr/bin/php /var/www/tempmail/receive.php ${recipient}
```

Then in `/etc/postfix/main.cf`:

```
virtual_mailbox_domains = mail.example.com
virtual_transport = tempmail
# accept every local part on the domain; receive.php decides what to keep
virtual_mailbox_maps = static:all
```

Create the pipe user and let it read the app:

```bash
sudo useradd --system --no-create-home --shell /usr/sbin/nologin tempmail
sudo chgrp -R tempmail /var/www/tempmail/{src,config.php,vendor,receive.php}
sudo chmod 640 /var/www/tempmail/config.php
sudo chgrp tempmail /var/www/tempmail/logs && sudo chmod 770 /var/www/tempmail/logs
sudo postfix reload
```

Mail for an address with no live inbox is discarded on purpose — no bounce,
no stored row.

### Cron: purge expired inboxes

```cron
*/10 * * * * www-data /usr/bin/php /var/www/tempmail/cleanup.php >> /var/www/tempmail/logs/cleanup.log 2>&1
```

### File permissions

| Path | Owner | Mode | Why |
|---|---|---|---|
| `/var/www/tempmail` | `root:www-data` | `750` | nothing world-readable |
| `config.php` | `root:www-data` | `640` | credentials; also readable by the Postfix pipe user's group if you add it |
| `logs/` | `www-data:tempmail` | `770` | written by PHP-FPM and by `receive.php` |
| `public/` | `root:www-data` | `750` | the only directory nginx serves |

## Configuration

Everything lives in `config.php` (gitignored):

| Constant | Meaning |
|---|---|
| `DOMAIN` | the domain addresses are issued on — must match Postfix |
| `INBOX_LIFETIME_MINUTES` | default 60 |
| `MAX_EXTENSIONS` / `EXTENSION_MINUTES` | default 3 × 60 minutes, a 4-hour ceiling |
| `COOKIE_LIFETIME_DAYS` | how long a returning visitor keeps their inbox (7) |
| `COOKIE_SECURE` | keep `true`; set `false` only for local http development |
| `MAX_BODY_BYTES` | per-part storage cap, larger bodies are truncated |
| `TRUSTED_PROXIES` | leave empty unless a proxy sits in front of nginx — see below |

### TRUSTED_PROXIES and rate limiting

Rate limits are keyed on the client IP. `client_ip()` reads `REMOTE_ADDR` and
only looks at `X-Forwarded-For` / `X-Real-IP` when `REMOTE_ADDR` is listed in
`TRUSTED_PROXIES`. With PHP-FPM directly behind nginx, leave the list empty:
`REMOTE_ADDR` is already the real client. Adding an address you do not control
lets clients pick their own rate-limit bucket by sending a header.

## Security notes

- Every query is a prepared statement; `LIMIT` values are bound as integers.
- Inbox access is authorized by the cookie token only. A message id belonging
  to another inbox returns `403`, exactly like an id that does not exist.
- HTML email is rendered inside `<iframe sandbox="allow-popups
  allow-popups-to-escape-sandbox">` via `srcdoc`. No `allow-scripts`, no
  `allow-same-origin` — email markup cannot run script or reach the page.
  (`allow-popups-to-escape-sandbox` only lets a clicked link open as a normal
  tab instead of an opaque sandboxed one.)
- The document is built server-side by `Message::iframeDocument()`, which also
  strips `<script>`/`<object>`, `on*` handlers and `javascript:` URLs, and adds
  `rel="noopener noreferrer"` to links.
- CSRF tokens are required on every state-changing POST.
- The CSP allows no inline scripts; all JavaScript is in `assets/app.js`.
- Remote images in emails load over HTTPS (`img-src https:`). Drop `https:`
  from the CSP in both `public/index.php` and `deploy/nginx.conf` if you would
  rather block tracking pixels.

## Troubleshooting

**Mail never appears in the inbox**

```bash
sudo tail -f /var/log/mail.log            # did Postfix accept and pipe it?
tail -f /var/www/tempmail/logs/app.log    # what did receive.php say?
```

- `status=bounced ... unknown user` → `virtual_mailbox_maps`/`virtual_transport`
  are not routing the domain to the `tempmail` transport.
- `receive: recipient domain not served` → `DOMAIN` in `config.php` differs from
  the domain Postfix delivered to.
- Nothing logged at all and no row → the address had no live inbox (expired, or
  never created). Expected behaviour.
- Test the pipe by hand:
  ```bash
  printf 'From: t <t@x.test>\nSubject: hello\n\nbody\n' \
    | sudo -u tempmail php /var/www/tempmail/receive.php you@mail.example.com
  ```

**Permission errors on the pipe**

`mail.log` shows `temporary failure. Command output: ... Permission denied`:

- the pipe user cannot read `config.php` (`chgrp tempmail config.php`,
  `chmod 640`), or
- it cannot write `logs/` (`chgrp tempmail logs; chmod 770 logs`), or
- `open_basedir` in the CLI `php.ini` excludes `/var/www/tempmail`.

**502 Bad Gateway / PHP-FPM socket mismatch**

`nginx.error.log` shows `connect() to unix:/run/php/php8.1-fpm.sock failed`:

```bash
ls /run/php/                              # what socket actually exists?
grep -r "^listen" /etc/php/*/fpm/pool.d/  # what does FPM listen on?
```

Put that exact path in the `fastcgi_pass` line and reload both services.

**429 responses while testing**

Ten address generations per IP per hour is the limit. Clear it during
development with `DELETE FROM rate_limits;`.

**Emails render as plain text**

`ext-mailparse` is missing and the fallback parser could not find an HTML part.
`php verify.php` reports this; install `php-mailparse` and run
`composer install`.

## Project layout

```
public/            nginx root — index.php, assets/, api/
src/               Database, Inbox, Message, RateLimiter, Csrf, Helpers
receive.php        Postfix pipe target
cleanup.php        cron: purge expired inboxes and mail
verify.php         post-install checks
schema.sql         the three tables
deploy/nginx.conf  server block
config.php         credentials (gitignored)
```
