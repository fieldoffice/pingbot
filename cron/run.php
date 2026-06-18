#!/usr/bin/env php
<?php
/**
 * cron/run.php
 *
 * Called by your server's cron every 5 minutes:
 *
 *   cPanel → Cron Jobs:
 *     *\/5 * * * *  /usr/local/bin/php /home/<cpanel-user>/public_html/uptime-monitor/cron/run.php
 *
 *   Or Linux crontab -e:
 *     *\/5 * * * *  php /var/www/html/uptime-monitor/cron/run.php >> /dev/null 2>&1
 *
 * This script is self-contained — it doesn't make an HTTP request to check.php,
 * so it works even when the web server is under load or the site itself is down.
 */

// ── Bootstrap ────────────────────────────────────────────────────────────────

define('ROOT',      dirname(__DIR__));
define('DATA_FILE', ROOT . '/data/sites.json');
define('LOG_FILE',  ROOT . '/data/check.log');

// Prevent multiple concurrent runs (simple lock file)
define('LOCK_FILE', ROOT . '/data/cron.lock');

if (file_exists(LOCK_FILE) && (time() - filemtime(LOCK_FILE)) < 240) {
    // A run started less than 4 min ago — bail to avoid overlap
    exit(0);
}
file_put_contents(LOCK_FILE, (string) time());

register_shutdown_function(function () {
    @unlink(LOCK_FILE);
});

// ── Helpers (duplicated to keep cron self-contained) ─────────────────────────

function loadSites(): array {
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(file_get_contents(DATA_FILE), true) ?: [];
}

function saveSites(array $sites): void {
    file_put_contents(DATA_FILE, json_encode($sites, JSON_PRETTY_PRINT));
}

function logLine(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] [cron] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    echo $line;   // visible in cron mail if configured
}

function checkSite(array &$site): void {
    $url   = $site['url'];
    $start = microtime(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'pingbot/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOBODY         => true,
    ]);

    curl_exec($ch);
    $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $responseTime = (int) round((microtime(true) - $start) * 1000);
    $curlError    = curl_error($ch);
    curl_close($ch);

    $isUp      = !$curlError && $httpCode >= 200 && $httpCode < 400;
    $prevStatus = $site['status'];

    $site['status']        = $isUp ? 'up' : 'down';
    $site['response_time'] = $isUp ? $responseTime : null;
    $site['last_checked']  = time();
    $site['http_code']     = $httpCode;

    logLine("{$url} → {$site['status']} (HTTP {$httpCode}, {$responseTime}ms)");

    if (!$isUp && $prevStatus !== 'down') {
        sendDownAlert($site, $httpCode, $curlError);
    }
    if ($isUp && $prevStatus === 'down') {
        sendRecoveryAlert($site, $responseTime);
    }
}

function sendDownAlert(array $site, int $httpCode, string $curlError): void {
    $to      = $site['email'];
    $name    = htmlspecialchars_decode($site['name']);
    $url     = $site['url'];
    $time    = date('Y-m-d H:i:s T');
    $error   = $curlError ?: "HTTP {$httpCode}";

    $subject = "🔴 DOWN: {$name} is offline";
    $message = "<!DOCTYPE html><html><body style='font-family:sans-serif;color:#1a1a1a;max-width:560px;margin:0 auto;padding:24px'>
<div style='border-left:4px solid #f85149;padding-left:16px;margin-bottom:24px'>
  <h2 style='margin:0 0 4px;color:#f85149'>Site offline: {$name}</h2>
  <p style='margin:0;color:#666;font-size:13px'>Detected at {$time}</p>
</div>
<table style='width:100%;border-collapse:collapse;font-size:14px'>
  <tr><td style='padding:8px 0;color:#666;width:130px'>URL</td><td><a href='{$url}'>{$url}</a></td></tr>
  <tr><td style='padding:8px 0;color:#666'>Reason</td><td style='color:#f85149'>{$error}</td></tr>
</table>
<p style='margin-top:24px;font-size:13px;color:#888'>You'll receive another email when {$name} comes back online.<br>— Pingbot</p>
</body></html>";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Pingbot <monitor@" . gethostname() . ">\r\n";

    if (mail($to, $subject, $message, $headers)) {
        logLine("Down alert → {$to} for {$url}");
    } else {
        logLine("FAILED to send alert → {$to} for {$url}");
    }
}

function sendRecoveryAlert(array $site, int $responseTime): void {
    $to      = $site['email'];
    $name    = htmlspecialchars_decode($site['name']);
    $url     = $site['url'];
    $time    = date('Y-m-d H:i:s T');

    $subject = "✅ RECOVERED: {$name} is back online";
    $message = "<!DOCTYPE html><html><body style='font-family:sans-serif;color:#1a1a1a;max-width:560px;margin:0 auto;padding:24px'>
<div style='border-left:4px solid #3fb950;padding-left:16px;margin-bottom:24px'>
  <h2 style='margin:0 0 4px;color:#3fb950'>Site recovered: {$name}</h2>
  <p style='margin:0;color:#666;font-size:13px'>At {$time}</p>
</div>
<table style='width:100%;border-collapse:collapse;font-size:14px'>
  <tr><td style='padding:8px 0;color:#666;width:130px'>URL</td><td><a href='{$url}'>{$url}</a></td></tr>
  <tr><td style='padding:8px 0;color:#666'>Response time</td><td style='color:#3fb950'>{$responseTime}ms</td></tr>
</table>
<p style='margin-top:24px;font-size:13px;color:#888'>— Pingbot</p>
</body></html>";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Pingbot <monitor@" . gethostname() . ">\r\n";

    mail($to, $subject, $message, $headers);
    logLine("Recovery email → {$to} for {$url}");
}

// ── Run ───────────────────────────────────────────────────────────────────────

logLine('Cron started — checking ' . count(loadSites()) . ' site(s)');

$sites = loadSites();
if (empty($sites)) {
    logLine('No sites configured.');
    exit(0);
}

foreach ($sites as &$site) {
    checkSite($site);
}

saveSites($sites);
logLine('Done.');
