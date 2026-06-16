<?php
/**
 * api/check.php
 *
 * Manual check (called from JS):
 *   GET ?id=<site_id>   → check one site
 *   GET ?all=1          → check all sites
 *
 * Also called directly by the cron script (cron/run.php includes this logic).
 */

require_once __DIR__ . '/../config.php';
assertAllowedIp();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

define('DATA_FILE', __DIR__ . '/../data/sites.json');
define('LOG_FILE',  __DIR__ . '/../data/check.log');

// ── Helpers ──────────────────────────────────────────────────────────────────

function loadSites(): array {
    if (!file_exists(DATA_FILE)) return [];
    return json_decode(file_get_contents(DATA_FILE), true) ?: [];
}

function saveSites(array $sites): void {
    file_put_contents(DATA_FILE, json_encode($sites, JSON_PRETTY_PRINT));
}

function respond(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function error(string $msg, int $code = 400): never {
    respond(['error' => $msg], $code);
}

function logLine(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ── Core check logic ─────────────────────────────────────────────────────────

function checkSite(array &$site): array {
    $url   = $site['url'];
    $start = microtime(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,          // 15-second timeout
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT      => 'pingbot/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOBODY         => true,        // HEAD request — faster
    ]);

    curl_exec($ch);
    $httpCode     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $responseTime = (int) round((microtime(true) - $start) * 1000);
    $curlError    = curl_error($ch);
    curl_close($ch);

    // Consider 2xx and 3xx as "up"
    $isUp = !$curlError && $httpCode >= 200 && $httpCode < 400;

    $prevStatus   = $site['status'];
    $site['status']        = $isUp ? 'up' : 'down';
    $site['response_time'] = $isUp ? $responseTime : null;
    $site['last_checked']  = time();
    $site['http_code']     = $httpCode;

    $result = [
        'id'            => $site['id'],
        'url'           => $url,
        'status'        => $site['status'],
        'response_time' => $site['response_time'],
        'http_code'     => $httpCode,
    ];

    logLine("{$url} → {$site['status']} (HTTP {$httpCode}, {$responseTime}ms)");

    // Send alert only when status transitions to 'down'
    // (avoid spamming on every check while already down)
    if (!$isUp && $prevStatus !== 'down') {
        sendDownAlert($site, $httpCode, $curlError);
    }

    // Optional: send recovery notice when site comes back up
    if ($isUp && $prevStatus === 'down') {
        sendRecoveryAlert($site, $responseTime);
    }

    return $result;
}

// ── Email alerts ─────────────────────────────────────────────────────────────

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
<p style='margin-top:24px;font-size:13px;color:#888'>You'll get another email when {$name} comes back online.<br>— Pingbot</p>
</body></html>";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Pingbot <monitor@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";

    if (mail($to, $subject, $message, $headers)) {
        logLine("Alert sent to {$to} for {$url}");
    } else {
        logLine("FAILED to send alert to {$to} for {$url}");
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
  <p style='margin:0;color:#666;font-size:13px'>Detected at {$time}</p>
</div>
<table style='width:100%;border-collapse:collapse;font-size:14px'>
  <tr><td style='padding:8px 0;color:#666;width:130px'>URL</td><td><a href='{$url}'>{$url}</a></td></tr>
  <tr><td style='padding:8px 0;color:#666'>Response time</td><td style='color:#3fb950'>{$responseTime}ms</td></tr>
</table>
<p style='margin-top:24px;font-size:13px;color:#888'>— Pingbot</p>
</body></html>";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Pingbot <monitor@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";

    mail($to, $subject, $message, $headers);
    logLine("Recovery email sent to {$to} for {$url}");
}

// ── Router ───────────────────────────────────────────────────────────────────

$sites = loadSites();

if (isset($_GET['all'])) {
    $results = [];
    foreach ($sites as &$site) {
        $results[] = checkSite($site);
    }
    saveSites($sites);
    respond(['results' => $results]);
}

if (isset($_GET['id'])) {
    $id = $_GET['id'];
    if (!isset($sites[$id])) error('Site not found.', 404);
    $result = checkSite($sites[$id]);
    saveSites($sites);
    respond($result);
}

error('Specify ?id=<id> or ?all=1');
