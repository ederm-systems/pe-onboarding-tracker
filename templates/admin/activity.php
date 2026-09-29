<?php
/**
 * Admin: the audit log.
 *
 * Filters run in the database across the whole table, not over a recent
 * slice, so a question about something that happened months ago has an
 * answer here. Results come back a page at a time.
 *
 * @var array $result    rows, total, page, pages, from, to
 * @var array $filters
 * @var array $choices   the values that actually occur in the log
 * @var array $practices
 */
$page_title = 'Activity';

$rows   = $result['rows'];
$total  = (int) $result['total'];
$pageNo = (int) $result['page'];
$pages  = (int) $result['pages'];

// Whether any filter is on decides between "nothing has happened yet"
// and "nothing matches what you asked for", which are different
// problems with different fixes.
$isFiltered = ($filters['q'] ?? '') !== ''
    || ($filters['practice_id'] ?? null) !== null
    || ($filters['actor'] ?? null) !== null
    || ($filters['action'] ?? null) !== null
    || ($filters['entity'] ?? null) !== null
    || ($filters['field'] ?? null) !== null
    || ($filters['from'] ?? null) !== null
    || ($filters['to'] ?? null) !== null;

/** A stored value as something readable, or null when there was none. */
$val = static function (?string $v): ?string {
    $v = trim((string) $v);
    return $v === '' ? null : $v;
};
?>
<div class="page-head">
  <div>
    <h1>Activity</h1>
    <p class="sub">
      Every change ever recorded, oldest kept indefinitely. This is also what drives
      the Last updated column on the dashboard.
    </p>
  </div>
</div>

