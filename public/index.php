<?php
/**
 * The whole UI: one server-rendered page that works without JavaScript,
 * progressively enhanced by assets/app.js.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Helpers.php';
require_once dirname(__DIR__) . '/src/Inbox.php';
require_once dirname(__DIR__) . '/src/Message.php';
require_once dirname(__DIR__) . '/src/Csrf.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';

start_session();

// NFR-5 — security headers. The CSP has no 'unsafe-inline' for scripts, so
// every line of JavaScript lives in assets/app.js.
header(
    "Content-Security-Policy: default-src 'self'; script-src 'self'; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; "
    . "frame-src 'self'; frame-ancestors 'self'; form-action 'self'; "
    . "base-uri 'none'; object-src 'none'"
);
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

/** Path of this page, safe to use in a Location header. */
function self_path(): string
{
    $path = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $path = preg_replace('#[^A-Za-z0-9._/\-]#', '', (string) $path);
    return $path === '' ? '/' : $path;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function redirect_self(string $query = ''): never
{
    header('Location: ' . self_path() . ($query === '' ? '' : '?' . $query), true, 303);
    exit;
}

/** HTML-side rate limiting: on a hit we flash instead of showing a 429 page. */
function html_rate_limit(string $action, int $max, int $window): bool
{
    $limiter = RateLimiter::forCurrentRequest();
    if ($limiter->check($action, $max, $window)) {
        return true;
    }
    $minutes = (int) ceil($limiter->remainingSeconds() / 60);
    flash('error', 'Too many addresses created from your network. Try again in ' . $minutes . ' minute(s).');
    return false;
}

$token = owner_token();
$inbox = $token === null ? null : Inbox::resolveByToken($token);

// ---------------------------------------------------------------- actions ---
// No-JS fallbacks. app.js intercepts these forms and calls the API instead.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Csrf::validate(Csrf::fromRequest())) {
        http_response_code(403);
        flash('error', 'Your session expired. The page has been reloaded — please try again.');
        redirect_self();
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'generate':
                if (html_rate_limit('generate', 10, 3600)) {
                    $new = Inbox::createRandom();
                    set_owner_token($new['token']);
                    flash('ok', 'New address ready.');
                }
                break;

            case 'custom':
                if (html_rate_limit('generate', 10, 3600)) {
                    $new = Inbox::createCustom((string) ($_POST['prefix'] ?? ''));
                    set_owner_token($new['token']);
                    flash('ok', 'Address ' . $new['address'] . ' is yours.');
                }
                break;

            case 'extend':
                if ($inbox !== null) {
                    Inbox::extend((int) $inbox['id']);
                    flash('ok', 'Inbox extended by one hour.');
                }
                break;

            case 'delete':
                if ($inbox !== null) {
                    $id = (int) ($_POST['id'] ?? 0);
                    Message::forInbox($inbox)->delete($id);
                    flash('ok', 'Email deleted.');
                }
                break;

            case 'clear':
                if ($inbox !== null) {
                    $count = Message::forInbox($inbox)->clearAll();
                    flash('ok', $count === 1 ? '1 email deleted.' : $count . ' emails deleted.');
                }
                break;
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        log_error('index POST ' . $action . ': ' . $e->getMessage());
        flash('error', 'Something went wrong. Please try again.');
    }

    redirect_self();
}

// ------------------------------------------------------------- first paint --
// FR-1.1 — a visitor with no inbox gets one before the page renders, so the
// address is in the HTML on first paint.
$rateLimited = false;
if ($inbox === null) {
    if (html_rate_limit('generate', 10, 3600)) {
        try {
            $inbox = Inbox::createRandom();
            set_owner_token($inbox['token']);
        } catch (Throwable $e) {
            log_error('auto-create: ' . $e->getMessage());
            flash('error', 'Could not create an address right now. Please retry in a moment.');
        }
    } else {
        $rateLimited = true;
    }
}

$flash    = take_flash();
$messages = [];
$unread   = 0;
$lastId   = 0;
$view     = null;
$confirm  = (string) ($_GET['confirm'] ?? '');

if ($inbox !== null) {
    $store    = Message::forInbox($inbox);
    $viewId   = (int) filter_var($_GET['msg'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0, 'min_range' => 1]]);
    if ($viewId > 0) {
        $view = $store->get($viewId);
        if ($view !== null) {
            $store->markRead($viewId);
        }
    }

    $messages = $store->listSince(0);
    foreach ($messages as $m) {
        $lastId = max($lastId, (int) $m['id']);
        if ((int) $m['is_read'] === 0) {
            $unread++;
        }
    }
}

