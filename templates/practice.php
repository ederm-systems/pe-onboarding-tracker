<?php
/**
 * Practice detail: tasks organised by product, then by category.
 *
 * @var array $practice
 * @var array $grouped      product => ['categories' => [...], 'tasks' => [...]]
 * @var array $rollup       totals across ALL tasks (unfiltered)
 * @var array $by_product
 * @var array $by_category
 * @var array $filters
 * @var bool  $filtered
 * @var bool  $hiding_done
 * @var int   $shown
 * @var array $products
 * @var array $categories
 * @var array $assignees
 * @var array $activity
 * @var bool  $is_admin
 * @var ?int  $member_id
 * @var bool  $can_practices
 */
$page_title  = (string) $practice['name'];
$practice_id = (int) $practice['id'];
$mine        = $member_id !== null && ($filters['scope'] ?? 'mine') !== 'all';
$days        = days_until($practice['target_go_live_date'] ?? null);
?>

<p class="crumbs"><a href="<?= e(url('dashboard')) ?>">Dashboard</a> <span>/</span> <?= e($practice['name']) ?></p>

<div class="page-head">
  <div>
    <h1><?= e($practice['name']) ?></h1>
    <p class="sub">
      <?php if (!empty($practice['location'])): ?><?= e($practice['location']) ?> · <?php endif; ?>
      <span class="state state-<?= e($practice['onboarding_state']) ?>">
        <?= e(PRACTICE_STATES[$practice['onboarding_state']] ?? '') ?>
      </span>
      · Target go-live <?= e(fmt_date($practice['target_go_live_date'])) ?>
      <?php if ($days !== null && $practice['onboarding_state'] !== 'completed'): ?>
        <span class="<?= $days < 0 ? 'v-alert' : ($days <= 14 ? 'v-warn' : 'muted') ?>">
          (<?php if ($days < 0): ?><?= abs($days) ?> days past<?php elseif ($days === 0): ?>today<?php else: ?>in <?= $days ?> days<?php endif; ?>)
        </span>
      <?php endif; ?>
    </p>
  </div>
  <?php if ($can_practices): ?>
    <div class="page-actions">
      <a class="btn btn-quiet" href="<?= e(url('admin/practice-form', ['id' => $practice_id])) ?>">Edit practice &amp; products</a>
    </div>
  <?php endif; ?>
</div>

<section class="stat-strip" aria-label="Practice summary">
  <div class="stat stat-wide" data-rollup="overall">
    <span class="stat-label">Overall onboarding progress</span>
    <?php $pct = (int) $rollup['progress']; require APP_ROOT . '/templates/partials/progress.php'; ?>
    <span class="stat-note">
      <span data-count><?= (int) $rollup['completed'] ?> of <?= (int) $rollup['countable'] ?> done</span>
      <?php if ((int) $rollup['not_applicable'] > 0): ?>
        · <?= (int) $rollup['not_applicable'] ?> not applicable
      <?php endif; ?>
    </span>
  </div>
  <div class="stat">
    <span class="stat-label">Blocked</span>
    <span class="stat-value <?= (int) $rollup['blocked'] > 0 ? 'v-alert' : '' ?>"><?= (int) $rollup['blocked'] ?></span>
    <span class="stat-note">
      <?php if ((int) $rollup['blocked'] > 0): ?>
        <a href="<?= e(url('practice', ['id' => $practice_id, 'status' => 'blocked'])) ?>">Show only these</a>
      <?php else: ?>nothing blocked<?php endif; ?>
    </span>
  </div>
  <div class="stat">
    <span class="stat-label">Waiting</span>
    <span class="stat-value"><?= (int) $rollup['waiting'] ?></span>
    <span class="stat-note">waiting on someone else</span>
  </div>
  <div class="stat">
    <span class="stat-label">Overdue</span>
    <span class="stat-value <?= (int) $rollup['overdue'] > 0 ? 'v-warn' : '' ?>"><?= (int) $rollup['overdue'] ?></span>
    <span class="stat-note">
      <?php if ((int) $rollup['overdue'] > 0): ?>
        <a href="<?= e(url('practice', ['id' => $practice_id, 'overdue' => 1])) ?>">Show only these</a>
      <?php else: ?>nothing overdue<?php endif; ?>
    </span>
  </div>
</section>

