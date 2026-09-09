# Deploying TempMail on a VPS — step by step

A complete build from a blank Ubuntu server to a working disposable-mail site
that receives real email. Every command is copy-paste ready; the only things
you substitute are your domain, your server's IP and your own passwords.

**Target:** Ubuntu 24.04 LTS (22.04 and Debian 12 work the same — only the PHP
version number differs, and step 3 detects that for you).

**Time:** about 30 minutes, plus DNS propagation.

---

## 0. Before you start

You need:

| Thing | Notes |
|---|---|
| A VPS | 1 vCPU / 1 GB RAM is plenty. Hetzner, DigitalOcean, Vultr, Contabo… |
| Root or sudo access | via SSH |
| A domain | you can edit DNS records for |
| Inbound port 25 open | almost all providers allow **inbound** 25. Some block **outbound** 25 — that does not matter, this app only receives |

**Pick the mail domain now.** Addresses will look like `k7x9m2p4@YOURDOMAIN`.
Use a dedicated subdomain or a throwaway domain — never the domain your real
email runs on, because you will point its MX record at this server.

Throughout this guide:

- `mail.example.com` → your chosen mail domain
- `203.0.113.10` → your VPS's public IP

Set them once in your shell so the later commands can be pasted verbatim.
**Stay in the same SSH session**, or re-run this block:

```bash
export TM_DOMAIN=mail.example.com
export TM_IP=203.0.113.10
```

---

## 1. DNS records

Add these at your DNS provider **before** you install anything — propagation
takes a few minutes and you will need it for both TLS and mail.

| Type | Name | Value | Priority |
|---|---|---|---|
| A | `mail` | `203.0.113.10` | — |
| MX | `mail` | `mail.example.com.` (trailing dot) | 10 |

That is the minimum: an A record so the browser and Let's Encrypt find the
site, and an MX record so other mail servers know where to deliver.

Optional but polite — an SPF record saying this host sends no mail:

| Type | Name | Value |
|---|---|---|
| TXT | `mail` | `v=spf1 -all` |

You do **not** need DKIM, DMARC or a PTR record: those matter for *sending*,
and this app never sends.

Check the records have landed (from your laptop, not the VPS):

```bash
dig +short A mail.example.com
dig +short MX mail.example.com
```

Both must answer before you continue. If `dig` is missing on your machine, use
`nslookup` or https://dnschecker.org.

---

## 2. Prepare the server

SSH in as root (or a sudo user) and get the base system in order.

```bash
# Update everything
sudo apt update && sudo apt -y upgrade

# Name the host after your mail domain — Postfix uses this in its greeting
sudo hostnamectl set-hostname "$TM_DOMAIN"
echo "127.0.1.1 $TM_DOMAIN" | sudo tee -a /etc/hosts

# Sensible timezone (logs and expiry timestamps read in local time)
sudo timedatectl set-timezone UTC

# Firewall: SSH, web, and SMTP in
sudo apt -y install ufw
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw allow 25/tcp
sudo ufw --force enable
sudo ufw status
```

On a 1 GB box, add swap so MySQL and PHP-FPM never get OOM-killed:

```bash
sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile
sudo mkswap /swapfile && sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

---

## 3. Install the software

```bash
sudo apt -y install nginx mariadb-server git unzip composer certbot python3-certbot-nginx \
                    php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-mailparse
```

**Prefer Apache?** Install it instead of nginx and use `deploy/apache.conf`:

```bash
sudo apt -y install apache2 mariadb-server git unzip composer certbot python3-certbot-apache \
                    php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-mailparse
