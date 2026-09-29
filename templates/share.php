<?php
/**
 * The practice's own view of its onboarding, opened with a link and no
 * sign-in.
 *
 * Deliberately narrower than the internal page. It shows what the
 * practice needs to know: how far along things are, what is done, what
 * is outstanding, and what is waiting on them. It does not show
 * internal notes, who on our side owns each task, the activity log, or
 * any other practice.
 *
 * @var array  $practice
 * @var array  $grouped    product => categories => tasks
 * @var array  $rollup
 * @var array  $by_product
 * @var array  $config
 */
$page_title = (string) $practice['name'];
$days       = days_until($practice['target_go_live_date'] ?? null);
$pctDone    = (int) $rollup['progress'];

// What the practice is likely to be asked about.
$waiting = 0;
foreach ($grouped as $g) {
    foreach ($g['categories'] as $c) {
        foreach ($c['tasks'] as $t) {
            if (in_array($t['status'], ['waiting', 'blocked'], true)) {
                $waiting++;
            }
        }
    }
}
?>
<div class="share">

  <header class="share-head">
    <div class="share-brand">
      <span class="auth-mark" aria-hidden="true"></span>
      <span class="auth-name"><?= e($config['app_name']) ?></span>
    </div>
    <h1><?= e($practice['name']) ?></h1>
    <p class="share-sub">
      Onboarding progress
      <?php if (!empty($practice['target_go_live_date'])): ?>
        · target go-live <?= e(fmt_date($practice['target_go_live_date'])) ?>
        <?php if ($days !== null && $practice['onboarding_state'] !== 'completed'): ?>
          <?php if ($days < 0): ?>(<?= abs($days) ?> days past)
          <?php elseif ($days === 0): ?>(today)
          <?php else: ?>(in <?= $days ?> days)<?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </p>
  </header>

  <section class="share-hero">
    <div class="share-gauge">
      <svg viewBox="0 0 42 42" role="img" aria-label="<?= $pctDone ?> percent complete">
        <circle cx="21" cy="21" r="15.915" class="gauge-track"></circle>
        <circle cx="21" cy="21" r="15.915" class="gauge-fill"
                stroke-dasharray="<?= $pctDone ?> <?= 100 - $pctDone ?>" stroke-dashoffset="25"></circle>
      </svg>
      <span class="share-gauge-num"><?= $pctDone ?>%</span>
    </div>
    <div class="share-hero-text">
      <p class="share-big"><?= (int) $rollup['completed'] ?> of <?= (int) $rollup['countable'] ?> steps complete</p>
      <?php if ($waiting > 0): ?>
        <p class="share-note">
          <?= $waiting ?> step<?= $waiting === 1 ? '' : 's' ?> currently waiting or held up.
          Your onboarding contact will be in touch about anything needed from you.
        </p>
      <?php elseif ($pctDone >= 100): ?>
        <p class="share-note">Everything is complete. Welcome aboard.</p>
      <?php else: ?>
        <p class="share-note">Everything is moving. Nothing is held up at the moment.</p>
      <?php endif; ?>
    </div>
  </section>

  <?php if ($by_product): ?>
    <section class="share-card">
      <h2>By product</h2>
      <ul class="mini-list">
        <?php foreach ($by_product as $bp): $r = $bp['rollup']; ?>
          <li>
            <div class="mini-row">
              <span class="mini-name"><?= e($bp['product_name']) ?></span>
              <span class="mini-meta"><?= (int) $r['completed'] ?>/<?= (int) $r['countable'] ?></span>
            </div>
            <?php $pct = (int) $r['progress']; $bar_size = 'sm'; require APP_ROOT . '/templates/partials/progress.php'; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <?php foreach ($grouped as $g): ?>
    <section class="share-card">
      <h2>
        <?php if (!empty($g['product_color'])): ?>
          <span class="chip-dot" style="background: <?= e($g['product_color']) ?>"></span>
        <?php endif; ?>
        <?= e($g['product_name']) ?>
      </h2>

      <?php foreach ($g['categories'] as $cat): ?>
        <h3 class="share-cat"><?= e($cat['category_name']) ?></h3>
        <ul class="share-tasks">
          <?php foreach ($cat['tasks'] as $t): ?>
            <li class="<?= $t['status'] === 'completed' ? 'is-done' : '' ?>">
              <span class="share-task-name"><?= e($t['task_name']) ?></span>
              <span class="share-task-meta">
                <?php if (!empty($t['due_date']) && $t['status'] !== 'completed'): ?>
                  <span class="muted"><?= e(fmt_date($t['due_date'])) ?></span>
                <?php endif; ?>
                <?php $status = (string) $t['status']; require APP_ROOT . '/templates/partials/status_badge.php'; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <?php if (!$grouped): ?>
    <section class="share-card">
      <p class="muted">Your onboarding plan is being set up. This page will fill in shortly.</p>
    </section>
  <?php endif; ?>

  <p class="share-foot">
    This page updates by itself as work progresses, so the link stays current.
    Questions go to your onboarding contact.
  </p>
</div>
