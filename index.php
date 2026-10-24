<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function makeShortCode(): string
{
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function isAllowedDestination(string $url): bool
{
    if ($url === '' || strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    return in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        && !empty($parts['host'])
        && !isset($parts['user'])
        && !isset($parts['pass']);
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
$successMessage = '';
$createdUrl = '';
$inputUrl = '';
$customCode = '';
$expiryDays = 0;
$action = 'create';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : 'create';
    $submittedToken = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';

    if (!hash_equals($csrfToken, $submittedToken)) {
        http_response_code(400);
        $error = 'Your form session expired. Refresh this page and try again.';
    } elseif ($action === 'update_destination') {
        $code = isset($_POST['code']) && is_string($_POST['code']) ? trim($_POST['code']) : '';
        $newUrl = isset($_POST['new_url']) && is_string($_POST['new_url']) ? trim($_POST['new_url']) : '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $code) || !isAllowedDestination($newUrl)) {
            $error = 'Enter a valid HTTP or HTTPS destination (up to 500 characters).';
        } else {
            $update = $conn->prepare('UPDATE short_urls SET long_url = ? WHERE short_code = ? AND owner_token_hash = ?');
            $update->bind_param('sss', $newUrl, $code, $ownerHash);
            $update->execute();
            $changed = $update->affected_rows > 0;
            $update->close();

            $check = $conn->prepare('SELECT long_url FROM short_urls WHERE short_code = ? AND owner_token_hash = ? LIMIT 1');
            $check->bind_param('ss', $code, $ownerHash);
            $check->execute();
            $check->bind_result($savedUrl);
            $owned = $check->fetch();
            $check->close();

            if (!$owned) {
                http_response_code(404);
                $error = 'This link was not found in your browser session.';
            } else {
                $successMessage = $changed ? 'Destination updated. The short link stays the same.' : 'Destination is already up to date.';
            }
        }
    } elseif ($action !== 'create') {
        http_response_code(400);
        $error = 'Unknown form action. Refresh the page and try again.';
    } else {
        $inputUrl = isset($_POST['long_url']) && is_string($_POST['long_url']) ? trim($_POST['long_url']) : '';
        $customCode = isset($_POST['custom_code']) && is_string($_POST['custom_code']) ? trim($_POST['custom_code']) : '';
        $expiryDays = isset($_POST['expiry_days']) && is_scalar($_POST['expiry_days']) ? (int) $_POST['expiry_days'] : 0;

        if (!isAllowedDestination($inputUrl)) {
            $error = 'Enter a valid HTTP or HTTPS URL no longer than 500 characters. Embedded credentials are not allowed.';
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

$stats = ['total' => 0, 'clicks' => 0, 'active' => 0];
$summary = $conn->prepare('SELECT COUNT(*), COALESCE(SUM(click_count), 0), COALESCE(SUM(CASE WHEN expires_at IS NULL OR expires_at > UTC_TIMESTAMP() THEN 1 ELSE 0 END), 0) FROM short_urls WHERE owner_token_hash = ?');
$summary->bind_param('s', $ownerHash);
$summary->execute();
$summary->bind_result($totalLinks, $totalClicks, $activeLinks);
if ($summary->fetch()) {
    $stats = ['total' => (int) $totalLinks, 'clicks' => (int) $totalClicks, 'active' => (int) $activeLinks];
}
$summary->close();

$links = [];
$list = $conn->prepare('SELECT short_code, long_url, created_at, expires_at, click_count FROM short_urls WHERE owner_token_hash = ? ORDER BY created_at DESC LIMIT 50');
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
    <p>Create, organize and track your short links. Your dashboard is tied to this browser session—not a user account.</p>
  </header>

  <section class="panel" aria-labelledby="create-title">
    <h2 id="create-title">Create a short link</h2>
    <?php if ($error !== ''): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <?php if ($successMessage !== ''): ?><div class="notice" role="status"><?= e($successMessage) ?></div><?php endif; ?>
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
      <input type="hidden" name="action" value="create">
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
    <div class="links-head">
      <div><h2 id="recent-title">Your link dashboard</h2><span class="small">Latest 50 links from this browser</span></div>
      <?php if ($links): ?><button class="btn secondary" type="button" id="exportCsv">Export CSV</button><?php endif; ?>
    </div>
    <div class="stats" aria-label="Link statistics">
      <div class="stat"><span>Total links</span><strong><?= $stats['total'] ?></strong></div>
      <div class="stat"><span>Active links</span><strong><?= $stats['active'] ?></strong></div>
      <div class="stat"><span>Total clicks</span><strong><?= $stats['clicks'] ?></strong></div>
    </div>
    <?php if (!$links): ?>
      <div class="empty">No links yet. Create your first link and it will appear here.</div>
    <?php else: ?>
      <div class="toolbar">
        <label class="search-label" for="linkSearch">Search links</label>
        <input type="search" id="linkSearch" placeholder="Search URL or short code">
        <label class="filter-label" for="statusFilter">Show</label>
        <select id="statusFilter"><option value="all">All links</option><option value="active">Active</option><option value="expired">Expired</option></select>
        <span class="small" id="visibleCount" aria-live="polite"></span>
      </div>
      <div id="linkList">
      <?php foreach ($links as $link):
        $short = BASE_URL . '/' . $link['code'];
        $expired = $link['expires'] !== null && strtotime($link['expires'] . ' UTC') <= time();
        $expiryLabel = $link['expires'] === null ? 'No expiry' : ($expired ? 'Expired' : 'Expires ' . date('d M Y', strtotime($link['expires'] . ' UTC')));
      ?>
        <article class="link-card" data-status="<?= $expired ? 'expired' : 'active' ?>" data-code="<?= e($link['code']) ?>" data-url="<?= e($link['url']) ?>" data-short="<?= e($short) ?>" data-clicks="<?= $link['clicks'] ?>" data-created="<?= e($link['created']) ?>" data-expires="<?= e($link['expires'] ?? '') ?>">
          <div class="card-top">
            <div class="short"><a href="<?= e($short) ?>"><?= e($short) ?></a></div>
            <span class="badge <?= $expired ? 'badge-expired' : 'badge-active' ?>"><?= $expired ? 'Expired' : 'Active' ?></span>
          </div>
          <div class="destination"><?= e($link['url']) ?></div>
          <div class="meta">
            <span class="badge"><?= $link['clicks'] ?> <?= $link['clicks'] === 1 ? 'click' : 'clicks' ?></span>
            <span>Created <?= e(date('d M Y', strtotime($link['created']))) ?></span>
            <span><?= e($expiryLabel) ?></span>
          </div>
          <div class="card-actions">
            <button class="btn secondary copy-link" type="button">Copy</button>
            <button class="btn secondary share-link" type="button">Share</button>
          </div>
          <details class="edit-link">
            <summary>Edit destination</summary>
            <form method="post" action="">
              <input type="hidden" name="action" value="update_destination">
              <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
              <input type="hidden" name="code" value="<?= e($link['code']) ?>">
              <label for="url-<?= e($link['code']) ?>">New destination URL</label>
              <input type="url" id="url-<?= e($link['code']) ?>" name="new_url" maxlength="500" value="<?= e($link['url']) ?>" required>
              <button class="btn" type="submit">Save destination</button>
            </form>
          </details>
        </article>
      <?php endforeach; ?>
      </div>
      <div class="empty" id="noMatches" hidden>No links match this search and filter.</div>
    <?php endif; ?>
  </section>

  <footer>Built with ❤️ by <a href="https://puffxhost.com">Puffx Host</a> · Links are stored in your MySQL database.</footer>
</main>
<script>
(() => {
  const copyText = async (value) => {
    try {
      await navigator.clipboard.writeText(value);
      return true;
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
      return copied;
    }
  };

  const urlElement = document.getElementById('createdUrl');
  const copyButton = document.getElementById('copyButton');
  const shareButton = document.getElementById('shareButton');
  const status = document.getElementById('copyStatus');
  if (urlElement) {
    const value = urlElement.textContent.trim();
    copyButton?.addEventListener('click', async () => {
      status.textContent = await copyText(value) ? 'Copied to clipboard.' : 'Copy failed; select the link to copy it.';
    });
    shareButton?.addEventListener('click', async () => {
      if (navigator.share) {
        try { await navigator.share({ title: 'Short link', url: value }); }
        catch (error) { if (error.name !== 'AbortError') status.textContent = 'Sharing is not available on this device.'; }
      } else {
        status.textContent = 'Use Copy link to share this URL.';
      }
    });
  }

  document.querySelectorAll('.link-card').forEach(card => {
    const cardStatus = card.querySelector('.card-actions');
    card.querySelector('.copy-link')?.addEventListener('click', async () => {
      const ok = await copyText(card.dataset.short);
      cardStatus.dataset.message = ok ? 'Short link copied.' : 'Copy failed; select the link to copy it.';
    });
    card.querySelector('.share-link')?.addEventListener('click', async () => {
      if (navigator.share) {
        try { await navigator.share({ title: 'Short link', url: card.dataset.short }); }
        catch (error) { if (error.name !== 'AbortError') cardStatus.dataset.message = 'Sharing is not available on this device.'; }
      } else {
        cardStatus.dataset.message = 'Use Copy to share this link.';
      }
    });
  });

  const search = document.getElementById('linkSearch');
  const filter = document.getElementById('statusFilter');
  const list = document.getElementById('linkList');
  const count = document.getElementById('visibleCount');
  const noMatches = document.getElementById('noMatches');
  const cards = [...document.querySelectorAll('.link-card')];
  function applyFilters() {
    const term = (search?.value || '').trim().toLocaleLowerCase();
    const state = filter?.value || 'all';
    let visible = 0;
    cards.forEach(card => {
      const matchesText = (card.dataset.code + ' ' + card.dataset.url + ' ' + card.dataset.short).toLocaleLowerCase().includes(term);
      const matchesState = state === 'all' || card.dataset.status === state;
      const show = matchesText && matchesState;
      card.hidden = !show;
      if (show) visible++;
    });
    if (count) count.textContent = visible + ' of ' + cards.length + ' shown';
    if (noMatches) noMatches.hidden = visible !== 0;
    if (list) list.hidden = cards.length > 0 && visible === 0;
  }
  search?.addEventListener('input', applyFilters);
  filter?.addEventListener('change', applyFilters);
  applyFilters();

  document.getElementById('exportCsv')?.addEventListener('click', () => {
    const visibleCards = cards.filter(card => !card.hidden);
    const safeCell = value => {
      let text = String(value ?? '');
      if (/^[\s]*[=+\-@\t\r]/.test(text)) text = "'" + text;
      return '"' + text.replaceAll('"', '""') + '"';
    };
    const rows = [
      ['Short URL', 'Destination URL', 'Clicks', 'Created', 'Expires', 'Status'],
      ...visibleCards.map(card => [card.dataset.short, card.dataset.url, card.dataset.clicks, card.dataset.created, card.dataset.expires || 'Never', card.dataset.status])
    ];
    const csv = rows.map(row => row.map(safeCell).join(',')).join('\r\n');
    const blob = new Blob([new Uint8Array([0xEF, 0xBB, 0xBF]), csv], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'puffx-links-' + new Date().toISOString().slice(0, 10) + '.csv';
    link.click();
    URL.revokeObjectURL(link.href);
  });
})();
</script>
</body>
</html>