sudo a2enmod rewrite headers expires proxy proxy_fcgi
```

Everything else in this guide is identical except step 7 — the Apache version
of that step is at the end of this file.

Note the PHP version you got — you need it in step 7:

```bash
php -v | head -1
ls /run/php/                 # e.g. php8.3-fpm.sock
```

If `php-mailparse` was not found (older Debian), install it from PECL:

```bash
sudo apt -y install php-dev php-pear build-essential
sudo pecl install mailparse
echo "extension=mailparse.so" | sudo tee /etc/php/$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')/mods-available/mailparse.ini
sudo phpenmod mailparse
sudo systemctl restart php*-fpm
php -m | grep mailparse
```

`mailparse` is optional — `receive.php` falls back to its own parser without
it — but the library handles exotic MIME better, so install it if you can.

---

## 4. Database

```bash
# Lock down the MariaDB/MySQL install (answer: no unix_socket change needed,
# remove anonymous users, disallow remote root, remove test db, reload)
sudo mysql_secure_installation
```

Create the database and a user with only the rights the app needs. **Change
the password**:

```bash
TM_DB_PASS='CHANGE-ME-to-a-long-random-password'

sudo mysql <<SQL
CREATE DATABASE IF NOT EXISTS tempmail CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'tempmail'@'127.0.0.1' IDENTIFIED BY '${TM_DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE ON tempmail.* TO 'tempmail'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
```

A good password, if you want one generated:

```bash
openssl rand -base64 24
```

---

## 5. Get the code

```bash
sudo git clone https://github.com/FaisalBinShakeel/tempmail.git /var/www/tempmail
cd /var/www/tempmail

sudo composer install --no-dev --optimize-autoloader --no-interaction

# Import the three tables
sudo mysql tempmail < schema.sql
```

If Composer refuses to run as root, use
`sudo COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader`.

---

## 6. Configure the app

```bash
sudo cp config.example.php config.php
sudo nano config.php
```

Set these, leave the rest at their defaults:

```php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'tempmail';
const DB_USER = 'tempmail';
const DB_PASS = 'the password you created in step 4';

const DOMAIN = 'mail.example.com';   // must match your MX record exactly

const COOKIE_SECURE   = true;        // you are about to install TLS — keep this true
const TRUSTED_PROXIES = [];          // empty: nginx talks to PHP-FPM directly
```

> `TRUSTED_PROXIES` stays empty unless Cloudflare or another proxy sits in
> front of nginx. Adding an address you do not control lets visitors forge
> their own IP and walk around the rate limits.

Now permissions. Two identities touch the files: **www-data** (nginx/PHP-FPM)
and **tempmail** (the user Postfix runs the mail pipe as).

```bash
# The pipe user — no login, no home directory. Putting it in the www-data
# group is what lets it read config.php and write the log.
sudo useradd --system --no-create-home --shell /usr/sbin/nologin \
             --groups www-data tempmail

# Owned by root, readable by the www-data group, closed to everyone else
sudo chown -R root:www-data /var/www/tempmail
sudo chmod -R o-rwx /var/www/tempmail
sudo find /var/www/tempmail -type d -exec chmod 750 {} \;
sudo find /var/www/tempmail -type f -exec chmod 640 {} \;

# config.php holds the DB password — group read only, never world
sudo chmod 640 /var/www/tempmail/config.php

# Both identities append to the same log. The setgid bit (2770) makes new
# files inherit the www-data group, and pre-creating app.log group-writable
# stops whichever process gets there first from locking the other one out.
sudo install -d -o www-data -g www-data -m 2770 /var/www/tempmail/logs
sudo install -o www-data -g www-data -m 664 /dev/null /var/www/tempmail/logs/app.log
sudo install -o www-data -g www-data -m 664 /dev/null /var/www/tempmail/logs/cleanup.log
```

Nothing needs to be world-readable: `www-data` and `tempmail` are both in the
`www-data` group, and the group bit on each directory (`750`) is what lets
them in.

Confirm the pipe user really can read the config and write the log — this is
the single most common reason mail silently fails later:

```bash
sudo -u tempmail php -r 'require "/var/www/tempmail/config.php"; echo DOMAIN, "\n";'
sudo -u tempmail sh -c 'echo test >> /var/www/tempmail/logs/app.log' && echo "log writable"
```

---

## 7. Web server + HTTPS

The shipped nginx config points at certificate files that do not exist yet, so
do this in three moves: a throwaway HTTP site, the certificate, then the real
config.

**a) A temporary HTTP-only site so Let's Encrypt can reach you**

```bash
sudo tee /etc/nginx/sites-available/tempmail-bootstrap > /dev/null <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${TM_DOMAIN};
    root /var/www/tempmail/public;
    location / { return 404; }
    location ^~ /.well-known/acme-challenge/ { allow all; }
}
EOF

