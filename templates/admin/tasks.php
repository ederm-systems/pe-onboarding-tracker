<?php
/**
 * Admin: the global task library for one product.
 *
 * A task added here appears in every practice onboarding this product,
 * as Not Started, with no further action needed. Edits across the whole
 * table are committed by the single Save button at the bottom.
 *
 * @var array  $products
 * @var int    $product_id
 * @var ?array $product
 * @var array  $categories
 * @var array  $assignees
 * @var array  $rows
 * @var bool   $include_inactive
 * @var bool   $can_delete
 */
$page_title = 'Task library';
?>
<div class="page-head">
  <div>
    <h1>Task library</h1>
    <p class="sub">
      These are global task definitions. Add one and it appears for every practice onboarding
      that product, tracked separately per practice.
    </p>
  </div>
</div>

<nav class="tabs" aria-label="Products">
  <?php foreach ($products as $p): ?>
    <a class="tab <?= ((int) $p['id'] === $product_id) ? 'on' : '' ?>"
       href="<?= e(url('admin/tasks', ['product_id' => (int) $p['id']])) ?>">
      <?= e($p['name']) ?><span class="tab-n"><?= (int) $p['task_count'] ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<?php if (!$product): ?>
  <div class="empty">
    <h2>No products yet</h2>
    <p><a href="<?= e(url('admin/products')) ?>">Add a product</a> before defining tasks.</p>
  </div>
<?php else: ?>

<section class="card">
  <h2 class="card-h">Add a task to <?= e($product['name']) ?></h2>
  <form method="post" action="<?= e(url('task-save')) ?>" class="row-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="product_id" value="<?= $product_id ?>">
    <input type="hidden" name="is_active" value="1">

    <label class="field f-grow"><span>Task name <abbr class="req" title="Required">*</abbr></span>
      <input type="text" name="name" required maxlength="200" placeholder="e.g. Configure SMS number"></label>

    <label class="field"><span>Category <abbr class="req" title="Required">*</abbr></span>
      <select name="category_id" required>
        <?php foreach ($categories as $c): ?>
          <option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option>
        <?php endforeach; ?>
      </select></label>

    <label class="field"><span>Default assignee</span>
      <select name="default_assignee_id">
        <option value="">Nobody by default</option>
        <?php foreach ($assignees as $a): ?>
          <option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option>
        <?php endforeach; ?>
      </select></label>

    <label class="field f-grow"><span>Description</span>
      <input type="text" name="description" maxlength="500" placeholder="Optional detail shown under the task name"></label>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-sm">Add task</button>
    </div>
  </form>
  <p class="muted">It will land at the bottom of <?= e($product['name']) ?>'s list. Use the arrows to move it.</p>
</section>

<div class="toolbar">
  <span class="toolbar-label"><?= count($rows) ?> task<?= count($rows) === 1 ? '' : 's' ?></span>
  <a class="btn btn-quiet btn-sm"
     href="<?= e(url('admin/tasks', ['product_id' => $product_id, 'inactive' => $include_inactive ? null : 1])) ?>">
    <?= $include_inactive ? 'Hide deactivated' : 'Show deactivated' ?>
  </a>
  <form method="post" action="<?= e(url('tasks-renumber')) ?>" class="inline-form">
    <?= Csrf::field() ?>
    <input type="hidden" name="product_id" value="<?= $product_id ?>">
    <button type="submit" class="btn btn-quiet btn-sm">Tidy order numbers</button>
  </form>
</div>

<?php if (!$rows): ?>
  <div class="empty"><h3>No tasks yet</h3><p>Add the first one above.</p></div>
<?php else: ?>

<form method="post" action="<?= e(url('tasks-save-all')) ?>" id="gridform">
  <?= Csrf::field() ?>
  <input type="hidden" name="product_id" value="<?= $product_id ?>">
  <!-- Filled in by the drag handler with the task ids in their new order.
       Left empty when nothing has been reordered. -->
  <input type="hidden" name="task_order" value="" data-task-order>
</form>

<p class="table-note" style="margin-bottom:.6rem">
  Tasks are grouped by category. Drag a row by its handle to reorder, or use the arrows. The new
  order is applied when you save, along with any other edits. Moving a task to another category
  is done with its Category dropdown, not by dragging.
</p>

<?php
  /**
   * A real table, so a heading cannot drift out of line with the cells
   * beneath it. The editable cells belong to #gridform through the HTML
   * `form` attribute, which is what lets one Save button commit the
   * whole table without a form illegally wrapping the cells.
   *
   * data-grid and data-sortable sit on the tbody rather than the table,
   * so the reorder and reset handlers move rows within their proper
   * parent.
   */
  $cols = $can_delete ? 7 : 6;
