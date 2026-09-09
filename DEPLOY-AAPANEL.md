# Installing TempMail on aaPanel

aaPanel does most of the plumbing for you — nginx, PHP-FPM, MySQL, SSL and cron
all have UI screens. This guide uses the panel where the panel is better, and
SSH only where it has to (Postfix, permissions, verification).

If you are on a plain VPS with no control panel, use **[DEPLOY.md](DEPLOY.md)**
instead.

## aaPanel differences at a glance

| | Plain VPS | aaPanel |
|---|---|---|
| Web root | `/var/www/tempmail` | `/www/wwwroot/<domain>` |
| Web user | `www-data` | **`www`** |
| PHP CLI | `/usr/bin/php` | `/www/server/php/82/bin/php` |
| FPM socket | `/run/php/php8.2-fpm.sock` | `/tmp/php-cgi-82.sock` |
| Site config (Apache) | `/etc/apache2/sites-available/…` | `/www/server/panel/vhost/apache/<domain>.conf` |
| Site config (nginx) | `/etc/nginx/sites-available/…` | `/www/server/panel/vhost/nginx/<domain>.conf` |
| Custom rules (Apache) | vhost or `.htaccess` | **`public/.htaccess` — ships in this repo, nothing to configure** |
| Custom rules (nginx) | edit the site config | `/www/server/panel/vhost/rewrite/<domain>.conf` |
| Restart the web server | `systemctl reload …` | `/etc/init.d/httpd reload` (Apache) / `/etc/init.d/nginx reload` |
| SSL | certbot by hand | Website → SSL → Let's Encrypt |
| Cron | `/etc/cron.d/…` | Cron page in the panel |
| Firewall | `ufw` | panel **Security** page **+** your cloud firewall |

`82` above is PHP 8.2 — substitute the version you install in step 2 (`81`,
`83`, …) everywhere it appears.

> **Before anything else:** do **not** install aaPanel's *Mail Server* plugin
> for this domain. That plugin takes over Postfix and delivers into real
> mailboxes; TempMail needs mail piped into `receive.php` instead. If the
> plugin is already installed for other domains, keep TempMail on its own
> subdomain and do not add that subdomain to the plugin.

---

## 1. DNS records

At your DNS provider, for the domain you will use for addresses — a dedicated
subdomain like `mail.example.com` is the right choice:

| Type | Name | Value | Priority |
|---|---|---|---|
| A | `mail` | your server IP | — |
| MX | `mail` | `mail.example.com.` (trailing dot) | 10 |

Check from your own machine before continuing:

```bash
dig +short A mail.example.com
dig +short MX mail.example.com
```

---

## 2. Panel: install the software

**Software Store → Runtime environment**

- **Apache** (or Nginx — both are covered below; Apache needs no extra
  config because the repo ships `public/.htaccess`)
- **PHP 8.2** — after installing, click **Setting → Install extensions** and
  make sure these are on: `pdo_mysql`, `mbstring`, `fileinfo`, `opcache`
- **MySQL 5.7 or 8.0**
- **phpMyAdmin** (optional, handy for step 6)

`mailparse` is not in aaPanel's extension list. That is fine — `receive.php`
falls back to its own MIME parser without it. Step 11 shows how to add it if
you want the library version.

---

## 3. Panel: create the website and database

**Website → Add site**

| Field | Value |
|---|---|
| Domain | `mail.example.com` |
| Root directory | leave the default: `/www/wwwroot/mail.example.com` |
| PHP version | 8.2 |
| Database | **MySQL** — the panel creates DB, user and password |

Copy the database name, user and password it shows you. You need them in
step 5.

---

## 4. SSH: get the code in place

Log in over SSH (panel **Terminal** works too):