sudo ln -sf /etc/nginx/sites-available/tempmail-bootstrap /etc/nginx/sites-enabled/tempmail-bootstrap
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

**b) The certificate**

```bash
sudo certbot certonly --webroot -w /var/www/tempmail/public \
     -d "$TM_DOMAIN" --agree-tos -m you@youremail.com --non-interactive
```

If this fails, your A record has not propagated or port 80 is closed — fix
that before continuing, everything after depends on it.

**c) The real config**

```bash
sudo rm -f /etc/nginx/sites-enabled/tempmail-bootstrap
sudo cp /var/www/tempmail/deploy/nginx.conf /etc/nginx/sites-available/tempmail

# Point it at your domain and at the PHP-FPM socket that actually exists
PHP_SOCK=$(ls /run/php/php*-fpm.sock | head -1)
sudo sed -i "s/mail\.example\.com/${TM_DOMAIN}/g" /etc/nginx/sites-available/tempmail
sudo sed -i "s|unix:/run/php/php8\.1-fpm\.sock|unix:${PHP_SOCK}|" /etc/nginx/sites-available/tempmail

sudo ln -sf /etc/nginx/sites-available/tempmail /etc/nginx/sites-enabled/tempmail
sudo nginx -t && sudo systemctl reload nginx
```

Check the substitutions landed before you reload, if you like:

```bash
grep -E 'server_name|ssl_certificate |fastcgi_pass' /etc/nginx/sites-available/tempmail
```

**Renewal** is automatic through `certbot.timer`, and the config's port-80
block keeps `/.well-known/acme-challenge/` reachable forever. Prove it:

```bash
sudo certbot renew --dry-run
systemctl status certbot.timer --no-pager | head -3
```

**Check the site answers**

```bash
curl -sI "https://$TM_DOMAIN/" | head -3
curl -s "https://$TM_DOMAIN/" | grep -o 'class="address">[^<]*'
```

You should get `HTTP/2 200` and an address like `k7x9m2p4@mail.example.com`.
Open it in a browser: the address is there on first paint, with a countdown
ticking down from 59:59.

---

## 8. Postfix — the part that makes mail actually arrive

```bash
sudo apt -y install postfix
```

In the installer choose **Internet Site**, and enter your mail domain as the
system mail name.

### 8a. main.cf

```bash
sudo postconf -e "myhostname = ${TM_DOMAIN}"
sudo postconf -e "mydestination = localhost"
sudo postconf -e "virtual_mailbox_domains = ${TM_DOMAIN}"
sudo postconf -e "virtual_mailbox_maps = static:all"
sudo postconf -e "virtual_transport = tempmail"
sudo postconf -e "inet_interfaces = all"
sudo postconf -e "inet_protocols = all"
sudo postconf -e "message_size_limit = 10485760"
sudo postconf -e "smtpd_helo_required = yes"
sudo postconf -e "disable_vrfy_command = yes"
sudo postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination"
```

What these do:

- `mydestination = localhost` — **critical**. If your mail domain is left in
  `mydestination`, Postfix tries local delivery and never reaches the app.
- `virtual_mailbox_domains` — we accept mail for this domain…
- `virtual_mailbox_maps = static:all` — …for *every* local part, because
  addresses are created on demand. `receive.php` decides what to keep.
- `virtual_transport = tempmail` — hand every accepted message to the pipe
  defined next.
- `reject_unauth_destination` — the anti-open-relay rule. Keep it.

> If Postfix logs `do not list domain mail.example.com in BOTH mydestination
> and virtual_mailbox_domains`, that is exactly the `mydestination` line above
> not having been applied. Re-run it and restart.

### 8b. master.cf — the pipe

```bash
sudo tee -a /etc/postfix/master.cf > /dev/null <<EOF

tempmail  unix  -       n       n       -       -       pipe
  flags=DRhu user=tempmail argv=/usr/bin/php /var/www/tempmail/receive.php \${recipient}
EOF
```

