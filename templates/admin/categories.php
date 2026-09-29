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
    <p class="sub">
      Categories group tasks inside each product. A new category is available to every product
      immediately. Tick <strong>Practice's own</strong> for a category whose tasks are the
      practice's to do, such as Customer: open tasks there are the only ones listed on the
      shared practice page, under "What we need from you".
    </p>
  </div>
</div>

<section class="card">
  <h2 class="card-h">Add a category</h2>
  <form method="post" action="<?= e(url('category-save')) ?>" class="row-form">
    <?= Csrf::field() ?>
    <label class="field f-grow">
      <span>Name <abbr class="req" title="Required">*</abbr></span>
      <input type="text" name="name" required maxlength="120" placeholder="e.g. Customer">
    </label>
    <label class="field">
      <span>Practice's own work</span>
      <span class="check-line"><input type="checkbox" name="is_customer" value="1">
        <span class="muted">Shown to the practice</span></span>
    </label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-sm">Add category</button>
    </div>
  </form>
</section>

<form method="post" action="<?= e(url('categories-save-all')) ?>" id="gridform"><?= Csrf::field() ?></form>

<div class="table-scroll">
<table class="grid cat-grid">
  <thead>
    <tr>
      <th class="c-order">Order</th>
      <th>Name</th>
      <th class="ta-c">Practice's own</th>
      <th class="ta-c">Tasks</th>
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
        <input form="gridform" type="hidden" name="rows[<?= $id ?>][is_customer]" value="0">
        <input form="gridform" type="checkbox" name="rows[<?= $id ?>][is_customer]" value="1"
               <?= !empty($r['is_customer']) ? 'checked' : '' ?>
               aria-label="Practice's own"
               title="Open tasks here are listed on the practice's own page">
      </td>
      <td class="ta-c"><?= (int) $r['task_count'] ?></td>
      <?php if ($can_delete): ?>
        <td class="ta-c">
          <input form="gridform" type="hidden" name="rows[<?= $id ?>][is_active]" value="0">
          <input form="gridform" type="checkbox" name="rows[<?= $id ?>][is_active]" value="1"
                 <?= !empty($r['is_active']) ? 'checked' : '' ?> aria-label="Active">
        </td>
        <td class="c-act">
          <form method="post" action="<?= e(url('category-delete')) ?>" class="inline-form row-act"
                data-confirm="Remove <?= e($r['name']) ?>? If tasks use it, it will be deactivated instead of deleted.">
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
