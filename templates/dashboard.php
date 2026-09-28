<?php
/**
 * Executive dashboard.
 *
 * Four headline numbers, then the shape of the portfolio: how practices
 * are faring, where the work sits, and what lands next. Everything is
 * clickable through to the underlying list, so a question raised in a
 * meeting can be answered in the meeting.
 *
 * @var array $totals
 * @var array $health      practice counts by health band
 * @var array $by_status
 * @var array $by_assignee
 * @var array $by_product
 * @var array $by_category
 * @var array $upcoming
 * @var ?array $my_work   the signed-in member's own open workload
 * @var bool  $is_admin
 * @var ?int  $member_id
 * @var bool  $can_practices
 */
$page_title = 'Dashboard';

$openTotal = 0;
foreach (['not_started', 'in_progress', 'waiting', 'blocked'] as $k) {
    $openTotal += (int) ($by_status[$k] ?? 0);
}
$statusTotal = array_sum(array_map('intval', $by_status));

// The gauge is drawn as a dash on a circle of circumference 100, so the
// percentage is the dash length directly.
$pctDone = (int) $totals['progress'];
?>

<div class="page-head">
  <div>
    <h1>Onboarding overview</h1>
    <p class="sub">
      <?= (int) $totals['practices'] ?> practice<?= $totals['practices'] === 1 ? '' : 's' ?> in flight
      · <?= $openTotal ?> task<?= $openTotal === 1 ? '' : 's' ?> open
      · updated <?= e(date('M j, Y')) ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-quiet" href="<?= e(url('practices')) ?>">All practices</a>
    <?php if ($can_practices): ?>
      <a class="btn btn-primary" href="<?= e(url('admin/practice-form')) ?>">Add practice</a>
    <?php endif; ?>
  </div>
</div>

<section class="kpis" aria-label="Headline numbers">

  <?php /* A team member's first question is their own workload; the
           administrator's is the portfolio. */ ?>
  <?php if ($my_work !== null): ?>
  <a class="kpi kpi-blue" href="<?= e(url('tasks')) ?>">
    <span class="kpi-label">My open tasks</span>
    <span class="kpi-value"><?= (int) $my_work['open_count'] ?></span>
    <span class="kpi-foot">
      <?php if ((int) $my_work['blocked'] > 0 || (int) $my_work['overdue'] > 0): ?>
        <?= (int) $my_work['blocked'] ?> blocked · <?= (int) $my_work['overdue'] ?> overdue
      <?php elseif ((int) $my_work['open_count'] === 0): ?>
        nothing outstanding
      <?php else: ?>
        none blocked or overdue
      <?php endif; ?>
    </span>
  <?php else: ?>
  <a class="kpi kpi-blue" href="<?= e(url('practices')) ?>">
    <span class="kpi-label">Practices in flight</span>
    <span class="kpi-value"><?= (int) $totals['practices'] ?></span>
    <span class="kpi-foot">
      <?= (int) $health['on_track'] ?> on track
      <?php if ((int) $health['on_hold'] > 0): ?> · <?= (int) $health['on_hold'] ?> on hold<?php endif; ?>
    </span>
  <?php endif; ?>
    <svg class="kpi-art" viewBox="0 0 120 40" aria-hidden="true">
      <path d="M0 32 L20 26 L40 28 L60 18 L80 20 L100 10 L120 6" fill="none"
            stroke="rgba(255,255,255,.55)" stroke-width="2.5" stroke-linecap="round"/>
    </svg>
  </a>

  <div class="kpi kpi-green">
    <span class="kpi-label">Overall progress</span>
    <div class="kpi-gauge">
      <svg viewBox="0 0 42 42" role="img" aria-label="<?= $pctDone ?> percent complete">
        <circle cx="21" cy="21" r="15.915" class="gauge-track"></circle>
        <circle cx="21" cy="21" r="15.915" class="gauge-fill"
                stroke-dasharray="<?= $pctDone ?> <?= 100 - $pctDone ?>" stroke-dashoffset="25"></circle>
      </svg>
      <span class="kpi-gauge-num"><?= $pctDone ?>%</span>
    </div>
    <span class="kpi-foot"><?= (int) $totals['completed'] ?> of <?= (int) $totals['countable'] ?> tasks done</span>
  </div>

  <a class="kpi <?= (int) $health['attention'] > 0 ? 'kpi-amber' : 'kpi-slate' ?>"
     href="<?= e(url('practices', ['attention' => 1])) ?>">
    <span class="kpi-label">Need attention</span>
    <span class="kpi-value"><?= (int) $health['attention'] ?></span>
    <span class="kpi-foot">
      <?= (int) $health['attention'] === 0 ? 'every practice is healthy' : 'practices with a blocker or overdue work' ?>
    </span>
    <svg class="kpi-art" viewBox="0 0 120 40" aria-hidden="true">
      <?php for ($i = 0; $i < 6; $i++): ?>
        <rect x="<?= 6 + $i * 20 ?>" y="<?= 30 - ($i % 3) * 7 ?>" width="10"
              height="<?= 6 + ($i % 3) * 7 ?>" rx="2" fill="rgba(255,255,255,.4)"/>
      <?php endfor; ?>
    </svg>
  </a>

  <a class="kpi <?= ($totals['blocked'] + $totals['overdue']) > 0 ? 'kpi-red' : 'kpi-slate' ?>"
     href="<?= e(url('tasks', ['blocked' => 1])) ?>">
    <span class="kpi-label">Blocked &amp; overdue</span>
    <span class="kpi-value"><?= (int) $totals['blocked'] ?><span class="kpi-split">/</span><?= (int) $totals['overdue'] ?></span>
    <span class="kpi-foot"><?= (int) $totals['blocked'] ?> blocked, <?= (int) $totals['overdue'] ?> overdue</span>
    <svg class="kpi-art" viewBox="0 0 120 40" aria-hidden="true">
      <circle cx="96" cy="20" r="16" fill="none" stroke="rgba(255,255,255,.35)" stroke-width="3"/>
      <circle cx="96" cy="20" r="7"  fill="rgba(255,255,255,.28)"/>
    </svg>
  </a>