> The two leading spaces on the second line are required by Postfix — if you
> retype this by hand, keep them.

Verify the PHP path matches your system (`which php`), then reload:

```bash
which php                      # expect /usr/bin/php
sudo postfix check
sudo systemctl restart postfix
sudo systemctl enable postfix
```

Confirm the settings took:

```bash
postconf -n | grep -E 'myhostname|mydestination|virtual_(mailbox|transport)'
postconf -M | grep tempmail
```

And confirm you did not just build an open relay — this must be **rejected**:

```bash
swaks --to someone@gmail.com --from spammer@example.com --server "$TM_DOMAIN" 2>&1 | tail -3
# expect: 554 5.7.1 <someone@gmail.com>: Relay access denied
```

Mail addressed to an expired or never-created inbox is discarded on purpose —
no bounce, no stored row.

---

## 9. Cron — purge expired inboxes

```bash
echo '*/10 * * * * www-data /usr/bin/php /var/www/tempmail/cleanup.php >> /var/www/tempmail/logs/cleanup.log 2>&1' \
  | sudo tee /etc/cron.d/tempmail
sudo chmod 644 /etc/cron.d/tempmail
```

Run it once by hand to be sure it works:

```bash
sudo -u www-data php /var/www/tempmail/cleanup.php
# [2026-09-09 13:00:00] cleanup: 0 emails, 0 inboxes, 0 rate-limit rows removed
```

Keep the logs from growing forever:

```bash
sudo tee /etc/logrotate.d/tempmail > /dev/null <<'EOF'
/var/www/tempmail/logs/*.log {
    weekly
    rotate 4
    compress
    missingok
    notifempty
    create 664 www-data www-data
}
EOF
```

---

## 10. Verify the install

```bash
cd /var/www/tempmail && sudo -u www-data php verify.php
```

Every line should read `PASS` (WARN on `mailparse`/composer is only a
degraded-path notice). Fix any `FAIL` before going further — it tells you
exactly what is wrong.

Then a real end-to-end test:

```bash
# 1. Open https://mail.example.com in a browser and copy the address shown.

# 2. Send it a real email from Gmail/Outlook/your phone.

# 3. Watch it land:
sudo tail -f /var/log/mail.log          # Postfix: accepted → piped
tail -f /var/www/tempmail/logs/app.log  # the app: only logs problems
```

The email should appear in the open browser tab within five seconds, with no
refresh. In `mail.log` you want to see `status=sent (delivered via tempmail
service)`.

Prefer to test without an external sender? Send one locally:

```bash
sudo apt -y install swaks
swaks --to "PASTE-THE-ADDRESS-HERE" --from tester@example.com --server 127.0.0.1
```

And check the row landed:

```bash
sudo mysql tempmail -e "SELECT id, inbox_address, sender_email, subject FROM emails ORDER BY id DESC LIMIT 5;"
```

---

## 11. Troubleshooting

**Nothing arrives, and `mail.log` says `status=bounced ... unknown user`**
`mydestination` still contains your mail domain, or `virtual_transport` is not
set. Re-check step 8a: `postconf mydestination virtual_transport virtual_mailbox_domains`.

**`mail.log` shows `temporary failure. Command output: ... Permission denied`**
The `tempmail` pipe user cannot read `config.php` or write `logs/`. Re-run the
permission block in step 6, then `sudo systemctl restart postfix`.

**`mail.log` shows the pipe ran, but no row appears**
`tail /var/www/tempmail/logs/app.log`. `recipient domain not served` means
`DOMAIN` in `config.php` does not match the domain Postfix delivered to.
Nothing logged at all and no row means the address had no live inbox — which
is correct behaviour for an expired one.

**Browser shows 502 Bad Gateway**
The FPM socket path in nginx is wrong:

```bash
ls /run/php/
grep -r '^listen' /etc/php/*/fpm/pool.d/
sudo nano /etc/nginx/sites-available/tempmail   # fix fastcgi_pass
sudo systemctl reload nginx php*-fpm
```

