<?php
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (in_array($requestPath, ['/favicon.png', '/style.css', '/app.js'], true)) {
    return false;
}
require __DIR__ . '/index.php';