?>
<div class="table-scroll">
<table class="grid lib-grid">
  <thead>
    <tr>
      <th class="c-move">Move</th>
      <th>Task</th>
      <th>Category</th>
      <th>Default assignee</th>
      <th>Description</th>
      <th class="ta-c">Active</th>
      <?php if ($can_delete): ?><th class="c-act"></th><?php endif; ?>
    </tr>
  </thead>
  <tbody data-grid data-sortable>
  <?php $lastCat = null; foreach ($rows as $r): $id = (int) $r['id'];
      // A banner row each time the category changes. It carries no
      // data-row, so the drag handler and the arrows read the task rows
      // as a plain list and never try to move a banner.
      if ($lastCat !== (int) $r['category_id']):
          $lastCat = (int) $r['category_id']; ?>
        <tr class="cat-row" style="--cat: <?= e($r['category_color'] ?: '#94A3B8') ?>">
          <td colspan="<?= $cols ?>">
            <span class="cat-swatch"></span><?= e($r['category_name']) ?>
          </td>
        </tr>
      <?php endif; ?>
    <tr data-row="<?= $id ?>" class="<?= empty($r['is_active']) ? 'row-muted' : '' ?>"
        style="--cat: <?= e($r['category_color'] ?: '#94A3B8') ?>">

      <td class="c-move">
        <span class="drag-handle" title="Drag to reorder" aria-hidden="true">⠿</span>

        <?php /* The arrows are the keyboard and touch path, and the fallback
                 when JavaScript is off. With JavaScript on they reorder the
                 row in place instead of reloading the page. */ ?>
        <form method="post" action="<?= e(url('task-move')) ?>" class="inline-form" data-leaves-page>
          <?= Csrf::field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="dir" value="up">
          <button type="submit" class="btn btn-icon" data-move="up"
                  title="Move up" aria-label="Move <?= e($r['name']) ?> up">↑</button>
        </form>
        <form method="post" action="<?= e(url('task-move')) ?>" class="inline-form" data-leaves-page>
          <?= Csrf::field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="dir" value="down">
          <button type="submit" class="btn btn-icon" data-move="down"
                  title="Move down" aria-label="Move <?= e($r['name']) ?> down">↓</button>
        </form>
      </td>

      <td>
        <input form="gridform" type="text" maxlength="200" name="rows[<?= $id ?>][name]"
               value="<?= e($r['name']) ?>" aria-label="Task name">
      </td>

      <td>
        <select form="gridform" name="rows[<?= $id ?>][category_id]" aria-label="Category">
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int) $c['id'] ?>" <?= ((int) $r['category_id'] === (int) $c['id']) ? 'selected' : '' ?>>
              <?= e($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </td>

      <td>
        <select form="gridform" name="rows[<?= $id ?>][default_assignee_id]" aria-label="Default assignee">
          <option value="">Nobody by default</option>
          <?php foreach ($assignees as $a): ?>
            <option value="<?= (int) $a['id'] ?>"
              <?= ((int) ($r['default_assignee_id'] ?? 0) === (int) $a['id']) ? 'selected' : '' ?>>
              <?= e($a['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </td>

      <td>
        <input form="gridform" type="text" maxlength="500" name="rows[<?= $id ?>][description]"
               value="<?= e($r['description'] ?? '') ?>" aria-label="Description">
      </td>

      <td class="ta-c">
        <?php if ($can_delete): ?>
          <input form="gridform" type="hidden" name="rows[<?= $id ?>][is_active]" value="0">
          <input form="gridform" type="checkbox" name="rows[<?= $id ?>][is_active]" value="1"
                 <?= !empty($r['is_active']) ? 'checked' : '' ?> aria-label="Active">
        <?php else: ?>
          <span class="badge <?= !empty($r['is_active']) ? 'st-completed' : 'st-not-started' ?>"
                title="Only the administrator can change this"><?= !empty($r['is_active']) ? 'Yes' : 'No' ?></span>
        <?php endif; ?>
      </td>

      <?php if ($can_delete): ?>
      <td class="c-act">
        <span class="row-act">
          <?php if (!empty($r['is_active'])): ?>
            <form method="post" action="<?= e(url('task-delete')) ?>" class="inline-form" data-leaves-page
                  data-confirm="Deactivate &quot;<?= e($r['name']) ?>&quot;? It disappears from every practice, and any status already recorded is kept.">
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <button type="submit" class="btn btn-quiet btn-xs">Deactivate</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?= e(url('task-reactivate')) ?>" class="inline-form" data-leaves-page>
              <?= Csrf::field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <button type="submit" class="btn btn-quiet btn-xs">Reactivate</button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= e(url('task-delete')) ?>" class="inline-form" data-leaves-page
                data-confirm="Permanently delete &quot;<?= e($r['name']) ?>&quot; and the status recorded against it for every practice? This cannot be undone.">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="hard" value="1">
            <button type="submit" class="btn btn-danger btn-xs">Delete</button>
          </form>
        </span>
      </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require APP_ROOT . '/templates/partials/savebar.php'; ?>

<p class="table-note">
  A default assignee is who normally picks the task up. Any practice that has not chosen someone
  of its own shows that person, so changing it here moves every one of them at once. Setting an
  assignee on a specific practice overrides the default for that practice only.
</p>

<p class="table-note">
  <?php if ($can_delete): ?>
    Deactivate rather than delete when a task is simply no longer part of the process. Deleting
    also removes the status, assignee, due date, and notes recorded against it for every practice.
  <?php else: ?>
    You can add, edit, reorder and reassign tasks here. Removing one, or switching it off, is the
    administrator's to do, because it affects every practice at once.
  <?php endif; ?>
</p>

<?php endif; ?>
<?php endif; ?>
