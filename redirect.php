<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$code = isset($_GET['code']) && is_string($_GET['code']) ? trim($_GET['code']) : '';
if ($code === '' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $code)) {
    http_response_code(404);
    exit('Short link not found.');
}

$stmt = $conn->prepare('SELECT long_url, expires_at FROM short_urls WHERE short_code = ? LIMIT 1');
$stmt->bind_param('s', $code);
$stmt->execute();
$stmt->bind_result($destination, $expiresAt);
$found = $stmt->fetch();
$stmt->close();

if (!$found) {
    http_response_code(404);
    exit('Short link not found.');
}

if ($expiresAt !== null && strtotime((string) $expiresAt . ' UTC') <= time()) {
    http_response_code(410);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Link expired</title><body style="font:16px system-ui;max-width:640px;margin:12vh auto;padding:24px;color:#20283a"><h1>This short link has expired</h1><p>Ask the person who shared it for a new link.</p></body></html>';
    exit;
}

$parts = parse_url((string) $destination);
$scheme = strtolower((string) ($parts['scheme'] ?? ''));
if (!filter_var($destination, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
    http_response_code(410);
    error_log('URL shortener blocked an invalid stored destination for code ' . $code);
    exit('This short link has an invalid destination.');
}

try {
    $update = $conn->prepare('UPDATE short_urls SET click_count = click_count + 1 WHERE short_code = ?');
    $update->bind_param('s', $code);
    $update->execute();
    $update->close();
} catch (Throwable $exception) {
    error_log('URL shortener click counter update failed: ' . $exception->getMessage());
}

header('Location: ' . $destination, true, 302);
exit;