```bash
DOMAIN=mail.example.com
PHPBIN=/www/server/php/82/bin/php

cd /www/wwwroot
# Keep whatever the panel created rather than deleting it
mv "$DOMAIN" "${DOMAIN}.panel-default-$(date +%s)"

git clone https://github.com/FaisalBinShakeel/tempmail.git "$DOMAIN"
cd "$DOMAIN"
chown -R www:www /www/wwwroot/$DOMAIN     # step 10 tightens this further
```

`index.php`, `src/`, `public/` and `receive.php` must end up directly under
`/www/wwwroot/mail.example.com/`. Once the site works you can delete the
`.panel-default-*` folder.

---

## 5. Configuration file

```bash
cp config.example.php config.php
nano config.php     # or edit it in the panel's File manager
```

Fill in what the panel gave you in step 3:

```php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'the database name from the panel';
const DB_USER = 'the database user from the panel';
const DB_PASS = 'the database password from the panel';

const DOMAIN = 'mail.example.com';   // must match your MX record exactly

const COOKIE_SECURE   = true;        // step 8 gives you HTTPS — keep this true
const TRUSTED_PROXIES = [];          // empty: nginx talks to PHP-FPM directly
```

> Leave `TRUSTED_PROXIES` empty unless Cloudflare or another proxy sits in
> front. Adding an address you do not control lets visitors forge their own IP
> and walk around the rate limits. Behind Cloudflare, put `['127.0.0.1']` there
> **only** if you have also configured aaPanel's `set_real_ip_from` rules.

---

## 6. Import the database schema

Easiest over SSH:

```bash
mysql -u DB_USER -p DB_NAME < /www/wwwroot/$DOMAIN/schema.sql
```

Or in the panel: **Database → phpMyAdmin → your database → Import → choose
`schema.sql`**.

Confirm three tables exist:

```bash
mysql -u DB_USER -p DB_NAME -e "SHOW TABLES;"
# emails / inboxes / rate_limits
```

---

## 7. Panel: point the site at `public/`

This is the step people miss, and it is the one that decides whether your
config file is exposed.

**Website → mail.example.com → Settings → Site directory**

- **Running directory:** select **`/public`** → Save

Now nginx serves `/www/wwwroot/mail.example.com/public`, and `config.php`,
`src/`, `logs/` and the CLI scripts sit above the web root where no URL can
reach them.

Also on that screen, leave **Anti-XSS (open_basedir)** enabled — the app runs
fine inside it.

---

## 8. Panel: HTTPS

**Website → mail.example.com → SSL → Let's Encrypt** → select the domain →
**Apply**, then turn on **Force HTTPS**.

The panel writes the certificate paths into the site config and renews
automatically. Wait for it to say the certificate was issued before moving on —
`COOKIE_SECURE = true` means the app's cookies need HTTPS to work at all.

---

## 9. Web server rules (headers + protection)

### If you run Apache

Nothing to configure — `public/.htaccess` ships in the repo and aaPanel's
Apache vhost already sets `AllowOverride All`, so it is picked up as soon as
the site loads. It adds the security headers, the front controller, asset
caching, and refuses dotfiles.

Confirm the panel really allows overrides, then reload:

```bash
grep -n "AllowOverride" /www/server/panel/vhost/apache/$DOMAIN.conf
# expect: AllowOverride All

/www/server/apache/bin/apachectl -t && /etc/init.d/httpd reload
```

If it says `AllowOverride None`, open **Website → Settings → Configuration
File** and change it to `All`, then reload.

There is a second `.htaccess` at the project root. It only comes into play if
the document root was left at the site root instead of `/public` — then it
refuses `config.php`, `src/`, `logs/`, `vendor/` and the CLI scripts while
still serving the app out of `public/`. Step 7 remains the correct setup; this
is a safety net, not a substitute.

### If you run Nginx

