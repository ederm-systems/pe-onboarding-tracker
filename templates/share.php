<?php
/**
 * The practice's own view of its onboarding, opened with a link and no
 * sign-in.
 *
 * A status update, not a work log. It answers three questions and
 * stops: how far along are we, what stage is each product at, and what
 * is needed from us. The only individual tasks named are the ones in a
 * category marked as the practice's own work, because those are the
 * only ones they can act on.
 *
 * It does not show internal notes, who on our side owns anything, our
 * internal task list, the activity log, or any other practice.
 *
 * @var array  $practice
 * @var array  $rollup
 * @var array  $products      per product: name, colour, rollup, current stage
 * @var array  $actions       open tasks in customer-facing categories
 * @var array  $config
 */
$page_title = (string) $practice['name'];
$days       = days_until($practice['target_go_live_date'] ?? null);
$pctDone    = (int) $rollup['progress'];
$overdue    = 0;
foreach ($actions as $a) {
    if (!empty($a['is_overdue'])) { $overdue++; }
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
      Onboarding status
      <?php if (!empty($practice['target_go_live_date'])): ?>
        · target go-live <?= e(fmt_date($practice['target_go_live_date'])) ?>
        <?php if ($days !== null && $practice['onboarding_state'] !== 'completed'): ?>
          <?php if ($days < 0): ?><span class="v-alert">(<?= abs($days) ?> days past)</span>
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
      <p class="share-big">
        <?php if ($pctDone >= 100): ?>
          Onboarding complete
        <?php elseif ($actions): ?>
          <?= count($actions) ?> thing<?= count($actions) === 1 ? '' : 's' ?> needed from you
        <?php else: ?>
          On track, nothing needed from you
        <?php endif; ?>
      </p>
      <p class="share-note">
        <?= (int) $rollup['completed'] ?> of <?= (int) $rollup['countable'] ?> steps complete.
        <?php if (!$actions && $pctDone < 100): ?>
          We are working through the rest and will be in touch if anything is needed.
        <?php endif; ?>
      </p>
    </div>
  </section>

  <?php /* The only place individual tasks are named, because these are
           the only ones the practice can act on. */ ?>
  <?php if ($actions): ?>
    <section class="share-actions <?= $overdue > 0 ? 'is-urgent' : '' ?>">
      <h2>
        What we need from you
        <span class="share-count"><?= count($actions) ?></span>
      </h2>
      <?php if ($overdue > 0): ?>
        <p class="share-urgent-note">
          <?= $overdue ?> of these <?= $overdue === 1 ? 'is' : 'are' ?> past the date we had hoped for.
        </p>
      <?php endif; ?>

      <ul class="share-actions-list">
        <?php foreach ($actions as $a): ?>
          <li class="<?= !empty($a['is_overdue']) ? 'is-overdue' : '' ?>">
            <span class="share-action-main">
              <span class="share-action-name"><?= e($a['task_name']) ?></span>
              <?php if (!empty($a['description'])): ?>
                <span class="share-action-desc"><?= e($a['description']) ?></span>
              <?php endif; ?>
              <span class="share-action-prod"><?= e($a['product_name']) ?></span>
            </span>
            <span class="share-action-due">
              <?php if (!empty($a['due_date'])): ?>
                <span class="<?= !empty($a['is_overdue']) ? 'v-alert' : 'muted' ?>">
                  <?= !empty($a['is_overdue']) ? 'Was due ' : 'By ' ?><?= e(fmt_date($a['due_date'])) ?>
                </span>
              <?php else: ?>
                <span class="muted">When you can</span>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>

      <p class="share-actions-foot">
        Your onboarding contact will help with any of these. Reply to them and we will pick it up.
      </p>
    </section>
  <?php endif; ?>

  <?php if ($products): ?>
    <section class="share-card">
      <h2>Where each product is</h2>
      <ul class="share-products">
        <?php foreach ($products as $p): $r = $p['rollup']; ?>
          <li>
            <div class="share-prod-head">
              <span class="share-prod-name">
                <?php if (!empty($p['color'])): ?>
                  <span class="chip-dot" style="background: <?= e($p['color']) ?>"></span>
                <?php endif; ?>
                <?= e($p['name']) ?>
              </span>
              <span class="share-prod-stage">
                <?php if ((int) $r['progress'] >= 100): ?>
                  <span class="badge st-completed">Complete</span>
                <?php elseif (!empty($p['stage'])): ?>
                  <span class="muted">Currently in</span> <strong><?= e($p['stage']) ?></strong>
                <?php else: ?>
                  <span class="muted">Not started</span>
                <?php endif; ?>
              </span>
            </div>
            <?php $pct = (int) $r['progress']; $bar_size = 'sm'; require APP_ROOT . '/templates/partials/progress.php'; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php else: ?>
    <section class="share-card">
      <p class="muted">Your onboarding plan is being set up. This page will fill in shortly.</p>
    </section>
  <?php endif; ?>

  <p class="share-foot">
    This page updates by itself as work progresses, so the link stays current.
    Questions go to your onboarding contact.
  </p>
</div>
