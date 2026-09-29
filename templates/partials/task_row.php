<?php
/**
 * One task row inside a practice.
 *
 * @var array $t            derived task row
 * @var int   $practice_id
 * @var bool  $is_admin
 * @var ?int  $member_id
 * @var array $assignees
 */
$tid    = (int) $t['task_id'];

// The administrator may change anything. A team member may change only
// the tasks that are theirs. The server checks this again on save; this
// just avoids showing controls that would be refused.
$can_edit = !empty($is_admin)
    || (isset($member_id) && $member_id !== null
        && (int) ($t['assignee_id'] ?? 0) === (int) $member_id);
$status = (string) $t['status'];
$rowCls = 'task';
if ($status === 'completed')      { $rowCls .= ' task-done'; }
if ($status === 'not_applicable') { $rowCls .= ' task-na'; }
if ($status === 'blocked')        { $rowCls .= ' task-blocked'; }
if (!empty($t['is_overdue']))     { $rowCls .= ' task-overdue'; }
?>
<tr class="<?= $rowCls ?><?= $can_edit ? ' task-mine' : '' ?>" data-task="<?= $tid ?>">

  <?php if ($can_edit): ?>
    <td class="c-check">
      <input type="checkbox" name="task_ids[]" value="<?= $tid ?>" class="bulk-check"
             aria-label="Select <?= e($t['task_name']) ?>">
    </td>
  <?php endif; ?>

  <td class="c-task">
    <span class="task-name"><?= e($t['task_name']) ?></span>
    <?php if (!empty($t['description'])): ?>
      <span class="task-desc"><?= e($t['description']) ?></span>
    <?php endif; ?>
    <span class="task-meta">
      <span class="chip"><?= e($t['category_name']) ?></span>
      <?php if (!empty($t['is_overdue'])): ?>
        <span class="chip chip-alert">Overdue<?php
          $d = $t['days_until'] ?? null;
          if ($d !== null) { echo ' by ' . abs((int) $d) . 'd'; }
        ?></span>
      <?php endif; ?>
    </span>
  </td>

  <td class="c-status">
    <?php if ($can_edit): ?>
      <select class="inline-input status-select <?= e(status_class($status)) ?>"
              data-field="status" data-task="<?= $tid ?>" data-practice="<?= (int) $practice_id ?>"
              aria-label="Status for <?= e($t['task_name']) ?>">
        <?php foreach (STATUSES as $k => $label): ?>
          <option value="<?= e($k) ?>" <?= $k === $status ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <?php require __DIR__ . '/status_badge.php'; ?>
    <?php endif; ?>
  </td>

  <td class="c-assignee">
    <?php if ($can_edit): ?>
      <select class="inline-input" data-field="assignee_id" data-task="<?= $tid ?>"
              data-practice="<?= (int) $practice_id ?>"
              aria-label="Assignee for <?= e($t['task_name']) ?>">
        <option value="">Unassigned</option>
        <?php foreach ($assignees as $a): ?>
          <option value="<?= (int) $a['id'] ?>"
            <?= ((int) ($t['assignee_id'] ?? 0) === (int) $a['id']) ? 'selected' : '' ?>>
            <?= e($a['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <?= $t['assignee_name']
            ? person($t['assignee_name'])
            : '<span class="muted">Unassigned</span>' ?>
    <?php endif; ?>
  </td>

  <td class="c-due">
    <?php if ($can_edit): ?>
      <input type="date" class="inline-input" value="<?= e($t['due_date'] ?? '') ?>"
             data-field="due_date" data-task="<?= $tid ?>" data-practice="<?= (int) $practice_id ?>"
             aria-label="Due date for <?= e($t['task_name']) ?>">
    <?php else: ?>
      <?= e(fmt_date($t['due_date'] ?? null)) ?>
    <?php endif; ?>
  </td>

  <td class="c-notes">
    <?php if ($can_edit): ?>
      <textarea class="inline-input notes-input" rows="1" placeholder="Add a note"
                data-field="notes" data-task="<?= $tid ?>" data-practice="<?= (int) $practice_id ?>"
                aria-label="Notes for <?= e($t['task_name']) ?>"><?= e($t['notes'] ?? '') ?></textarea>
    <?php else: ?>
      <?= $t['notes'] ? e($t['notes']) : '<span class="muted">—</span>' ?>
    <?php endif; ?>
  </td>

  <td class="c-updated"><span class="muted"><?= e(fmt_ago($t['updated_at'] ?? null)) ?></span></td>
</tr>
