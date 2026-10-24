<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', '0');

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
    'path' => '/',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');

$dbHost = getenv('DB_HOST') ?: '';
$dbUser = getenv('DB_USER') ?: '';
$dbPassword = getenv('DB_PASSWORD');
$dbName = getenv('DB_NAME') ?: '';
$baseUrl = rtrim((string) (getenv('APP_BASE_URL') ?: ''), '/');

$baseParts = $baseUrl !== '' ? parse_url($baseUrl) : false;
$baseScheme = is_array($baseParts) ? strtolower((string) ($baseParts['scheme'] ?? '')) : '';
if ($dbHost === '' || $dbUser === '' || $dbName === '' || $dbPassword === false || !in_array($baseScheme, ['http', 'https'], true) || empty($baseParts['host'])) {
    http_response_code(500);
    error_log('URL shortener is missing required DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, or APP_BASE_URL configuration.');
    exit('The URL shortener is not configured yet. Ask the site administrator to check the server environment settings.');
}

define('BASE_URL', $baseUrl);

try {
    $conn = new mysqli($dbHost, $dbUser, (string) $dbPassword, $dbName);
    $conn->set_charset('utf8mb4');
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('URL shortener database connection failed: ' . $exception->getMessage());
    exit('The link service is temporarily unavailable. Please try again later.');
}