<form class="filters" method="get" action="index.php">
  <input type="hidden" name="p" value="admin/activity">

  <label class="f-field f-grow">
    <span>Search</span>
    <input type="search" name="q" value="<?= e($filters['q'] ?? '') ?>"
           placeholder="Practice, task, or a value that changed">
  </label>

  <label class="f-field">
    <span>Practice</span>
    <select name="practice_id">
      <option value="">All practices</option>
      <?php if (!empty($choices['has_null_practice'])): ?>
        <option value="none" <?= (($filters['practice_id'] ?? null) === 'none') ? 'selected' : '' ?>>
          No practice
        </option>
      <?php endif; ?>
      <?php foreach ($practices as $p): ?>
        <option value="<?= (int) $p['id'] ?>"
          <?= ((int) ($filters['practice_id'] ?? 0) === (int) $p['id']) ? 'selected' : '' ?>>
          <?= e($p['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="f-field">
    <span>Person</span>
    <select name="actor">
      <option value="">Anyone</option>
      <?php foreach ($choices['actors'] as $a): ?>
        <option value="<?= e($a) ?>" <?= (($filters['actor'] ?? '') === $a) ? 'selected' : '' ?>>
          <?= e($a) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="f-field">
    <span>Action</span>
    <select name="action">
      <option value="">Any action</option>
      <?php foreach ($choices['actions'] as $a): ?>
        <option value="<?= e($a) ?>" <?= (($filters['action'] ?? '') === $a) ? 'selected' : '' ?>>
          <?= e(Activity::label($a)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="f-field">
    <span>Type</span>
    <select name="entity">
      <option value="">Anything</option>
      <?php foreach ($choices['entities'] as $en): ?>
        <option value="<?= e($en) ?>" <?= (($filters['entity'] ?? '') === $en) ? 'selected' : '' ?>>
          <?= e(Activity::label($en)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="f-field">
    <span>Field changed</span>
    <select name="field">
      <option value="">Any field</option>
      <?php if (!empty($choices['has_null_field'])): ?>
        <option value="none" <?= (($filters['field'] ?? null) === 'none') ? 'selected' : '' ?>>
          No single field
        </option>
      <?php endif; ?>
      <?php foreach ($choices['fields'] as $fl): ?>
        <option value="<?= e($fl) ?>" <?= (($filters['field'] ?? '') === $fl) ? 'selected' : '' ?>>
          <?= e(Activity::label($fl)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="f-field">
    <span>From</span>
    <input type="date" name="from" value="<?= e($filters['from'] ?? '') ?>">
  </label>

  <label class="f-field">
    <span>To</span>
    <input type="date" name="to" value="<?= e($filters['to'] ?? '') ?>">
  </label>

  <div class="f-actions">
    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
    <a class="btn btn-quiet btn-sm" href="<?= e(url('admin/activity')) ?>">Clear</a>
  </div>
</form>

<?php if (!$rows): ?>
  <div class="empty">
    <?php if ($isFiltered): ?>
      <h2>Nothing matches</h2>
      <p>No change in the log fits those filters. <a href="<?= e(url('admin/activity')) ?>">Clear them</a> to see everything.</p>
    <?php else: ?>
      <h2>Nothing recorded yet</h2>
      <p>Changes will appear here as they happen.</p>
    <?php endif; ?>
  </div>
<?php else: ?>

<p class="filter-note">
  Showing <strong><?= (int) $result['from'] ?> to <?= (int) $result['to'] ?></strong>
  of <?= number_format($total) ?> change<?= $total === 1 ? '' : 's' ?><?= $isFiltered ? ' that match' : '' ?>.
</p>

<div class="table-scroll">
<table class="grid log-grid">
  <thead>
    <tr>
      <th>When</th>
      <th>Practice</th>
      <th>What changed</th>
      <th>From</th>
      <th>To</th>
      <th>By</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($rows as $r):
      $old = $val($r['old_value'] ?? null);
      $new = $val($r['new_value'] ?? null);
  ?>
    <tr>
      <td class="c-when">
        <?= e(date('j M Y, g:ia', strtotime((string) $r['created_at']))) ?>
        <span class="row-sub"><?= e(fmt_ago($r['created_at'])) ?></span>
      </td>

      <td class="c-name">
        <?php if (!empty($r['practice_name'])): ?>
          <a href="<?= e(url('practice', ['id' => (int) $r['practice_id']])) ?>"><?= e($r['practice_name']) ?></a>
        <?php else: ?>
          <span class="muted">No practice</span>
        <?php endif; ?>
      </td>

      <td>
        <?php if (!empty($r['task_name'])): ?>
          <strong><?= e($r['task_name']) ?></strong>
          <?php if (!empty($r['product_name'])): ?>
            <span class="row-sub"><?= e($r['product_name']) ?></span>
          <?php endif; ?>
        <?php endif; ?>
        <span class="log-what"><?= e($r['summary'] ?? (Activity::label($r['action']) . ' ' . Activity::label($r['entity']))) ?></span>
        <span class="log-tags">
          <span class="chip"><?= e(Activity::label($r['entity'])) ?></span>
          <span class="chip"><?= e(Activity::label($r['action'])) ?></span>
          <?php if (!empty($r['field'])): ?>
            <span class="chip"><?= e(Activity::label($r['field'])) ?></span>
          <?php endif; ?>
        </span>
      </td>

      <?php /* The before and after are recorded on every change but were
               never shown. Long values are clipped in the cell and the
               whole thing is on the title attribute.

               When neither side was recorded the cells are left blank.
               Signing in, or creating a share link, changes no value at
               all, and printing "Empty" twice would suggest something
               had been cleared. "Empty" is only said where it means
               something: one side of a real change was blank, so a
               value was either set from nothing or cleared to it. */ ?>
      <?php if ($old === null && $new === null): ?>
        <td class="c-was"></td>
        <td class="c-now"></td>
      <?php else: ?>
        <td class="c-was">
          <?php if ($old !== null): ?>
            <span class="log-old" title="<?= e($old) ?>"><?= e($old) ?></span>
          <?php else: ?>
            <span class="muted">Empty</span>
          <?php endif; ?>
        </td>
        <td class="c-now">
          <?php if ($new !== null): ?>
            <span class="log-new" title="<?= e($new) ?>"><?= e($new) ?></span>
          <?php else: ?>
            <span class="muted">Empty</span>
          <?php endif; ?>
        </td>
      <?php endif; ?>

      <td><?= person($r['actor'], 'System') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if ($pages > 1): ?>
  <nav class="pager" aria-label="Pages of activity">
    <?php if ($pageNo > 1): ?>
      <a class="btn btn-sm" href="<?= e(url_with(['page' => $pageNo - 1])) ?>">Newer</a>
    <?php else: ?>
      <span class="btn btn-sm" aria-disabled="true">Newer</span>
    <?php endif; ?>

    <span class="pager-at">Page <?= $pageNo ?> of <?= $pages ?></span>

    <?php if ($pageNo < $pages): ?>
      <a class="btn btn-sm" href="<?= e(url_with(['page' => $pageNo + 1])) ?>">Older</a>
    <?php else: ?>
      <span class="btn btn-sm" aria-disabled="true">Older</span>
    <?php endif; ?>
  </nav>
<?php endif; ?>

<p class="table-note">
  The log is append-only: entries are never edited or removed, including when the thing
  they describe is deleted. Permanently deleting a practice leaves its history here, with
  the practice column showing no practice.
</p>

<?php endif; ?>
