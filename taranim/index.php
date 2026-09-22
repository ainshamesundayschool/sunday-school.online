<?php
// Entry point for Apache / Nginx / PHP Web Hosts
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (strpos($uri, '/api/') !== false) {
    require __DIR__ . '/api.php';
    exit;
}

if (strpos($uri, 'install.html') !== false && file_exists(__DIR__ . '/install.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/install.html');
    exit;
}

if (strpos($uri, 'manifest.webmanifest') !== false && file_exists(__DIR__ . '/manifest.webmanifest')) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    readfile(__DIR__ . '/manifest.webmanifest');
    exit;
}

if ((strpos($uri, 'present.html') !== false || strpos($uri, 'obs.html') !== false) && file_exists(__DIR__ . '/present.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/present.html');
    exit;
}

if (strpos($uri, 'sw.js') !== false && file_exists(__DIR__ . '/sw.js')) {
    header('Content-Type: application/javascript; charset=utf-8');
    readfile(__DIR__ . '/sw.js');
    exit;
}

if (strpos($uri, 'style.css') !== false && file_exists(__DIR__ . '/style.css')) {
    header('Content-Type: text/css; charset=utf-8');
    readfile(__DIR__ . '/style.css');
    exit;
}

if (strpos($uri, 'app.js') !== false && file_exists(__DIR__ . '/app.js')) {
    header('Content-Type: application/javascript; charset=utf-8');
    readfile(__DIR__ . '/app.js');
    exit;
}

if (strpos($uri, 'logo.png') !== false && file_exists(__DIR__ . '/logo.png')) {
    header('Content-Type: image/png');
    readfile(__DIR__ . '/logo.png');
    exit;
}

// Default Index Page
if (file_exists(__DIR__ . '/index.html')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/index.html');
    exit;
}