```bash
cp /www/wwwroot/$DOMAIN/deploy/aapanel-rewrite.conf \
   /www/server/panel/vhost/rewrite/$DOMAIN.conf

grep -n "vhost/rewrite" /www/server/panel/vhost/nginx/$DOMAIN.conf
# expect: include /www/server/panel/vhost/rewrite/mail.example.com.conf;

/www/server/nginx/sbin/nginx -t && /etc/init.d/nginx reload
```

If the include line is missing, add it between the `#REWRITE-START` and
`#REWRITE-END` markers via **Website → Settings → Configuration File**.

> The denies in that file all use `^~` on purpose: aaPanel's
> `include enable-php-82.conf;` sits *earlier* in the site config and matches
> every `*.php` request, so a plain regex deny would never be reached. `^~`
> outranks regex locations in nginx, so the deny wins.

### Either way, check it

```bash
curl -sI https://$DOMAIN/ | head -3                                    # expect 200
curl -s -o /dev/null -w "%{http_code}\n" https://$DOMAIN/config.php    # must not show the file
curl -s https://$DOMAIN/config.php | grep -c DB_PASS                   # must print 0
curl -s https://$DOMAIN/ | grep -o 'class="address">[^<]*'             # your first address
curl -sI https://$DOMAIN/ | grep -i content-security-policy            # headers present
```

With the document root on `/public`, a request for `/config.php` simply
renders the inbox page — the file is above the web root and no URL reaches it.

---

## 10. Permissions

Two identities touch these files: **`www`** (nginx/PHP-FPM) and **`tempmail`**
(the user Postfix will run the mail pipe as, created here).

```bash
cd /www/wwwroot/$DOMAIN

# The pipe user: no login, member of the www group
useradd --system --no-create-home --shell /sbin/nologin --groups www tempmail 2>/dev/null || \
  usermod -a -G www tempmail

# Owned by the web user, closed to everyone else
chown -R www:www /www/wwwroot/$DOMAIN
find /www/wwwroot/$DOMAIN -type d -exec chmod 750 {} \;
find /www/wwwroot/$DOMAIN -type f -exec chmod 640 {} \;
chmod 640 config.php

# Both identities append to the same log. setgid (2770) makes new files
# inherit the www group; pre-creating the logs group-writable stops whichever
# process gets there first from locking the other one out.
install -d -o www -g www -m 2770 logs
install -o www -g www -m 664 /dev/null logs/app.log
install -o www -g www -m 664 /dev/null logs/cleanup.log
```

Prove the pipe user can do its job — this is the number one cause of "mail
never arrives" later:

```bash
sudo -u tempmail $PHPBIN -r 'require "/www/wwwroot/mail.example.com/config.php"; echo DOMAIN, "\n";'
sudo -u tempmail sh -c 'echo test >> /www/wwwroot/mail.example.com/logs/app.log' && echo "log writable"
```

No `sudo` on the box? Same checks with `su`:

```bash
su -s /bin/sh tempmail -c "$PHPBIN -r 'require \"/www/wwwroot/mail.example.com/config.php\"; echo DOMAIN;'"
su -s /bin/sh tempmail -c 'echo test >> /www/wwwroot/mail.example.com/logs/app.log' && echo "log writable"
```

Reload the site once from the panel after changing ownership if pages 403.

---

## 11. Optional: the MIME parser library

`receive.php` works without this. Install it only if you want
php-mime-mail-parser's stricter parsing.

aaPanel's PHP blocks `proc_open`, which Composer needs, so this is a
three-part move:

1. **Panel → Software Store → PHP 8.2 → Setting → Disabled functions** —
   remove `proc_open` and `putenv`, save.
2. Install:
   ```bash
   cd /www/wwwroot/$DOMAIN
   curl -sS https://getcomposer.org/installer | $PHPBIN -- --install-dir=/usr/local/bin --filename=composer
   $PHPBIN /usr/local/bin/composer install --no-dev --optimize-autoloader
   chown -R www:www vendor
   ```
3. Put `proc_open` and `putenv` **back** in the disabled list.

