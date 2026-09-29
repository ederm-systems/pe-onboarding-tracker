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

<div class="table-scroll">
<table class="grid prod-grid">
  <thead>
    <tr>
      <th class="c-order">Order</th>
      <th>Name</th>
      <th class="ta-c">Colour</th>
      <th>Description</th>
      <th class="ta-c">Tasks</th>
      <th class="ta-c">Practices</th>
      <?php if ($can_delete): ?>
        <th class="ta-c">Active</th>
        <th class="c-act"></th>
      <?php endif; ?>
    </tr>
  </thead>
  <tbody data-grid>
  <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
    <tr data-row="<?= $id ?>" class="<?= empty($r['is_active']) ? 'row-muted' : '' ?>">
      <td class="c-order">
        <input form="gridform" type="number" step="10" name="rows[<?= $id ?>][sort_order]"
               value="<?= (int) $r['sort_order'] ?>" aria-label="Order">
      </td>
      <td>
        <input form="gridform" type="text" maxlength="120" name="rows[<?= $id ?>][name]"
               value="<?= e($r['name']) ?>" aria-label="Name">
      </td>
      <td class="ta-c">
        <input form="gridform" type="color" class="swatch" name="rows[<?= $id ?>][color]"
               value="<?= e($r['color'] ?: '#334155') ?>" aria-label="Colour">
      </td>
      <td>
        <input form="gridform" type="text" maxlength="500" name="rows[<?= $id ?>][description]"
               value="<?= e($r['description'] ?? '') ?>" aria-label="Description">
      </td>
      <td class="ta-c">
        <a href="<?= e(url('admin/tasks', ['product_id' => $id])) ?>"><?= (int) $r['task_count'] ?></a>
      </td>
      <td class="ta-c"><?= (int) $r['practice_count'] ?></td>
      <?php if ($can_delete): ?>
        <td class="ta-c">
          <input form="gridform" type="hidden" name="rows[<?= $id ?>][is_active]" value="0">
          <input form="gridform" type="checkbox" name="rows[<?= $id ?>][is_active]" value="1"
                 <?= !empty($r['is_active']) ? 'checked' : '' ?> aria-label="Active">
        </td>
        <td class="c-act">
          <form method="post" action="<?= e(url('product-delete')) ?>" class="inline-form row-act"
                data-confirm="Remove <?= e($r['name']) ?>? If any practice uses it, it will be deactivated instead of deleted.">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-danger btn-xs">Remove</button>
          </form>
        </td>
      <?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require APP_ROOT . '/templates/partials/savebar.php'; ?>

<p class="table-note">
  The colour identifies the product in chips, tabs and dashboard charts. Defaults come from the
  Practice Engine design system.
</p>
<p class="table-note">Lower order numbers appear first. Leave gaps of ten so you can slot a product in between later.</p>
