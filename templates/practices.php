<?php
/**
 * Every practice, with progress and attention flags.
 *
 * Moved off the dashboard so it can grow to hundreds of rows without
 * pushing the summary charts off screen. Admin actions appear inline
 * when signed in, so there is only one practices page rather than two.
 *
 * @var array $rows
 * @var array $filters
 * @var array $products
 * @var bool  $is_admin
 * @var bool  $can_practices
 * @var bool  $can_delete
 */
$page_title = 'Practices';

$sortLinks = [
    'name'     => 'Name',
    'progress' => 'Progress',
    'golive'   => 'Target go-live',
    'updated'  => 'Last updated',
    'blocked'  => 'Blocked',
    'overdue'  => 'Overdue',
];
$curSort = $filters['sort'] ?? 'name';
$curDir  = $filters['dir'] ?? 'asc';

$attention = 0;
foreach ($rows as $r) {
    if ($r['needs_attention']) { $attention++; }
}
?>

<div class="page-head">
  <div>
    <h1>Practices</h1>
    <p class="sub">
      <?= count($rows) ?> shown.
      <?php if ($attention > 0): ?>
        <strong><?= $attention ?></strong> need attention.
      <?php else: ?>
        None blocked or overdue.
      <?php endif; ?>
    </p>
  </div>
  <?php if ($can_practices): ?>
    <div class="page-actions">
      <a class="btn btn-primary" href="<?= e(url('admin/practice-form')) ?>">Add practice</a>
    </div>
  <?php endif; ?>
</div>

<?php
$show = ['q', 'product', 'state', 'flags', 'attention'];
$filter_page = 'practices';
require APP_ROOT . '/templates/partials/filter_bar.php';
?>

<div class="sortbar">
  <span class="sortbar-label">Sort by</span>
  <?php foreach ($sortLinks as $key => $label):
      $isOn    = ($curSort === $key);
      $default = in_array($key, ['blocked', 'overdue', 'progress', 'updated'], true) ? 'desc' : 'asc';
      $nextDir = $isOn ? ($curDir === 'asc' ? 'desc' : 'asc') : $default;
  ?>
    <a class="sort-link <?= $isOn ? 'on' : '' ?>" href="<?= e(url_with(['sort' => $key, 'dir' => $nextDir])) ?>">
      <?= e($label) ?><?php if ($isOn): ?><span class="caret"><?= $curDir === 'asc' ? '▲' : '▼' ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
  <?php if ($is_admin): ?>
    <a class="sort-link" href="<?= e(url_with(['archived' => empty($filters['include_archived']) ? 1 : null])) ?>">
      <?= empty($filters['include_archived']) ? 'Show archived' : 'Hide archived' ?>
    </a>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <div class="empty">
    <h2>No practices match</h2>
    <p>
      <?php if ($filters['q'] || $filters['product_id'] || $filters['state']
                || $filters['only_blocked'] || $filters['only_overdue'] || $filters['only_attention']): ?>
        Try clearing the filters above.
      <?php elseif ($can_practices): ?>
        Add your first practice to get started.
      <?php else: ?>
        No practices have been set up yet.
      <?php endif; ?>
    </p>
  </div>
<?php else: ?>

<div class="table-scroll">
<table class="grid dash-grid">
  <thead>
    <tr>
      <th class="c-name">Practice</th>
      <th class="c-products">Products</th>
      <th class="c-progress">Progress</th>
      <th class="c-num">Left</th>
      <th class="c-num">Blocked</th>
      <th class="c-num">Overdue</th>
      <th class="c-golive">Target go-live</th>
      <?php if ($can_practices): ?><th class="c-act">Actions</th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
      $href   = url('practice', ['id' => (int) $r['id']]);
      $rowCls = $r['needs_attention'] ? 'row-alert' : '';
      if ($r['onboarding_state'] === 'completed') { $rowCls .= ' row-done'; }
      if ($r['onboarding_state'] === 'on_hold')   { $rowCls .= ' row-hold'; }
      if (!empty($r['is_archived']))              { $rowCls .= ' row-muted'; }
      $days = $r['days_to_golive'];
  ?>
    <tr class="<?= trim($rowCls) ?>">
      <td class="c-name">
        <a class="practice-link" href="<?= e($href) ?>"><?= e($r['name']) ?></a>
        <span class="row-sub">
          <?php if (!empty($r['location'])): ?><?= e($r['location']) ?> · <?php endif; ?>
          <span class="state state-<?= e($r['onboarding_state']) ?>"><?= e(PRACTICE_STATES[$r['onboarding_state']] ?? '') ?></span>
          <?php if (!empty($r['is_archived'])): ?> · <span class="muted">Archived</span><?php endif; ?>
        </span>
      </td>

      <td class="c-products">
        <?php if (!$r['products']): ?>
          <span class="muted">No products selected</span>
        <?php else: foreach ($r['products'] as $p): ?>
          <span class="chip">
            <?php if (!empty($p['color'])): ?>
              <span class="chip-dot" style="background: <?= e($p['color']) ?>"></span>
            <?php endif; ?>
            <?= e($p['name']) ?>
          </span>
        <?php endforeach; endif; ?>
      </td>

      <td class="c-progress"><?php $pct = (int) $r['progress']; require APP_ROOT . '/templates/partials/progress.php'; ?></td>
      <td class="c-num"><?= (int) $r['remaining'] ?></td>

      <td class="c-num">
        <?php if ((int) $r['blocked'] > 0): ?>
          <a class="pill pill-alert" href="<?= e(url('practice', ['id' => (int) $r['id'], 'blocked' => 1])) ?>"><?= (int) $r['blocked'] ?></a>
        <?php else: ?><span class="muted">0</span><?php endif; ?>
      </td>

      <td class="c-num">
        <?php if ((int) $r['overdue'] > 0): ?>
          <a class="pill pill-warn" href="<?= e(url('practice', ['id' => (int) $r['id'], 'overdue' => 1])) ?>"><?= (int) $r['overdue'] ?></a>
        <?php else: ?><span class="muted">0</span><?php endif; ?>
      </td>

      <td class="c-golive">
        <?= e(fmt_date($r['target_go_live_date'])) ?>
        <?php if ($days !== null && $r['onboarding_state'] !== 'completed'): ?>
          <span class="row-sub <?= $days < 0 ? 'v-alert' : ($days <= 14 ? 'v-warn' : '') ?>">
            <?php if ($days < 0): ?><?= abs($days) ?> days past
            <?php elseif ($days === 0): ?>today
            <?php else: ?>in <?= $days ?> days<?php endif; ?>
          </span>
        <?php endif; ?>
      </td>

      <?php if ($can_practices): ?>
        <td class="c-act">
          <a class="btn btn-quiet btn-xs" href="<?= e(url('admin/practice-form', ['id' => (int) $r['id']])) ?>">Edit</a>
          <?php if ($can_delete && empty($r['is_archived'])): ?>
            <form method="post" action="<?= e(url('practice-delete')) ?>" class="inline-form"
                  data-confirm="Archive <?= e($r['name']) ?>? It disappears from the list but nothing is deleted.">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button type="submit" class="btn btn-quiet btn-xs">Archive</button>
            </form>
          <?php endif; ?>
        </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<p class="table-note">
  Progress counts Completed against every task except those marked Not Applicable.
  A practice is flagged when it has a blocked task or an overdue one.
</p>

<?php endif; ?>
