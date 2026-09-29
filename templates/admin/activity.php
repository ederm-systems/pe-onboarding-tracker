<?php
/**
 * Admin: recent activity across everything.
 * @var array $rows
 */
$page_title = 'Activity';
?>
<div class="page-head">
  <div>
    <h1>Activity</h1>
    <p class="sub">The 300 most recent changes. This is what drives the Last updated column on the dashboard.</p>
  </div>
</div>

<?php if (!$rows): ?>
  <div class="empty"><h2>Nothing recorded yet</h2><p>Changes will appear here as they happen.</p></div>
<?php else: ?>
<div class="table-scroll">
<table class="grid">
  <thead>
    <tr><th>When</th><th>Practice</th><th>What changed</th><th>By</th></tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="c-when">
        <?= e(date('M j, g:ia', strtotime((string) $r['created_at']))) ?>
        <span class="row-sub muted"><?= e(fmt_ago($r['created_at'])) ?></span>
      </td>
      <td class="c-name">
        <?php if (!empty($r['practice_name'])): ?>
          <a href="<?= e(url('practice', ['id' => (int) $r['practice_id']])) ?>"><?= e($r['practice_name']) ?></a>
        <?php else: ?><span class="muted">—</span><?php endif; ?>
      </td>
      <td>
        <?php if (!empty($r['task_name'])): ?>
          <strong><?= e($r['task_name']) ?></strong><br>
        <?php endif; ?>
        <?= e($r['summary'] ?? ($r['action'] . ' ' . $r['entity'])) ?>
      </td>
      <td><?= person($r['actor'], 'System') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
