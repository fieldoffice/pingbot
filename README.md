# Pingbot

A lightweight self-hosted uptime monitoring service. Pings your sites every 5 minutes and sends email alerts when something goes down (and again when it recovers).

## File structure

```
pingbot/
├── index.html          ← Dashboard (the UI)
├── api/
│   ├── sites.php       ← REST API: list / add / delete sites
│   └── check.php       ← REST API: trigger a manual check
├── cron/
│   └── run.php         ← Cron script (runs every 5 min)
└── data/               ← Auto-created; writable by PHP
    ├── sites.json      ← Site store
    └── check.log       ← Rolling check log
```

## Deployment notes

### 1. Upload to your server

Drop the `pingbot/` folder anywhere inside `public_html` (or your web root).

### 2. Make the data/ directory writable

```bash
chmod 755 data/
# or, if PHP runs as a different user:
chown www-data:www-data data/
```

### 3. Set up the cron (cPanel)

Go to **cPanel → Cron Jobs** and add:

| Field   | Value                                                                                          |
|---------|-----------------------------------------------------------------------------------------------|
| Minute  | `*/5`                                                                                          |
| Command | `/usr/local/bin/php /home/<cpanel-user>/public_html/uptime-monitor/cron/run.php >> /dev/null 2>&1` |

Replace `<cpanel-user>` with your actual cPanel username.

> **Tip:** run `which php` via SSH to confirm the PHP binary path.

### 4. Linux crontab (non-cPanel)

```bash
crontab -e
# Add:
*/5 * * * * php /var/www/html/uptime-monitor/cron/run.php >> /dev/null 2>&1
```

### 5. (Optional) Protect the dashboard

Add an `.htaccess` to restrict access to `index.html`:

```apache
AuthType Basic
AuthName "Uptime Monitor"
AuthUserFile /home/<user>/.htpasswd
Require valid-user
```

---

## How alerts work

- **Down alert** fires once, on the **first failed check** after the site was up.  
  It does *not* repeat on every check while the site is down (no inbox spam).
- **Recovery alert** fires once when the site comes back up.
- Both emails are HTML with clear colour-coded styling.

To use an external SMTP service (Mailgun, SendGrid, etc.) instead of `mail()`, replace the `mail()` calls in `cron/run.php` and `api/check.php` with your preferred library (PHPMailer, SwiftMailer, etc.).

---

## Manual checks

The dashboard has a **Check all now** button and a per-site refresh button — these hit `api/check.php` directly, independent of the cron.

---

## Requirements

- PHP 8.0+ with `curl` extension
- A server with `mail()` configured (or swap in PHPMailer)
- Write permissions on `data/`