The library also wants the `mailparse` extension. If your aaPanel PHP has no
extension entry for it:

```bash
/www/server/php/82/bin/pecl install mailparse
echo "extension=mailparse.so" > /www/server/php/82/etc/php.d/mailparse.ini 2>/dev/null || \
  echo "extension=mailparse.so" >> /www/server/php/82/etc/php.ini
/etc/init.d/php-fpm-82 restart
$PHPBIN -m | grep mailparse
```

If `pecl` fails to build, stop — the fallback parser is already handling your
mail. `verify.php` reports this as a WARN, not a failure.

---

## 12. Panel: the cleanup cron

**Cron → Add task**

| Field | Value |
|---|---|
| Type of task | **Shell Script** |
| Name | TempMail cleanup |
| Period | **N minutes** → every **10** minutes |
| Script | `/www/server/php/82/bin/php /www/wwwroot/mail.example.com/cleanup.php` |

Save, then hit **Execute** once and open the log — you should see:

```
[2026-09-09 13:00:00] cleanup: 0 emails, 0 inboxes, 0 rate-limit rows removed
```

---

## 13. SSH: Postfix

aaPanel does not manage this, so it is plain package work. Install:

```bash
# Ubuntu / Debian
apt update && apt -y install postfix
# CentOS / AlmaLinux / Rocky
# yum -y install postfix && systemctl enable postfix
```

On Debian/Ubuntu choose **Internet Site** and enter your mail domain as the
system mail name.

**Configure it:**

```bash
DOMAIN=mail.example.com

postconf -e "myhostname = ${DOMAIN}"
postconf -e "mydestination = localhost"
postconf -e "virtual_mailbox_domains = ${DOMAIN}"
postconf -e "virtual_mailbox_maps = static:all"
postconf -e "virtual_transport = tempmail"
postconf -e "inet_interfaces = all"
postconf -e "inet_protocols = all"
postconf -e "message_size_limit = 10485760"
postconf -e "smtpd_helo_required = yes"
postconf -e "disable_vrfy_command = yes"
postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination"
```

- `mydestination = localhost` is **critical** — leave your mail domain in
  `mydestination` and Postfix delivers locally, never reaching the app. Postfix
  will log `do not list domain … in BOTH mydestination and
  virtual_mailbox_domains` if you get this wrong.
- `virtual_mailbox_maps = static:all` is the catch-all: every local part is
  accepted, and `receive.php` decides what to keep.

**Add the pipe** (note the two leading spaces on the second line, and the
aaPanel PHP path):

```bash
cat >> /etc/postfix/master.cf <<EOF

tempmail  unix  -       n       n       -       -       pipe
  flags=DRhu user=tempmail argv=/www/server/php/82/bin/php /www/wwwroot/${DOMAIN}/receive.php \${recipient}
EOF

postfix check
systemctl restart postfix && systemctl enable postfix
```

**Verify:**

```bash
postconf -n | grep -E 'myhostname|mydestination|virtual_(mailbox|transport)'
postconf -M | grep tempmail
```

---

## 14. Open port 25

Two places, both matter:

1. **aaPanel → Security → Firewall** → add port **25** (TCP), allow.
2. **Your cloud provider's firewall / security group** — AWS, Oracle, Alibaba
   and Google all block inbound ports by default.

Check from your own machine, not the server:

```bash
nc -zv mail.example.com 25
```

If that times out, mail can never reach you — fix it before testing.

---

## 15. Verify and test end to end

```bash
cd /www/wwwroot/$DOMAIN
sudo -u www $PHPBIN verify.php
```

Every line should read `PASS` (`WARN` on mailparse/composer is only the
degraded-path notice from step 11). Then:

1. Open `https://mail.example.com` — an address appears immediately with a
   countdown.
2. Send that address a real email from Gmail or your phone.
3. Watch it land:

