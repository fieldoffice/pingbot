<?php
/**
 * config.php
 *
 * Shared configuration. Included by every PHP file in the project.
 * Edit ALLOWED_IPS to control who can access the dashboard and API.
 */

// ── Access control ────────────────────────────────────────────────────────────
// Add your IP address(es) here. Both IPv4 and IPv6 are supported.
// Find your IP at: https://whatismyipaddress.com
define('ALLOWED_IPS', [
    '0.0.0.0',   // ← replace with your IP
]);

// ── IP check ─────────────────────────────────────────────────────────────────
function assertAllowedIp(): void {
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '';

    // X-Forwarded-For can be a comma-separated list; take the first (client) IP
    $ip = trim(explode(',', $ip)[0]);

    if (!in_array($ip, ALLOWED_IPS, true)) {
        http_response_code(403);
        // Generic response — don't reveal that this endpoint exists
        echo '403 Forbidden';
        exit;
    }
}