$csrf       = Csrf::token();
$address    = $inbox['address'] ?? '';
$expiresAt  = $inbox['expires_at'] ?? '';
$extensions = (int) ($inbox['extensions'] ?? 0);
$titlePrefix = $unread > 0 ? '(' . $unread . ') ' : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="csrf-token" content="<?= h($csrf) ?>">
<meta name="robots" content="noindex">
<title><?= h($titlePrefix) ?>TempMail — disposable inbox</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='14' font-size='14'>%F0%9F%93%AC</text></svg>">
<link rel="stylesheet" href="assets/style.css">
</head>
<body
  data-address="<?= h($address) ?>"
  data-expires-at="<?= h($expiresAt) ?>"
  data-server-time="<?= h(date('Y-m-d H:i:s')) ?>"
  data-extensions="<?= h((string) $extensions) ?>"
  data-max-extensions="<?= h((string) MAX_EXTENSIONS) ?>"
  data-last-id="<?= h((string) $lastId) ?>">

<header class="topbar">
  <span class="brand">📬 TempMail</span>
  <form method="post" class="inline-form" data-js="generate">
    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
    <input type="hidden" name="action" value="generate">
    <button type="submit" class="btn">Generate new</button>
  </form>
</header>

<main>
<?php if ($flash !== null): ?>
  <p class="flash flash-<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></p>
<?php endif; ?>
<div id="notice" class="flash" role="status" hidden></div>

<?php if ($inbox === null): ?>
  <section class="hero">
    <h1 class="label"><?= $rateLimited ? 'Slow down a moment' : 'No active inbox' ?></h1>
    <p class="expired-note">
      <?= $rateLimited
          ? 'This network has created a lot of addresses in the past hour. Try again shortly.'
          : 'This inbox has expired.' ?>
    </p>
    <form method="post" class="inline-form" data-js="generate">
      <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn primary big">Generate a new address</button>
    </form>
  </section>
