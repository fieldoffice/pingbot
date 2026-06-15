<?php
/**
 * index.php
 *
 * IP-gated entry point for the dashboard.
 * Rename or remove index.html and use this as the default document,
 * or configure your server to serve this first.
 *
 * Apache: set DirectoryIndex index.php in .htaccess (already done if you
 *         copied the provided .htaccess).
 */

require_once __DIR__ . '/config.php';
assertAllowedIp();

// Serve the dashboard HTML
readfile(__DIR__ . '/index.html');
