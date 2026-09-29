<?php
/**
 * Admin: products.
 *
 * Every editable cell belongs to one form, declared empty just below and
 * referenced by the HTML `form` attribute. That way a single Save button
 * commits the whole table, and the per-row Remove buttons can stay as
 * their own forms without illegally nesting inside it.
 *
 * @var array $rows
 */
$page_title = 'Products';
?>
<div class="page-head">
  <div>
    <h1>Products</h1>
    <p class="sub">Practice Engine products available for onboarding. Deactivating one hides its tasks everywhere without deleting anything.</p>
  </div>
</div>

<section class="card">
  <h2 class="card-h">Add a product</h2>
  <form method="post" action="<?= e(url('product-save')) ?>" class="row-form">
    <?= Csrf::field() ?>
    <label class="field f-grow">
      <span>Name <abbr class="req" title="Required">*</abbr></span>
      <input type="text" name="name" required maxlength="120" placeholder="e.g. Patient Portal">
    </label>
    <label class="field">
      <span>Colour</span>
      <input type="color" name="color" value="#334155">
    </label>
    <label class="field f-grow">
      <span>Description</span>
      <input type="text" name="description" maxlength="500" placeholder="One line, shown when picking products">
    </label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-sm">Add product</button>
    </div>
  </form>
</section>

<form method="post" action="<?= e(url('products-save-all')) ?>" id="gridform"><?= Csrf::field() ?></form>

<div class="edit-rows cols-product" data-grid>
  <div class="edit-head">
    <span>Order</span><span>Name</span><span class="ta-c">Colour</span><span>Description</span>
    <span class="ta-c">Tasks</span><span class="ta-c">Practices</span><?php if ($can_delete): ?><span class="ta-c">Active</span><?php endif; ?>
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

        <label class="cell ta-c"><span class="cell-lab">Colour</span>
          <input form="gridform" type="color" class="swatch" name="rows[<?= $id ?>][color]"
                 value="<?= e($r['color'] ?: '#334155') ?>"></label>

        <label class="cell"><span class="cell-lab">Description</span>
          <input form="gridform" type="text" maxlength="500" name="rows[<?= $id ?>][description]"
                 value="<?= e($r['description'] ?? '') ?>"></label>

        <span class="cell ta-c"><span class="cell-lab">Tasks</span>
          <a href="<?= e(url('admin/tasks', ['product_id' => $id])) ?>"><?= (int) $r['task_count'] ?></a></span>

        <span class="cell ta-c"><span class="cell-lab">Practices</span><?= (int) $r['practice_count'] ?></span>

        <?php if ($can_delete): ?>
          <label class="cell ta-c"><span class="cell-lab">Active</span>
            <input form="gridform" type="hidden" name="rows[<?= $id ?>][is_active]" value="0">
            <input form="gridform" type="checkbox" name="rows[<?= $id ?>][is_active]" value="1"
                   <?= !empty($r['is_active']) ? 'checked' : '' ?>></label>
        <?php endif; ?>
      </div>

      <?php if ($can_delete): ?>
      <form method="post" action="<?= e(url('product-delete')) ?>" class="edit-row-side"
            data-confirm="Remove <?= e($r['name']) ?>? If any practice uses it, it will be deactivated instead of deleted.">
        <?= Csrf::field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <button type="submit" class="btn btn-danger btn-xs">Remove</button>
      </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require APP_ROOT . '/templates/partials/savebar.php'; ?>

<p class="table-note">
  The colour identifies the product in chips, tabs and dashboard charts. Defaults come from the
  Practice Engine design system.
</p>
<p class="table-note">Lower order numbers appear first. Leave gaps of ten so you can slot a product in between later.</p>
