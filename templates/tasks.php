<?php
/**
 * Cross-practice task list, one product at a time.
 *
 * This is the view that answers "what is blocked everywhere" and
 * "what is on my plate". Every product is rendered and one is shown,
 * so the page stays short without a round trip per tab.
 *
 * @var array $rows
 * @var array $grouped    product_id => ['product_name', 'product_color', 'rows']
 * @var array $rollup
 * @var array $filters
 * @var array $practices
 * @var array $products
 * @var array $categories
 * @var array $assignees
 * @var bool  $is_admin
 * @var ?int  $member_id
 */
$mine       = $member_id !== null && ($filters['scope'] ?? 'mine') !== 'all';
$page_title = $mine ? 'My tasks' : 'All tasks';
?>

<div class="page-head">
  <div>
    <h1><?= $mine ? 'My tasks' : 'All tasks' ?></h1>
    <p class="sub">
      <?= count($rows) ?> task<?= count($rows) === 1 ? '' : 's' ?>
      <?= $mine ? 'assigned to you' : 'across every active practice' ?>.
      <?php if ((int) $rollup['blocked'] > 0 || (int) $rollup['overdue'] > 0): ?>
        <strong><?= (int) $rollup['blocked'] ?></strong> blocked,
        <strong><?= (int) $rollup['overdue'] ?></strong> overdue.
      <?php endif; ?>
    </p>
  </div>
  <div class="page-actions">
    <a class="btn btn-quiet" href="<?= e(url_with(['p' => 'export'])) ?>">Export CSV</a>
  </div>
</div>

<?php
$show = ['q', 'practice', 'product', 'category', 'status', 'assignee', 'flags', 'completed', 'scope'];
$filter_page = 'tasks';
require APP_ROOT . '/templates/partials/filter_bar.php';
?>

<?php if (!$rows): ?>
  <div class="empty">
    <?php if ($mine): ?>
      <h2>Nothing is assigned to you right now</h2>
      <p>
        Either your work here is done, or it has not been handed out yet.
        <a href="<?= e(url_with(['scope' => 'all'])) ?>">See everyone's tasks</a>.
      </p>
    <?php else: ?>
      <h2>Nothing matches</h2>
      <p>Try clearing the filters above.</p>
    <?php endif; ?>
  </div>
<?php else: ?>

<?php if (count($grouped) > 1): ?>
  <nav class="tabs" data-product-tabs aria-label="Products">
    <?php foreach ($grouped as $g):
        $blocked = 0;
        $overdue = 0;
        foreach ($g['rows'] as $r) {
            if ($r['status'] === 'blocked') { $blocked++; }
            if (!empty($r['is_overdue']))   { $overdue++; }
        }
    ?>
      <button type="button" class="tab" data-tab="<?= (int) $g['product_id'] ?>">
        <?php if (!empty($g['product_color'])): ?>
          <span class="chip-dot" style="background: <?= e($g['product_color']) ?>"></span>
        <?php endif; ?>
        <?= e($g['product_name']) ?>
        <span class="tab-n"><?= count($g['rows']) ?></span>
        <?php if ($blocked > 0 || $overdue > 0): ?>
          <span class="tab-flag" title="<?= $blocked ?> blocked, <?= $overdue ?> overdue"></span>
        <?php endif; ?>
      </button>
    <?php endforeach; ?>
    <button type="button" class="tab" data-tab="all">All products<span class="tab-n"><?= count($rows) ?></span></button>
  </nav>
<?php endif; ?>

<?php foreach ($grouped as $g): ?>
  <section class="prod-block" data-product="<?= (int) $g['product_id'] ?>">
    <?php if (count($grouped) > 1): ?>
      <header class="prod-head">
        <h3>
          <?php if (!empty($g['product_color'])): ?>
            <span class="chip-dot" style="background: <?= e($g['product_color']) ?>"></span>
          <?php endif; ?>
          <?= e($g['product_name']) ?>
        </h3>
        <span class="prod-count"><?= count($g['rows']) ?> task<?= count($g['rows']) === 1 ? '' : 's' ?></span>
      </header>
    <?php endif; ?>

    <?php foreach (Repo::groupRowsByCategory($g['rows']) as $cat): ?>
      <div class="cat-group" style="--cat: <?= e($cat['category_color'] ?: '#94A3B8') ?>">
        <h4 class="cat-h cat-h-tinted">
          <span class="cat-swatch"></span>
          <?= e($cat['category_name']) ?>
          <span class="cat-count"><?= count($cat['rows']) ?></span>
        </h4>

        <div class="table-scroll">
        <table class="grid all-grid">
          <thead>
            <tr>
              <th>Practice</th><th>Task</th>
              <th>Status</th><th>Assignee</th><th>Due</th><th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cat['rows'] as $r):
                $cls = '';
                if ($r['status'] === 'completed')      { $cls = 'task-done'; }
                if ($r['status'] === 'not_applicable') { $cls = 'task-na'; }
                if ($r['status'] === 'blocked')        { $cls = 'task-blocked'; }
                if (!empty($r['is_overdue']))          { $cls .= ' task-overdue'; }
            ?>
              <tr class="<?= trim($cls) ?>">
                <td class="c-name">
                  <a href="<?= e(url('practice', ['id' => (int) $r['practice_id']])) ?>"><?= e($r['practice_name']) ?></a>
                </td>
                <td class="c-task"><?= e($r['task_name']) ?></td>
                <td><?php $status = (string) $r['status']; require APP_ROOT . '/templates/partials/status_badge.php'; ?></td>
                <td><?= $r['assignee_name']
                          ? person($r['assignee_name'])
                          : '<span class="muted">Unassigned</span>' ?></td>
                <td class="c-due">
                  <?= e(fmt_date($r['due_date'] ?? null)) ?>
                  <?php if (!empty($r['is_overdue'])): ?><span class="chip chip-alert">Overdue</span><?php endif; ?>
                </td>
                <td class="c-notes-ro"><?= $r['notes'] ? e($r['notes']) : '<span class="muted">—</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    <?php endforeach; ?>
  </section>
<?php endforeach; ?>

<p class="table-note">
  Editing happens on a practice's own page, where the whole product context is visible.
  <?php if (!$is_admin): ?>Sign in as an admin to make changes.<?php endif; ?>
</p>

<?php endif; ?>