**Browser shows a plain "TempMail is having trouble right now"**
That is the app's own 503 — the database is unreachable. Check
`logs/app.log` for the real reason, then `systemctl status mariadb` and the
credentials in `config.php`.

**No mail at all, not even in `mail.log`**
Port 25 is not reachable. From your laptop: `nc -zv mail.example.com 25`.
If it times out, check `sudo ufw status`, `sudo ss -lntp | grep :25`, and
whether your provider blocks inbound 25 (rare, but ask support).

**429 responses while testing**
Ten address generations per IP per hour is the limit. Clear it while testing:
`sudo mysql tempmail -e "DELETE FROM rate_limits;"`.

**Emails render as plain text**
`mailparse` is missing and the fallback found no HTML part — see step 3.

---

## 12. Day-to-day operations

**Update to a newer version of the app**

```bash
cd /var/www/tempmail
sudo git pull
sudo composer install --no-dev --optimize-autoloader

# New files arrive with default permissions — put them back
sudo chown -R root:www-data /var/www/tempmail
sudo find /var/www/tempmail -type d -exec chmod 750 {} \;
sudo find /var/www/tempmail -type f -exec chmod 640 {} \;
sudo chmod 2770 /var/www/tempmail/logs

sudo -u www-data php verify.php
```

`config.php` is gitignored, so your credentials survive a pull. There are no
migrations — if `schema.sql` ever changes, the release notes will say so.

**Change inbox lifetime, extension ceiling or the domain**
Edit `config.php`; no restart needed.

**Back up**

```bash
sudo mysqldump tempmail | gzip > ~/tempmail-$(date +%F).sql.gz
```

Honestly: there is nothing here worth backing up except `config.php` and
`nginx.conf`. Every inbox is disposable by design.

**Who runs as what**

| Process | User | Touches |
|---|---|---|
| nginx / PHP-FPM | `www-data` | `public/`, `src/`, `config.php` (read), `logs/` (write) |
| Postfix pipe → `receive.php` | `tempmail` | `config.php` (read), `logs/` (write), `emails` table (insert) |
| cron → `cleanup.php` | `www-data` | deletes expired rows |

**Block tracking pixels in emails** (optional)
Remove `https:` from `img-src` in both `public/index.php` and
`/etc/nginx/sites-available/tempmail`, then reload nginx. Remote images stop
loading; everything else keeps working.

---

## Appendix: Apache instead of nginx

Replaces step 7. Steps 1–6 and 8–12 are unchanged.

```bash
sudo cp /var/www/tempmail/deploy/apache.conf /etc/apache2/sites-available/tempmail.conf

PHP_SOCK=$(ls /run/php/php*-fpm.sock | head -1)
sudo sed -i "s/mail\.example\.com/${TM_DOMAIN}/g" /etc/apache2/sites-available/tempmail.conf
sudo sed -i "s|unix:/run/php/php8\.3-fpm\.sock|unix:${PHP_SOCK}|" /etc/apache2/sites-available/tempmail.conf

sudo a2enmod rewrite headers expires proxy proxy_fcgi ssl
sudo a2dissite 000-default
sudo a2ensite tempmail
sudo apache2ctl configtest && sudo systemctl reload apache2

sudo certbot --apache -d "$TM_DOMAIN" --agree-tos -m you@youremail.com --redirect --non-interactive
```

`deploy/apache.conf` inlines the same rules as the nginx config (security
headers, front controller, asset caching, denies) with `AllowOverride None`,
which is the faster option. If you would rather use `.htaccess` — on shared
hosting, say — set `AllowOverride All` and delete the `<Directory>` body: the
repo already ships `public/.htaccess` with the identical rules, plus a root
`.htaccess` that protects the app if the document root is ever left at the
project root instead of `public/`.

Check it the same way:

```bash
curl -sI "https://$TM_DOMAIN/" | head -3
curl -s "https://$TM_DOMAIN/config.php" | grep -c DB_PASS      # must print 0
curl -sI "https://$TM_DOMAIN/" | grep -i content-security-policy
```
