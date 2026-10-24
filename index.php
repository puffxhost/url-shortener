<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function makeShortCode(): string
{
    return bin2hex(random_bytes(4));
}

if (empty($_SESSION['owner_token'])) {
    $_SESSION['owner_token'] = bin2hex(random_bytes(32));
}
$ownerHash = hash('sha256', (string) $_SESSION['owner_token']);
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['csrf_token'];

$error = '';
$createdUrl = '';
$inputUrl = '';
$customCode = '';
$expiryDays = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputUrl = isset($_POST['long_url']) && is_string($_POST['long_url']) ? trim($_POST['long_url']) : '';
    $customCode = isset($_POST['custom_code']) && is_string($_POST['custom_code']) ? trim($_POST['custom_code']) : '';
    $expiryDays = isset($_POST['expiry_days']) && is_scalar($_POST['expiry_days']) ? (int) $_POST['expiry_days'] : 0;
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = 'Your form session expired. Refresh this page and try again.';
    } elseif (strlen($inputUrl) > 500 || !filter_var($inputUrl, FILTER_VALIDATE_URL)) {
        $error = 'Enter a valid URL no longer than 500 characters.';
    } else {
        $parts = parse_url($inputUrl);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            $error = 'Only public HTTP or HTTPS links are accepted. URLs containing embedded credentials are not allowed.';
        } elseif ($customCode !== '' && !preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $customCode)) {
            $error = 'A custom code can use 1–32 letters, numbers, hyphens, or underscores.';
        } elseif ($customCode !== '' && in_array(strtolower($customCode), ['assets', 'index.php', 'redirect.php', 'config.php'], true)) {
            $error = 'That custom code is reserved. Please choose another one.';
        } elseif (!in_array($expiryDays, [0, 1, 7, 30, 90], true)) {
            $error = 'Choose one of the listed expiration options.';
        } else {
            $limit = $conn->prepare("SELECT COUNT(*) FROM short_urls WHERE owner_token_hash = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $limit->bind_param('s', $ownerHash);
            $limit->execute();
            $limit->bind_result($recentCount);
            $limit->fetch();
            $limit->close();

            if ((int) $recentCount >= 20) {
                http_response_code(429);
                $error = 'This browser has created 20 links in the last hour. Please try again later.';
            } else {
                $shortCode = $customCode !== '' ? $customCode : makeShortCode();
                $expiresAt = $expiryDays > 0 ? gmdate('Y-m-d H:i:s', time() + ($expiryDays * 86400)) : null;
                $insert = $conn->prepare('INSERT INTO short_urls (long_url, short_code, expires_at, owner_token_hash) VALUES (?, ?, ?, ?)');
                $insert->bind_param('ssss', $inputUrl, $shortCode, $expiresAt, $ownerHash);

                try {
                    $insert->execute();
                    $createdUrl = BASE_URL . '/' . $shortCode;
                    $customCode = '';
                    $inputUrl = '';
                } catch (mysqli_sql_exception $exception) {
                    if ((int) $exception->getCode() === 1062) {
                        $error = $customCode !== ''
                            ? 'That custom code is already in use. Please choose a different one.'
                            : 'A short-code collision occurred. Please submit again.';
                    } else {
                        error_log('URL shortener insert failed: ' . $exception->getMessage());
                        http_response_code(500);
                        $error = 'The link could not be saved. Please try again later.';
                    }
                } finally {
                    $insert->close();
                }
            }
        }
    }
}

