<?php

declare(strict_types=1);

// Minimal landing page for the ATFM toolset on IONOS Web Hosting.
// This is the only entry point exposed to the public; everything
// meaningful lives under src/ and is autoloaded via Composer.

require __DIR__ . '/../vendor/autoload.php';

$appName = 'atfm-tools';
$version = trim((string) @file_get_contents(__DIR__ . '/../VERSION')) ?: 'dev';

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($appName) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 720px; margin: 3rem auto; padding: 0 1rem; color: #1a1a1a; }
        code { background: #f2f2f2; padding: 0.1rem 0.3rem; border-radius: 3px; }
        h1 { margin-bottom: 0.25rem; }
        .muted { color: #666; }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($appName) ?></h1>
    <p class="muted">version <?= htmlspecialchars($version) ?></p>
    <p>ATFM toolset scaffold is live. Replace this page with your dashboard.</p>
    <ul>
        <li><a href="/map.html">FIR map</a> (once you add it)</li>
        <li><a href="/api/health">Health check</a></li>
    </ul>
</body>
</html>