<?php else: ?>

  <section class="hero">
    <h1 class="label">Your temporary address</h1>

    <div class="address-box">
      <span id="address" class="address"><?= h($address) ?></span>
      <button type="button" id="copy-btn" class="btn copy-btn" data-copy="<?= h($address) ?>"
              aria-label="Copy address to clipboard">Copy</button>
    </div>

    <div class="meta-row">
      <span id="countdown" class="countdown">Expires <?= h(date('H:i', (int) strtotime((string) $expiresAt))) ?></span>
      <form method="post" class="inline-form" data-js="extend">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="extend">
        <button type="submit" id="extend-btn" class="btn"
          <?= $extensions >= MAX_EXTENSIONS ? 'disabled' : '' ?>>+1 hour</button>
      </form>
      <span id="extend-note" class="hint"><?= h((string) (MAX_EXTENSIONS - $extensions)) ?> left</span>
    </div>

    <details class="custom" id="custom-panel">
      <summary>Use custom address</summary>
      <form method="post" id="custom-form" class="custom-form">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="custom">
        <div class="custom-input">
          <input type="text" id="custom-prefix" name="prefix" inputmode="latin"
                 autocapitalize="off" autocorrect="off" spellcheck="false"
                 minlength="3" maxlength="30" placeholder="your-name"
                 aria-describedby="custom-status">
          <span class="suffix">@<?= h(DOMAIN) ?></span>
        </div>
        <p id="custom-status" class="hint" role="status"></p>
        <button type="submit" class="btn primary">Create address</button>
      </form>
    </details>
  </section>

  <section class="inbox">
    <div class="inbox-head">
      <h2 class="inbox-title">Inbox <span id="inbox-count"><?= count($messages) > 0 ? '(' . count($messages) . ')' : '' ?></span></h2>
      <a class="btn subtle" id="clear-link" href="?confirm=clear">Clear all</a>
    </div>

    <?php if ($confirm === 'clear' && $view === null): ?>
      <div class="confirm-bar">
        <span>Delete every email in this inbox?</span>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="clear">
          <button type="submit" class="btn danger">Yes, clear</button>
        </form>
        <a class="btn subtle" href="<?= h(self_path()) ?>">Cancel</a>
      </div>
    <?php endif; ?>

    <ul class="message-list<?= count($messages) === 0 ? ' is-empty' : '' ?>" id="message-list">
      <?php foreach ($messages as $m):
          $sender = trim((string) ($m['sender_name'] ?? '')) !== ''
              ? (string) $m['sender_name']
              : (string) ($m['sender_email'] ?? 'Unknown sender');
      ?>
      <li class="message<?= (int) $m['is_read'] === 0 ? ' unread' : '' ?>" data-id="<?= h((string) $m['id']) ?>">
        <a class="message-link" href="?msg=<?= h((string) $m['id']) ?>">
          <span class="message-top">
            <span class="sender"><span class="dot" aria-hidden="true"></span><?= h($sender) ?></span>
            <span class="time"><?= h(relative_time((string) $m['received_at'])) ?></span>
          </span>
          <span class="subject"><?= h(trim((string) $m['subject']) !== '' ? (string) $m['subject'] : '(no subject)') ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>

    <p class="empty-state" id="empty-state"<?= count($messages) > 0 ? ' hidden' : '' ?>>
      <span class="pulse" aria-hidden="true"></span> Waiting for emails…
    </p>
  </section>

  <?php if ($view !== null): ?>
    <?php
      $viewSender = trim((string) ($view['sender_name'] ?? '')) !== ''
          ? (string) $view['sender_name']
          : (string) ($view['sender_email'] ?? 'Unknown sender');
      $viewOtp = Message::extractOtp($view['subject'] ?? '', $view['body_text'] ?? '');
    ?>
    <!-- No-JS message view. With JS this same content opens in a modal. -->
    <section class="reader" id="server-reader">
      <div class="reader-head">
        <a class="btn subtle" href="<?= h(self_path()) ?>">← Back to inbox</a>
        <a class="btn danger subtle" href="?msg=<?= h((string) $view['id']) ?>&amp;confirm=delete">Delete</a>
      </div>

      <?php if ($confirm === 'delete'): ?>
        <div class="confirm-bar">
          <span>Delete this email?</span>
          <form method="post" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= h((string) $view['id']) ?>">
            <button type="submit" class="btn danger">Yes, delete</button>
          </form>
          <a class="btn subtle" href="?msg=<?= h((string) $view['id']) ?>">Cancel</a>
        </div>
      <?php endif; ?>

      <h2 class="reader-subject"><?= h(trim((string) $view['subject']) !== '' ? (string) $view['subject'] : '(no subject)') ?></h2>
      <p class="reader-meta">
        <?= h($viewSender) ?>
        <span class="muted">&lt;<?= h((string) $view['sender_email']) ?>&gt;</span>
        · <?= h(relative_time((string) $view['received_at'])) ?>
      </p>

      <?php if ($viewOtp !== null): ?>
        <p class="otp-chip">Code: <strong><?= h($viewOtp) ?></strong></p>
      <?php endif; ?>

      <!-- FR-4.2 — no allow-scripts, no allow-same-origin: email markup cannot
           run script or touch this document. -->
      <iframe class="reader-frame" title="Email content"
              sandbox="allow-popups allow-popups-to-escape-sandbox"
              referrerpolicy="no-referrer"
              srcdoc="<?= h(Message::iframeDocument($view['body_html'] ?? null, $view['body_text'] ?? null)) ?>"></iframe>

      <details class="raw">
        <summary>Raw source</summary>
        <pre class="raw-source"><?= h((string) $view['raw_headers']) ?>

<?= h((string) $view['body_text']) ?></pre>
      </details>
    </section>
  <?php endif; ?>

<?php endif; ?>
</main>

<footer class="footer">
  <p>Emails are deleted when the inbox expires. Don't use this for anything you need to keep.</p>
</footer>

<!-- JS message reader. Populated by app.js; inert without JavaScript. -->
<div class="modal" id="modal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="modal-subject">
    <div class="modal-head">
      <button type="button" class="btn subtle" id="modal-close">← Back</button>
      <span class="modal-actions">
        <button type="button" class="btn subtle" id="modal-raw-toggle">Raw</button>
        <button type="button" class="btn danger subtle" id="modal-delete">Delete</button>
      </span>
    </div>
    <div class="confirm-bar" id="modal-confirm" hidden>
      <span>Delete this email?</span>
      <button type="button" class="btn danger" id="modal-delete-yes">Yes, delete</button>
      <button type="button" class="btn subtle" id="modal-delete-no">Cancel</button>
    </div>
    <h2 class="reader-subject" id="modal-subject"></h2>
    <p class="reader-meta" id="modal-meta"></p>
    <button type="button" class="otp-chip" id="modal-otp" hidden></button>
    <iframe class="reader-frame" id="modal-frame" title="Email content"
            sandbox="allow-popups allow-popups-to-escape-sandbox"
            referrerpolicy="no-referrer"></iframe>
    <pre class="raw-source" id="modal-raw" hidden></pre>
  </div>
</div>

<script src="assets/app.js" defer></script>
</body>
</html>