$links = [];
$list = $conn->prepare('SELECT short_code, long_url, created_at, expires_at, click_count FROM short_urls WHERE owner_token_hash = ? ORDER BY created_at DESC LIMIT 10');
$list->bind_param('s', $ownerHash);
$list->execute();
$list->bind_result($rowCode, $rowUrl, $rowCreated, $rowExpires, $rowClicks);
while ($list->fetch()) {
    $links[] = [
        'code' => (string) $rowCode,
        'url' => (string) $rowUrl,
        'created' => (string) $rowCreated,
        'expires' => $rowExpires === null ? null : (string) $rowExpires,
        'clicks' => (int) $rowClicks,
    ];
}
$list->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#101525">
  <title>Puffx URL Shortener</title>
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
  <header class="hero">
    <div class="eyebrow">Fast · Simple · Yours</div>
    <h1>Make long links easy to share</h1>
    <p>Create a short link with an optional custom code and expiry. Your recent links and click counts are visible only in this browser session.</p>
  </header>

  <section class="panel" aria-labelledby="create-title">
    <h2 id="create-title">Create a short link</h2>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($createdUrl !== ''): ?>
      <div class="notice" role="status">
        <strong>Your link is ready</strong>
        <div class="result-url" id="createdUrl"><?= e($createdUrl) ?></div>
        <div class="actions">
          <button class="btn" type="button" id="copyButton">Copy link</button>
          <button class="btn secondary" type="button" id="shareButton">Share</button>
          <span class="small" id="copyStatus" aria-live="polite"></span>
        </div>
      </div>
    <?php endif; ?>
    <form method="post" action="" autocomplete="on">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <label for="long_url">Destination URL</label>
      <input type="url" name="long_url" id="long_url" maxlength="500" placeholder="https://example.com/page" value="<?= e($inputUrl) ?>" required>
      <p class="help">Only HTTP and HTTPS destinations are accepted.</p>
      <div class="row">
        <div>
          <label for="custom_code">Custom short code <span class="small">(optional)</span></label>
          <input type="text" name="custom_code" id="custom_code" maxlength="32" pattern="[A-Za-z0-9_-]{1,32}" placeholder="my-link" value="<?= e($customCode) ?>" autocomplete="off">
          <p class="help">1–32 letters, numbers, hyphens, or underscores.</p>
        </div>
        <div>
          <label for="expiry_days">Link expiry</label>
          <select name="expiry_days" id="expiry_days">
            <option value="0"<?= $expiryDays === 0 ? ' selected' : '' ?>>Never expires</option>
            <option value="1"<?= $expiryDays === 1 ? ' selected' : '' ?>>1 day</option>
            <option value="7"<?= $expiryDays === 7 ? ' selected' : '' ?>>7 days</option>
            <option value="30"<?= $expiryDays === 30 ? ' selected' : '' ?>>30 days</option>
            <option value="90"<?= $expiryDays === 90 ? ' selected' : '' ?>>90 days</option>
          </select>
        </div>
      </div>
      <button class="btn submit" type="submit">Create short link <span aria-hidden="true">→</span></button>
    </form>
  </section>

  <section class="panel" aria-labelledby="recent-title">
    <div class="links-head"><h2 id="recent-title">Recent links</h2><span class="small">Last 10 from this browser</span></div>
    <?php if (!$links): ?>
      <div class="empty">No links yet. Your first one will appear here.</div>
    <?php else: ?>
      <?php foreach ($links as $link):
        $short = BASE_URL . '/' . $link['code'];
        $expired = $link['expires'] !== null && strtotime($link['expires'] . ' UTC') <= time();
      ?>
        <article class="link-card">
          <div class="short"><a href="<?= e($short) ?>"><?= e($short) ?></a></div>
          <div class="destination"><?= e($link['url']) ?></div>
          <div class="meta">
            <span class="badge"><?= $link['clicks'] ?> <?= $link['clicks'] === 1 ? 'click' : 'clicks' ?></span>
            <span>Created <?= e(date('d M Y', strtotime($link['created']))) ?></span>
            <span><?= $link['expires'] === null ? 'No expiry' : ($expired ? 'Expired' : 'Expires ' . e(date('d M Y', strtotime($link['expires'] . ' UTC')))) ?></span>
          </div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <footer>Built with ❤️ by <a href="https://puffxhost.com">Puffx Host</a> · Links are stored in your MySQL database.</footer>
</main>
<script>
(() => {
  const urlElement = document.getElementById('createdUrl');
  const copyButton = document.getElementById('copyButton');
  const shareButton = document.getElementById('shareButton');
  const status = document.getElementById('copyStatus');
  if (!urlElement) return;
  const value = urlElement.textContent.trim();
  copyButton?.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(value);
      status.textContent = 'Copied to clipboard.';
    } catch (_) {
      const field = document.createElement('textarea');
      field.value = value;
      field.setAttribute('readonly', '');
      field.style.position = 'fixed';
      field.style.opacity = '0';
      document.body.appendChild(field);
      field.select();
      const copied = document.execCommand('copy');
      field.remove();
      status.textContent = copied ? 'Copied to clipboard.' : 'Copy failed; select the link to copy it.';
    }
  });
  shareButton?.addEventListener('click', async () => {
    if (navigator.share) {
      try { await navigator.share({ title: 'Short link', url: value }); }
      catch (error) { if (error.name !== 'AbortError') status.textContent = 'Sharing is not available on this device.'; }
    } else {
      status.textContent = 'Use Copy link to share this URL.';
    }
  });
})();
</script>
</body>
</html>
