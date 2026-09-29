<?php
/**
 * One-time setup helper.
 *
 * Creates the tables, optionally loads the starter data, and hashes an
 * admin PIN for you. It refuses to do anything unless
 * 'allow_install' => true is set in config/config.php, so set that back
 * to false (or delete this file) once you are live.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$allowed = !empty($config['allow_install']);
$notices = [];
$errors  = [];
$hash    = null;

/**
 * Split a .sql file into individual statements.
 * The schema and seed files deliberately contain no semicolons inside
 * string literals, which keeps this splitter safe for them.
 */
function sql_statements(string $path): array
{
    $sql = (string) file_get_contents($path);
    $lines = preg_split('/\R/', $sql) ?: [];
    $clean = [];
    foreach ($lines as $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '--')) {
            continue;
        }
        $clean[] = $line;
    }
    $joined = implode("\n", $clean);
    $parts  = explode(';', $joined);

    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

function run_sql_file(string $path, array &$errors): int
{
    if (!is_file($path)) {
        $errors[] = 'Missing file: ' . basename($path);
        return 0;
    }
    $n = 0;
    foreach (sql_statements($path) as $stmt) {
        try {
            Database::pdo()->exec($stmt);
            $n++;
        } catch (Throwable $ex) {
            $errors[] = basename($path) . ': ' . $ex->getMessage();
        }
    }
    return $n;
}

$action = (string) ($_POST['action'] ?? '');

