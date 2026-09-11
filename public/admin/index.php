<?php
/**
 * Admin panel: health, settings, inboxes and maintenance tools.
 *
 * Access is a single password, hashed into config.php. Everything here is
 * server-rendered; the only JavaScript is a copy button.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Helpers.php';
require_once dirname(__DIR__, 2) . '/src/Admin.php';
require_once dirname(__DIR__, 2) . '/src/Settings.php';
require_once dirname(__DIR__, 2) . '/src/Csrf.php';
require_once dirname(__DIR__, 2) . '/src/RateLimiter.php';

start_session();

header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data:; "
    . "frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'"
);
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

ini_set('display_errors', '0');
set_exception_handler(static function (Throwable $e): void {
    log_error('admin: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><meta charset="utf-8"><title>Admin error</title>'
        . '<link rel="stylesheet" href="' . h(asset_url('style.css', '../assets/')) . '">'
        . '<main><p class="flash flash-error">The admin panel hit an error. '
        . 'Details are in logs/app.log.</p></main>';
});

function admin_self(): string
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/admin/', '?');
    $path = preg_replace('#[^A-Za-z0-9._/\-]#', '', (string) $path);
    return $path === '' ? '/admin/' : $path;
}

function admin_redirect(string $query = ''): never
{
    header('Location: ' . admin_self() . ($query === '' ? '' : '?' . $query), true, 303);
    exit;
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

function admin_flash(string $type, string $message): void
{
    $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
}

$settingErrors = [];

// ------------------------------------------------------------- actions -----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        if (!Csrf::validate(Csrf::fromRequest())) {
            admin_flash('error', 'Session expired — try again.');
            admin_redirect();
        }

        $limiter = RateLimiter::forCurrentRequest();
        if (!$limiter->check('admin_login', 10, 900)) {
            admin_flash('error', 'Too many attempts. Wait ' . (int) ceil($limiter->remainingSeconds() / 60) . ' minute(s).');
            admin_redirect();
        }

        if (Admin::login((string) ($_POST['password'] ?? ''))) {
            admin_flash('ok', 'Signed in.');
            admin_redirect();
        }

        log_error('admin: failed login from ' . client_ip());
        admin_flash('error', 'Wrong password.');
        admin_redirect();
    }

    // Everything below needs a session.
    if (!Admin::isAuthed() || !Csrf::validate(Csrf::fromRequest())) {
        admin_flash('error', 'Please sign in again.');
        admin_redirect();
    }

    switch ($action) {
        case 'logout':
            Admin::logout();
            admin_flash('ok', 'Signed out.');
            admin_redirect();

        case 'save_settings':
            $settingErrors = Settings::put($_POST);
            if ($settingErrors === []) {
                admin_flash('ok', 'Settings saved.');
                admin_redirect('tab=settings');
            }
            $_SESSION['admin_setting_errors'] = $settingErrors;
            admin_flash('error', 'Nothing was saved — check the fields below.');
            admin_redirect('tab=settings');

        case 'run_cleanup':
            $removed = Cleanup::run();
            admin_flash('ok', sprintf(
                'Cleanup done: %d emails, %d inboxes, %d rate-limit rows removed.',
                $removed['emails'],
                $removed['inboxes'],
                $removed['rate_limits']
            ));
            admin_redirect('tab=tools');

        case 'clear_rate_limits':
            $gone = Cleanup::clearRateLimits();
            admin_flash('ok', $gone . ' rate-limit row(s) cleared.');
            admin_redirect('tab=tools');
    }

    admin_redirect();
}

$settingErrors = $_SESSION['admin_setting_errors'] ?? [];
unset($_SESSION['admin_setting_errors']);

$csrf      = Csrf::token();
$tab       = (string) ($_GET['tab'] ?? 'overview');
$validTabs = ['overview', 'settings', 'inboxes', 'tools'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'overview';
}

$siteName = 'TempMail';
try {
    $siteName = (string) Settings::get('site_name');
} catch (Throwable $e) {
    // Database down: the health page below will say so.
}

/** Small helper for the status pills. */
function pill(string $status): string
{
    $labels = ['pass' => 'OK', 'warn' => 'Check', 'fail' => 'Problem'];
    return '<span class="pill pill-' . h($status) . '">' . h($labels[$status] ?? $status) . '</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin — <?= h($siteName) ?></title>
<link rel="stylesheet" href="<?= h(asset_url('style.css', '../assets/')) ?>">
<link rel="stylesheet" href="<?= h(asset_url('admin.css', '../assets/')) ?>">
</head>
<body class="admin">

<header class="topbar">
  <span class="brand"><span class="brand-mark" aria-hidden="true">✉</span><?= h($siteName) ?> <span class="brand-tag">admin</span></span>
  <?php if (Admin::isAuthed()): ?>
    <span class="topbar-actions">
      <a class="btn subtle" href="../">View site</a>
      <form method="post" class="inline-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="logout">
        <button type="submit" class="btn">Sign out</button>
      </form>
    </span>
  <?php endif; ?>
</header>

<main class="admin-main">

<?php if ($flash !== null): ?>
  <p class="flash flash-<?= h((string) $flash['type']) ?>" role="status"><?= h((string) $flash['message']) ?></p>
<?php endif; ?>

<?php if (!Admin::isConfigured()): ?>

  <section class="card">
    <h1 class="card-title">Set an admin password</h1>
    <p class="lede">The admin panel is closed until <code>ADMIN_PASSWORD_HASH</code> is set in
      <code>config.php</code>. Generate a hash on the server:</p>
    <pre class="fix"><?= h('php -r \'echo password_hash("YOUR-PASSWORD", PASSWORD_DEFAULT), PHP_EOL;\'') ?></pre>
    <p class="lede">Then add the line it prints to <code><?= h(dirname(__DIR__, 2) . '/config.php') ?></code>:</p>
    <pre class="fix"><?= h("const ADMIN_PASSWORD_HASH = '\$2y\$10\$...the hash it printed...';") ?></pre>
    <p class="hint">Paste the hash, never the password itself. Reload this page afterwards.</p>
  </section>

<?php elseif (!Admin::isAuthed()): ?>

  <section class="card card-narrow">
    <h1 class="card-title">Admin sign in</h1>
    <form method="post" class="stack">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="login">
      <label class="field">
        <span class="field-label">Password</span>
        <input type="password" name="password" autocomplete="current-password" required autofocus>
      </label>
      <button type="submit" class="btn primary big">Sign in</button>
    </form>
    <p class="hint">Ten attempts per 15 minutes, per IP.</p>
  </section>

<?php else: ?>

  <nav class="tabs">
    <?php foreach (['overview' => 'Overview', 'settings' => 'Settings', 'inboxes' => 'Inboxes', 'tools' => 'Tools'] as $key => $label): ?>
      <a class="tab<?= $tab === $key ? ' tab-active' : '' ?>" href="?tab=<?= h($key) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($tab === 'overview'):
      $checks = Admin::health();
      $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
      foreach ($checks as $c) {
          $counts[$c['status']] = ($counts[$c['status']] ?? 0) + 1;
      }
      $stats = Admin::stats();
  ?>

    <section class="stat-grid">
      <div class="stat"><span class="stat-num"><?= h((string) $stats['inboxes_live']) ?></span><span class="stat-label">Live inboxes</span></div>
      <div class="stat"><span class="stat-num"><?= h((string) $stats['emails_24h']) ?></span><span class="stat-label">Emails · 24h</span></div>
      <div class="stat"><span class="stat-num"><?= h((string) $stats['created_24h']) ?></span><span class="stat-label">Addresses · 24h</span></div>
      <div class="stat"><span class="stat-num"><?= $stats['last_email_age'] === null ? '—' : h(relative_time((int) $stats['last_email_age'])) ?></span><span class="stat-label">Last email</span></div>
    </section>

    <section class="card">
      <div class="card-head">
        <h2 class="card-title">Health</h2>
        <span class="health-summary">
          <?= $counts['fail'] > 0 ? '<span class="pill pill-fail">' . (int) $counts['fail'] . ' problem(s)</span>' : '' ?>
          <?= $counts['warn'] > 0 ? '<span class="pill pill-warn">' . (int) $counts['warn'] . ' to check</span>' : '' ?>
          <?= $counts['fail'] === 0 && $counts['warn'] === 0 ? '<span class="pill pill-pass">All good</span>' : '' ?>
        </span>
      </div>

      <?php
      $grouped = [];
      foreach ($checks as $c) {
          $grouped[$c['group']][] = $c;
      }
      foreach ($grouped as $group => $items): ?>
        <h3 class="group-title"><?= h((string) $group) ?></h3>
        <ul class="check-list">
          <?php foreach ($items as $c): ?>
            <li class="check check-<?= h($c['status']) ?>">
              <div class="check-head">
                <span class="check-label"><?= h($c['label']) ?></span>
                <?= pill($c['status']) ?>
              </div>
              <p class="check-detail"><?= nl2br(h($c['detail'])) ?></p>
              <?php if ($c['fix'] !== null): ?>
                <details class="check-fix">
                  <summary>How to fix</summary>
                  <pre class="fix" data-copy-source><?= h($c['fix']) ?></pre>
                  <button type="button" class="btn subtle copy-fix" data-copy-fix>Copy</button>
                </details>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </section>

  <?php elseif ($tab === 'settings'):
      $values = Settings::all();
      $spec   = Settings::spec();
      $groups = [];
      foreach ($spec as $key => $meta) {
          $groups[$meta['group']][$key] = $meta;
      }
  ?>

    <section class="card">
      <div class="card-head">
        <h2 class="card-title">Settings</h2>
        <?php if (!Settings::tableExists()): ?><span class="pill pill-warn">read-only</span><?php endif; ?>
      </div>

      <?php if (!Settings::tableExists()): ?>
        <p class="flash flash-error">The <code>settings</code> table is missing, so these cannot be saved yet.
          Run the migration from the Overview tab, then reload.</p>
      <?php endif; ?>

      <p class="lede">These take effect immediately. Database credentials, the mail domain and this
        password live in <code>config.php</code> and are deliberately not editable from the web.</p>

      <form method="post" class="stack">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="save_settings">

        <?php foreach ($groups as $group => $fields): ?>
          <h3 class="group-title"><?= h((string) $group) ?></h3>
          <?php foreach ($fields as $key => $meta): ?>
            <label class="field">
              <span class="field-label"><?= h((string) $meta['label']) ?></span>
              <?php if ($meta['type'] === 'int'): ?>
                <input type="number" name="<?= h($key) ?>" value="<?= h((string) $values[$key]) ?>"
                       min="<?= h((string) $meta['min']) ?>" max="<?= h((string) $meta['max']) ?>"
                       <?= Settings::tableExists() ? '' : 'disabled' ?>>
              <?php else: ?>
                <input type="text" name="<?= h($key) ?>" value="<?= h((string) $values[$key]) ?>"
                       maxlength="<?= h((string) $meta['max']) ?>"
                       <?= Settings::tableExists() ? '' : 'disabled' ?>>
              <?php endif; ?>
              <span class="field-help"><?= h((string) $meta['help']) ?></span>
              <?php if (isset($settingErrors[$key])): ?>
                <span class="field-error"><?= h((string) $settingErrors[$key]) ?></span>
              <?php endif; ?>
            </label>
          <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="form-actions">
          <button type="submit" class="btn primary" <?= Settings::tableExists() ? '' : 'disabled' ?>>Save settings</button>
        </div>
      </form>
    </section>

  <?php elseif ($tab === 'inboxes'):
      $inboxes = Admin::recentInboxes(20);
      $senders = Admin::topSenders(8);
  ?>

    <section class="card">
      <h2 class="card-title">Recent addresses</h2>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Address</th><th>Type</th><th>Created</th><th>Expires</th><th>Emails</th></tr></thead>
          <tbody>
          <?php if ($inboxes === []): ?>
            <tr><td colspan="5" class="muted">Nothing yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($inboxes as $row): ?>
            <tr>
              <td class="mono"><?= h((string) $row['address']) ?></td>
              <td><?= ((int) $row['is_custom']) === 1 ? 'custom' : 'random' ?></td>
              <td><?= h(relative_time((int) $row['age_seconds'])) ?></td>
              <td><?= ((int) $row['expires_in']) > 0
                    ? h((int) round($row['expires_in'] / 60) . ' min left')
                    : '<span class="muted">expired</span>' ?></td>
              <td><?= h((string) $row['email_count']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint">Message contents are not shown here — the panel reports counts, not people's mail.</p>
    </section>

    <section class="card">
      <h2 class="card-title">Top senders · 7 days</h2>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Sender</th><th>Emails</th></tr></thead>
          <tbody>
          <?php if ($senders === []): ?>
            <tr><td colspan="2" class="muted">No mail received yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($senders as $row): ?>
            <tr><td class="mono"><?= h((string) $row['sender_email']) ?></td><td><?= h((string) $row['hits']) ?></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>

  <?php else:
      $stats = Admin::stats();
  ?>

    <section class="card">
      <h2 class="card-title">Maintenance</h2>

      <div class="tool">
        <div>
          <h3 class="tool-title">Run cleanup now</h3>
          <p class="hint">Deletes expired inboxes, their mail, and rate-limit buckets older than two hours.
            The cron does this every 10 minutes.</p>
        </div>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="run_cleanup">
          <button type="submit" class="btn">Run cleanup</button>
        </form>
      </div>

      <div class="tool">
        <div>
          <h3 class="tool-title">Clear rate limits</h3>
          <p class="hint">Wipes all <?= h((string) $stats['rate_rows']) ?> counter row(s). Use this when your own
            testing runs into a 429.</p>
        </div>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="clear_rate_limits">
          <button type="submit" class="btn">Clear limits</button>
        </form>
      </div>
    </section>

    <section class="card">
      <h2 class="card-title">This install</h2>
      <dl class="kv">
        <dt>Mail domain</dt><dd class="mono"><?= h(defined('DOMAIN') ? DOMAIN : '—') ?></dd>
        <dt>App directory</dt><dd class="mono"><?= h(dirname(__DIR__, 2)) ?></dd>
        <dt>PHP</dt><dd class="mono"><?= h(PHP_VERSION) ?> · <?= h(PHP_BINARY) ?></dd>
        <dt>Log file</dt><dd class="mono"><?= h((defined('LOG_DIR') ? LOG_DIR : '') . '/app.log') ?></dd>
        <dt>Emails stored</dt><dd><?= h((string) $stats['emails_total']) ?> (<?= h((string) $stats['emails_unread']) ?> unread)</dd>
        <dt>Inboxes ever created</dt><dd><?= h((string) $stats['inboxes_total']) ?></dd>
      </dl>
    </section>

  <?php endif; ?>

<?php endif; ?>

</main>

<script src="<?= h(asset_url('admin.js', '../assets/')) ?>" defer></script>
</body>
</html>
