<?php
/* Web app manifest: lets phones install the portal with an "Add to Home Screen" icon. */
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$base = BASE_URL;
echo json_encode([
    'name'             => 'CSO-NORSU Portal',
    'short_name'       => 'CSO-NORSU',
    'description'      => 'Computer Science Organization portal: events, announcements, campus map and curriculum.',
    'start_url'        => $base . '/home.php',
    'scope'            => $base . '/',
    'display'          => 'standalone',
    'background_color' => '#E4EAE6',
    'theme_color'      => '#375534',
    'icons'            => [
        ['src' => $base . '/assets/icons/icon-192.png',          'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . '/assets/icons/icon-512.png',          'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $base . '/assets/icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
    'shortcuts'        => [
        ['name' => 'Campus Map',  'url' => $base . '/map.php'],
        ['name' => 'Curriculum',  'url' => $base . '/curriculum.php'],
        ['name' => 'Events',      'url' => $base . '/events.php'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