</section>

<div class="split">
  <section class="card">
    <h2 class="card-h">Practice status</h2>
    <?php
      $donut_rows = [
        ['label' => 'On track',       'value' => (int) $health['on_track'],
         'color' => '#3E8E5C', 'href' => url('practices', ['state' => 'active'])],
        ['label' => 'Need attention', 'value' => (int) $health['attention'],
         'color' => '#D97706', 'href' => url('practices', ['attention' => 1])],
        ['label' => 'On hold',        'value' => (int) $health['on_hold'],
         'color' => '#94A3B8', 'href' => url('practices', ['state' => 'on_hold'])],
        ['label' => 'Completed',      'value' => (int) $health['completed'],
         'color' => '#0284C7', 'href' => url('practices', ['state' => 'completed'])],
      ];
      $donut_centre = (string) (int) $health['total'];
      $donut_sub    = 'practices';
      $donut_empty  = 'No practices yet.';
      require APP_ROOT . '/templates/partials/donut.php';
    ?>
  </section>

  <section class="card">
    <h2 class="card-h">Task status across the portfolio</h2>
    <?php
      $palette = [
        'not_started'    => '#CBD5E1',
        'in_progress'    => '#0284C7',
        'waiting'        => '#E0A458',
        'blocked'        => '#BE123C',
        'completed'      => '#3E8E5C',
        'not_applicable' => '#E2E8F0',
      ];
      $donut_rows = [];
      foreach (STATUSES as $k => $label) {
          $donut_rows[] = [
            'label' => $label,
            'value' => (int) ($by_status[$k] ?? 0),
            'color' => $palette[$k],
            'href'  => url('tasks', ['status' => $k]),
          ];
      }
      $donut_centre = (string) $statusTotal;
      $donut_sub    = 'tasks';
      $donut_empty  = 'No tasks yet. Add products to a practice to generate them.';
      require APP_ROOT . '/templates/partials/donut.php';
    ?>
  </section>
</div>

<section class="card">
  <h2 class="card-h">Open work by onboarding stage</h2>
  <?php
    $col_rows = array_map(static fn($r) => [
      'label' => $r['label'],
      'value' => (int) $r['open_count'],
      'color' => null,
      'href'  => url('tasks', ['category_id' => (int) $r['category_id']]),
      'sub'   => ((int) $r['blocked'] > 0) ? ((int) $r['blocked'] . ' blocked') : null,
    ], $by_category);
    $col_empty = 'No open work in any stage.';
    require APP_ROOT . '/templates/partials/columns.php';
  ?>
  <p class="table-note">Stages run left to right. A tall column late in the sequence is normal; a tall one early is a bottleneck.</p>
</section>

<div class="split">
  <section class="card">
    <h2 class="card-h">Open work by product</h2>
    <?php
      $col_rows = array_map(static fn($r) => [
        'label' => $r['label'],
        'value' => (int) $r['open_count'],
        'color' => $r['color'] ?: '#334155',
        'href'  => url('tasks', ['product_id' => (int) $r['product_id']]),
        'sub'   => ((int) $r['overdue'] > 0) ? ((int) $r['overdue'] . ' overdue') : null,
      ], $by_product);
      $col_empty = 'No products have open work.';
      require APP_ROOT . '/templates/partials/columns.php';
    ?>
  </section>

  <section class="card">
    <h2 class="card-h">Open work by person</h2>
    <?php
      $chart_rows = array_map(static fn($r) => $r + [
        'href' => url('tasks', ['assignee_id' => $r['assignee_id'] ?: 'none']),
      ], $by_assignee);
      $chart_empty = 'Nothing open. Either everything is done, or no products are selected yet.';
      require APP_ROOT . '/templates/partials/bar_chart.php';
    ?>
    <p class="table-note">Excludes completed work and anything marked Not Applicable.</p>
  </section>
</div>

<section class="card">
    <h2 class="card-h">Going live in the next 60 days</h2>
    <?php if (!$upcoming): ?>
      <p class="muted">Nothing has a target go-live date in the next 60 days.</p>
    <?php else: ?>
      <ul class="mini-list">
        <?php foreach ($upcoming as $u):
            $p    = progress_pct((int) $u['completed'], (int) $u['countable']);
            $away = (int) $u['days_away'];
        ?>
          <li>
            <div class="mini-row">
              <a class="mini-name" href="<?= e(url('practice', ['id' => (int) $u['id']])) ?>"><?= e($u['name']) ?></a>
              <span class="mini-meta <?= $away < 0 ? 'v-alert' : ($away <= 14 ? 'v-warn' : '') ?>">
                <?php if ($away < 0): ?><?= abs($away) ?>d past
                <?php elseif ($away === 0): ?>today
                <?php else: ?><?= $away ?>d away<?php endif; ?>
              </span>
            </div>
            <?php $pct = $p; $bar_size = 'sm'; require APP_ROOT . '/templates/partials/progress.php'; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>


</div>