<div class="split">
  <section class="card" aria-labelledby="bp-h">
    <h2 id="bp-h" class="card-h">Progress by product</h2>
    <?php if (!$by_product): ?>
      <p class="muted">
        No products are selected for this practice yet<?= $is_admin ? ', so it has no tasks.' : '.' ?>
      </p>
    <?php else: ?>
      <ul class="mini-list">
        <?php foreach ($by_product as $bp): $r = $bp['rollup']; ?>
          <li data-rollup-product="<?= (int) $bp['product_id'] ?>">
            <div class="mini-row">
              <a class="mini-name" href="<?= e(url('practice', ['id' => $practice_id, 'product_id' => (int) $bp['product_id']])) ?>">
                <?= e($bp['product_name']) ?>
              </a>
              <span class="mini-meta">
                <?= (int) $r['completed'] ?>/<?= (int) $r['countable'] ?>
                <?php if ((int) $r['blocked'] > 0): ?><span class="pill pill-alert"><?= (int) $r['blocked'] ?> blocked</span><?php endif; ?>
                <?php if ((int) $r['overdue'] > 0): ?><span class="pill pill-warn"><?= (int) $r['overdue'] ?> overdue</span><?php endif; ?>
              </span>
            </div>
            <?php $pct = (int) $r['progress']; $bar_size = 'sm'; require APP_ROOT . '/templates/partials/progress.php'; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="bc-h">
    <h2 id="bc-h" class="card-h">Progress by category</h2>
    <?php if (!$by_category): ?>
      <p class="muted">Nothing to show yet.</p>
    <?php else: ?>
      <ul class="mini-list">
        <?php foreach ($by_category as $bc): ?>
          <li>
            <div class="mini-row">
              <span class="mini-name"><?= e($bc['name']) ?></span>
              <span class="mini-meta"><?= (int) $bc['completed'] ?>/<?= (int) $bc['countable'] ?></span>
            </div>
            <?php $pct = (int) $bc['progress']; $bar_size = 'sm'; require APP_ROOT . '/templates/partials/progress.php'; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<h2 class="section-h"><?= $mine ? 'My tasks here' : 'Onboarding tasks' ?></h2>

<?php
$show = ['q', 'product', 'category', 'status', 'assignee', 'flags', 'completed', 'scope'];
$filter_page = 'practice';
require APP_ROOT . '/templates/partials/filter_bar.php';
?>

<?php if (count($grouped) > 1): ?>
  <nav class="tabs" data-product-tabs aria-label="Products">
    <?php foreach ($grouped as $g):
        $pr = $by_product[$g['product_id']]['rollup'] ?? Repo::rollup($g['tasks']);
        $needs = ((int) $pr['blocked'] > 0 || (int) $pr['overdue'] > 0);
    ?>
      <button type="button" class="tab" data-tab="<?= (int) $g['product_id'] ?>"
              <?= !empty($g['product_color']) ? 'style="--tab-color: ' . e($g['product_color']) . '"' : '' ?>>
        <?php if (!empty($g['product_color'])): ?><span class="chip-dot" style="background: <?= e($g['product_color']) ?>"></span><?php endif; ?>
        <?= e($g['product_name']) ?>
        <span class="tab-n"><?= (int) $pr['progress'] ?>%</span>
        <?php if ($needs): ?><span class="tab-flag" title="Blocked or overdue work"></span><?php endif; ?>
      </button>
    <?php endforeach; ?>
    <button type="button" class="tab" data-tab="all">All products</button>
  </nav>
<?php endif; ?>

<?php if ($filtered): ?>
  <p class="filter-note">
    Showing <?= (int) $shown ?> of <?= (int) $rollup['total'] ?> tasks<?php
      if (!empty($hiding_done) && (int) $rollup['completed'] > 0) {
          echo ', with ' . (int) $rollup['completed'] . ' completed hidden';
      }
    ?>. The progress figures above always count every task.
    <?php if (!empty($hiding_done)): ?>
      <a href="<?= e(url_with(['completed' => 1])) ?>">Show completed</a>
    <?php endif; ?>
  </p>
<?php endif; ?>

<?php if (!$grouped): ?>
  <div class="empty">
    <h3>No tasks to show</h3>
    <p>
      <?php if ($mine): ?>
        None of this practice's tasks are assigned to you.
        <a href="<?= e(url_with(['scope' => 'all'])) ?>">See everyone's tasks here</a>.
      <?php elseif ($filtered): ?>
        Nothing matches those filters.
      <?php elseif (!$products): ?>
        This practice has no products selected.
        <?php if ($can_practices): ?>
          <a href="<?= e(url('admin/practice-form', ['id' => $practice_id])) ?>">Choose its products</a> and the task list will fill in automatically.
        <?php endif; ?>
      <?php else: ?>
        The selected products have no active tasks defined yet.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>