if ($action !== '' && $allowed) {
    if (!Csrf::check((string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Reload this page and try again.';
    } else {
        if ($action === 'schema') {
            $n = run_sql_file(APP_ROOT . '/db/schema.sql', $errors);
            $notices[] = "Ran {$n} schema statement(s).";
        }

        if ($action === 'seed') {
            if (!Database::isInstalled()) {
                $errors[] = 'Create the tables first.';
            } else {
                $existing = (int) Database::scalar('SELECT COUNT(*) FROM products');
                if ($existing > 0 && empty($_POST['confirm_reseed'])) {
                    $errors[] = 'There are already ' . $existing . ' products. Tick the confirmation box to load the starter data anyway.';
                } else {
                    $n = run_sql_file(APP_ROOT . '/db/seed.sql', $errors);
                    $notices[] = "Ran {$n} seed statement(s).";
                }
            }
        }

        if ($action === 'hash') {
            $pin = trim((string) ($_POST['pin'] ?? ''));
            if (!preg_match('/^[0-9]{6}$/', $pin)) {
                $errors[] = 'The PIN must be exactly 6 digits, the same as everyone else\'s.';
            } else {
                $hash = password_hash($pin, PASSWORD_DEFAULT);
            }
        }
    }
}

// Current state, for the checklist.
$dbOk        = false;
$dbError     = null;
$installed   = false;
$counts      = [];

try {
    Database::pdo();
    $dbOk = true;
    $installed = Database::isInstalled();
    if ($installed) {
        foreach (['products', 'categories', 'tasks', 'assignees', 'practices', 'practice_products', 'practice_tasks'] as $t) {
            $counts[$t] = (int) Database::scalar("SELECT COUNT(*) FROM {$t}");
        }
    }
} catch (Throwable $ex) {
    $dbError = $ex->getMessage();
}

$pinSet = trim((string) ($config['admin_pin_hash'] ?? '')) !== '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Setup · <?= e($config['app_name']) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
</head>
<body>
<main class="wrap" style="max-width: 52rem">

  <h1>Setup</h1>
  <p class="sub">Run these once, in order. Then set <code>allow_install</code> to false in
     <code>config/config.php</code> and this page stops working.</p>

  <?php if (!$allowed): ?>
    <div class="flash flash-error">
      Setup is disabled. To re-enable it, set <code>'allow_install' =&gt; true</code> in
      <code>config/config.php</code>. Leaving it disabled is the correct state for a live site.
    </div>
  <?php endif; ?>

  <?php foreach ($notices as $n): ?><div class="flash flash-ok"><?= e($n) ?></div><?php endforeach; ?>
  <?php foreach ($errors as $n): ?><div class="flash flash-error"><?= e($n) ?></div><?php endforeach; ?>

  <section class="card">
    <h2 class="card-h">1 · Database connection</h2>
    <?php if ($dbOk): ?>
      <p><strong>Connected</strong> to <code><?= e((string) $config['db_name']) ?></code> on
         <code><?= e((string) $config['db_host']) ?></code>.</p>
    <?php else: ?>
      <p class="v-alert"><strong>Not connected.</strong></p>
      <p class="prose">Check <code>config/config.php</code>. On GoDaddy the host is usually
         <code>localhost</code>, and both the database name and user are prefixed with your
         cPanel username.</p>
      <?php if ($dbError): ?><p class="muted"><?= e($dbError) ?></p><?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2 class="card-h">2 · Create the tables</h2>
    <?php if ($installed): ?>
      <p><strong>Tables exist.</strong></p>
      <ul class="prose">
        <?php foreach ($counts as $t => $c): ?>
          <li><code><?= e($t) ?></code>: <?= (int) $c ?> row<?= $c === 1 ? '' : 's' ?></li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="prose">Runs <code>db/schema.sql</code>. Safe to run twice, every statement uses
         <code>CREATE TABLE IF NOT EXISTS</code>.</p>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="schema">
      <div class="form-actions">
        <button class="btn btn-primary btn-sm" <?= (!$allowed || !$dbOk) ? 'disabled' : '' ?>>
          <?= $installed ? 'Run schema again' : 'Create tables' ?>
        </button>
      </div>
    </form>
  </section>

  <section class="card">
    <h2 class="card-h">3 · Load the starter data</h2>
    <p class="prose">
      Runs <code>db/seed.sql</code>: the five products you named, seven task categories,
      102 starter onboarding tasks, five role-based assignees, and four sample practices so the
      dashboard is not empty. Everything is editable in the admin screens afterwards.
      Skip this step if you would rather start from nothing.
    </p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="seed">
      <?php if (!empty($counts['products'])): ?>
        <label class="check-line">
          <input type="checkbox" name="confirm_reseed" value="1">
          <span>Yes, load it anyway. Existing products and categories are matched by name and updated, not duplicated, but sample practices would be added again.</span>
        </label>
      <?php endif; ?>
      <div class="form-actions">
        <button class="btn btn-primary btn-sm" <?= (!$allowed || !$installed) ? 'disabled' : '' ?>>Load starter data</button>
      </div>
    </form>
  </section>

  <section class="card">
    <h2 class="card-h">4 · Set the admin PIN</h2>
    <?php if ($pinSet): ?>
      <p><strong>A PIN is configured.</strong> Generate a new hash below if you want to change it.</p>
    <?php else: ?>
      <p class="v-warn"><strong>No PIN is set yet</strong>, so nobody can sign in to make changes.</p>
    <?php endif; ?>
    <p class="prose">
      Type the 6-digit PIN you want. This page hashes it and shows you the line to paste into
      <code>config/config.php</code>. The PIN itself is never stored anywhere.
    </p>
    <p class="prose">
      Pick one no team member is using. Sign-in tries the administrator PIN first, so a clash
      would send them into your account. The People screen already refuses to hand a team member
      a PIN matching yours, but nothing stops the reverse being done here by hand.
    </p>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>">
      <input type="hidden" name="action" value="hash">
      <label class="field" style="max-width: 20rem">
        <span>PIN <em>exactly 6 digits</em></span>
        <input type="text" name="pin" autocomplete="off" spellcheck="false"
               inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="481920">
      </label>
      <div class="form-actions">
        <button class="btn btn-primary btn-sm" <?= $allowed ? '' : 'disabled' ?>>Generate hash</button>
      </div>
    </form>

    <?php if ($hash): ?>
      <p style="margin-top:1rem"><strong>Paste this into <code>config/config.php</code>:</strong></p>
      <textarea rows="3" readonly onclick="this.select()">'admin_pin_hash' =&gt; '<?= e($hash) ?>',</textarea>
      <p class="muted">Replace the existing <code>admin_pin_hash</code> line. Then sign in with the PIN you just typed.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2 class="card-h">5 · Lock setup down</h2>
    <p class="prose">
      Set <code>'allow_install' =&gt; false</code> in <code>config/config.php</code>, or delete
      <code>public/install.php</code> entirely. Then open the
      <a href="<?= e(url('dashboard')) ?>">dashboard</a>.
    </p>
  </section>

  <p class="table-note">
    Reminder: this tracker is for onboarding operations only. Never enter patient information into it.
  </p>
</main>
</body>
</html>
