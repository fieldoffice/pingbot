<?php
/**
 * api/sites.php
 * GET    → list all sites
 * POST   → add a site   { name, url, email }
 * DELETE → remove a site { id }
 */

require_once __DIR__ . '/../config.php';
assertAllowedIp();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

define('DATA_FILE', __DIR__ . '/../data/sites.json');

// ── Helpers ──────────────────────────────────────────────────────────────────

function loadSites(): array {
    if (!file_exists(DATA_FILE)) return [];
    $raw = file_get_contents(DATA_FILE);
    return json_decode($raw, true) ?: [];
}

function saveSites(array $sites): void {
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
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

// ── Router ───────────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $sites = loadSites();
    respond(['sites' => array_values($sites)]);
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $name  = trim($body['name']  ?? '');
    $url   = trim($body['url']   ?? '');
    $email = trim($body['email'] ?? '');

    if (!$name)                  error('Name is required.');
    if (!filter_var($url, FILTER_VALIDATE_URL)) error('Invalid URL.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error('Invalid email.');

    // Normalise URL
    if (!str_starts_with($url, 'http')) error('URL must start with http:// or https://');

    $sites = loadSites();
    $id    = time() . '_' . rand(1000, 9999);

    $sites[$id] = [
        'id'            => $id,
        'name'          => htmlspecialchars($name, ENT_QUOTES),
        'url'           => $url,
        'email'         => $email,
        'status'        => 'unknown',
        'response_time' => null,
        'last_checked'  => null,
        'added_at'      => time(),
    ];

    saveSites($sites);
    respond(['success' => true, 'id' => $id], 201);
}

if ($method === 'DELETE') {
    $body = json_decode(file_get_contents('php://input'), true);
    $id   = $body['id'] ?? null;

    $sites = loadSites();
    if (!isset($sites[$id])) error('Site not found.', 404);

    unset($sites[$id]);
    saveSites($sites);
    respond(['success' => true]);
}

error('Method not allowed.', 405);