<form method="post" action="<?= e(url('bulk-save')) ?>" id="task-form">
  <?= Csrf::field() ?>
  <input type="hidden" name="practice_id" value="<?= $practice_id ?>">

  <?php foreach ($grouped as $g):
      $r = $by_product[$g['product_id']]['rollup'] ?? Repo::rollup($g['tasks']);
  ?>
    <section class="prod-block" data-product="<?= (int) $g['product_id'] ?>">
      <header class="prod-head">
        <h3>
          <?php if (!empty($g['product_color'])): ?><span class="chip-dot" style="background: <?= e($g['product_color']) ?>"></span><?php endif; ?>
          <?= e($g['product_name']) ?>
        </h3>
        <div class="prod-progress" data-rollup-product="<?= (int) $g['product_id'] ?>">
          <?php $pct = (int) $r['progress']; $bar_size = 'sm'; require APP_ROOT . '/templates/partials/progress.php'; ?>
          <span class="prod-count" data-count><?= (int) $r['completed'] ?> of <?= (int) $r['countable'] ?> done</span>
        </div>
      </header>

      <?php foreach ($g['categories'] as $cat): ?>
        <div class="cat-block">
          <h4 class="cat-h">
            <?= e($cat['category_name']) ?>
            <span class="cat-count"><?= count($cat['tasks']) ?></span>
          </h4>

          <div class="table-scroll">
          <table class="grid task-grid">
            <thead>
              <tr>
                <?php if ($is_admin || $member_id !== null): ?><th class="c-check" aria-label="Select"></th><?php endif; ?>
                <th class="c-task">Task</th>
                <th class="c-status">Status</th>
                <th class="c-assignee">Assignee</th>
                <th class="c-due">Due</th>
                <th class="c-notes">Notes</th>
                <th class="c-updated">Updated</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cat['tasks'] as $t): require APP_ROOT . '/templates/partials/task_row.php'; endforeach; ?>
            </tbody>
          </table>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <?php if ($is_admin || $member_id !== null): ?>
    <div class="bulkbar" id="bulkbar" hidden>
      <span class="bulk-count"><span id="bulk-n">0</span> selected</span>

      <label class="f-field">
        <span>Status</span>
        <select name="bulk_status">
          <option value="">Leave as is</option>
          <?php foreach (STATUSES as $k => $label): ?>
            <option value="<?= e($k) ?>"><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="f-field">
        <span>Assignee</span>
        <select name="bulk_assignee">
          <option value="">Leave as is</option>
          <option value="clear">Unassign</option>
          <?php foreach ($assignees as $a): ?>
            <option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="f-field">
        <span>Due date</span>
        <input type="date" name="bulk_due">
      </label>

      <button type="submit" class="btn btn-primary btn-sm">Apply to selected</button>
      <button type="button" class="btn btn-quiet btn-sm" id="bulk-clear">Clear selection</button>
    </div>
  <?php endif; ?>
</form>

<?php endif; ?>

<div class="split">
  <section class="card" aria-labelledby="notes-h">
    <h2 id="notes-h" class="card-h">Practice notes</h2>
    <?php if ($is_admin): ?>
      <form method="post" action="<?= e(url('practice-notes-save')) ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $practice_id ?>">
        <textarea name="notes" rows="5" placeholder="Context for the team. No patient information."><?= e($practice['notes'] ?? '') ?></textarea>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary btn-sm">Save notes</button>
        </div>
      </form>
    <?php else: ?>
      <?php if (!empty($practice['notes'])): ?>
        <p class="prose"><?= nl2br(e($practice['notes'])) ?></p>
      <?php else: ?>
        <p class="muted">No notes.</p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <section class="card" aria-labelledby="act-h">
    <h2 id="act-h" class="card-h">Recent activity</h2>
    <?php if (!$activity): ?>
      <p class="muted">Nothing recorded yet.</p>
    <?php else: ?>
      <ul class="feed">
        <?php foreach ($activity as $a): ?>
          <li>
            <span class="feed-when"><?= e(fmt_ago($a['created_at'])) ?></span>
            <span class="feed-what">
              <?php if (!empty($a['task_name'])): ?>
                <strong><?= e($a['task_name']) ?></strong>
                <?php if (!empty($a['product_name'])): ?><span class="muted">· <?= e($a['product_name']) ?></span><?php endif; ?>
                <br>
              <?php endif; ?>
              <?= e($a['summary'] ?? ($a['action'] . ' ' . $a['entity'])) ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>
