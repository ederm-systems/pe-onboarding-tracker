<?php
/**
 * Admin: task categories. One Save button for the whole table.
 * @var array $rows
 */
$page_title = 'Categories';
?>
<div class="page-head">
  <div>
    <h1>Task categories</h1>
    <p class="sub">Categories group tasks inside each product. A new category is available to every product immediately.</p>
  </div>
</div>

<section class="card">
  <h2 class="card-h">Add a category</h2>
  <form method="post" action="<?= e(url('category-save')) ?>" class="row-form">
    <?= Csrf::field() ?>
    <label class="field f-grow">
      <span>Name <abbr class="req" title="Required">*</abbr></span>
      <input type="text" name="name" required maxlength="120" placeholder="e.g. Data Migration">
    </label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-sm">Add category</button>
    </div>
  </form>
</section>

<form method="post" action="<?= e(url('categories-save-all')) ?>" id="gridform"><?= Csrf::field() ?></form>

<div class="edit-rows cols-category" data-grid>
  <div class="edit-head">
    <span>Order</span><span>Name</span><span class="ta-c">Tasks</span><?php if ($can_delete): ?><span class="ta-c">Active</span><?php endif; ?>
  </div>

  <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
    <div class="edit-line <?= empty($r['is_active']) ? 'is-off' : '' ?>" data-row="<?= $id ?>">
      <div class="edit-row">
        <label class="cell"><span class="cell-lab">Order</span>
          <input form="gridform" type="number" step="10" name="rows[<?= $id ?>][sort_order]"
                 value="<?= (int) $r['sort_order'] ?>"></label>

        <label class="cell"><span class="cell-lab">Name</span>
          <input form="gridform" type="text" maxlength="120" name="rows[<?= $id ?>][name]"
                 value="<?= e($r['name']) ?>"></label>

        <span class="cell ta-c"><span class="cell-lab">Tasks</span><?= (int) $r['task_count'] ?></span>

        <?php if ($can_delete): ?>
          <label class="cell ta-c"><span class="cell-lab">Active</span>
            <input form="gridform" type="hidden" name="rows[<?= $id ?>][is_active]" value="0">
            <input form="gridform" type="checkbox" name="rows[<?= $id ?>][is_active]" value="1"
                   <?= !empty($r['is_active']) ? 'checked' : '' ?>></label>
        <?php endif; ?>
      </div>

      <?php if ($can_delete): ?>
      <form method="post" action="<?= e(url('category-delete')) ?>" class="edit-row-side"
            data-confirm="Remove <?= e($r['name']) ?>? If tasks use it, it will be deactivated instead of deleted.">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger btn-xs">Remove</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require APP_ROOT . '/templates/partials/savebar.php'; ?>