```bash
tail -f /var/log/mail.log            # Ubuntu/Debian — Postfix accepted → piped
# tail -f /var/log/maillog           # CentOS/AlmaLinux
tail -f /www/wwwroot/$DOMAIN/logs/app.log   # the app only logs problems
```

The email should appear in the open browser tab within five seconds, no
refresh. In the mail log you want `status=sent (delivered via tempmail
service)`.

Confirm you did not build an open relay — this must be **rejected**:

```bash
apt -y install swaks   # CentOS: yum -y install epel-release && yum -y install swaks
swaks --to someone@gmail.com --from spammer@example.com --server "$DOMAIN" 2>&1 | tail -3
# expect: 554 5.7.1 <someone@gmail.com>: Relay access denied
```

---

## 16. aaPanel-specific troubleshooting

**404 on every page, or the raw PHP source downloads**
Running directory is not `/public`. Redo step 7.

**403 Forbidden**
Ownership. `chown -R www:www /www/wwwroot/mail.example.com`, then re-apply the
`find … chmod` lines from step 10.

**Blank page, or "TempMail is having trouble right now"**
That second one is the app's own 503: the database is unreachable. Check
`logs/app.log`, then the credentials in `config.php` against **Database** in
the panel.

**`open_basedir restriction in effect`** in the PHP error log
The site's anti-XSS path does not cover the app. In **Website → Settings →
Site directory**, either turn off Anti-XSS or make sure the running directory
is `/public` under the same site root.

**`proc_open() has been disabled for security reasons`**
Composer only. See step 11 — remove it from disabled functions, install, put it
back.

**Apache: security headers missing, or every URL 404s**
`AllowOverride` is not `All`, so `public/.htaccess` is being ignored — see
step 9. `Internal Server Error` instead means a module is missing:
`/www/server/apache/bin/apachectl -M | grep -E 'rewrite|headers'`. Enable
Apache's rewrite and headers modules from the panel's Apache settings.

**Apache: `.htaccess` works but PHP files download as text**
The site's PHP version is not set. **Website → Settings → PHP version** →
pick 8.2, then reload.

**Nginx: custom rules stopped working**
The panel overwrote `/www/server/panel/vhost/rewrite/<domain>.conf`, which it
does when you pick a rewrite preset in the UI. Copy
`deploy/aapanel-rewrite.conf` back over it and reload nginx.

**Mail never arrives, nothing in the mail log**
Port 25 is closed — step 14. Check both the panel firewall and the cloud one.

**Mail log shows `status=bounced … unknown user`**
`mydestination` still contains your mail domain, or `virtual_transport` did not
apply. Re-run the `postconf` lines in step 13 and restart Postfix.

**Mail log shows `temporary failure … Permission denied`**
The `tempmail` user cannot read `config.php` or append to `logs/app.log`. Re-run
step 10, including the two `sudo -u tempmail` checks, then
`systemctl restart postfix`.

**Mail log shows the pipe ran, but no email appears**
`tail /www/wwwroot/<domain>/logs/app.log`. `recipient domain not served` means
`DOMAIN` in `config.php` does not match what Postfix delivered to. No log line
and no row means the address had no live inbox — correct behaviour for an
expired one.

**429 while testing**
Ten address generations per IP per hour. Clear it:
`mysql -u DB_USER -p DB_NAME -e "DELETE FROM rate_limits;"`

**PHP version mismatch**
The panel's site PHP and your CLI path must match. If the site runs 8.2, the
cron and the Postfix pipe must both use `/www/server/php/82/bin/php`.

---

## 17. Updating later

```bash
cd /www/wwwroot/mail.example.com
git pull

# new files arrive with default permissions — put them back
chown -R www:www .
find . -type d -exec chmod 750 {} \;
find . -type f -exec chmod 640 {} \;
chmod 2770 logs

sudo -u www /www/server/php/82/bin/php verify.php
```

`config.php` is gitignored, so your credentials and settings survive a pull.
