<?php
/**
 * Admin: add or edit a practice, including its product selection.
 * @var ?array $practice
 * @var array  $products
 * @var array  $selected
 */
$editing    = $practice !== null;
$page_title = $editing ? ('Edit ' . $practice['name']) : 'Add practice';
?>
<p class="crumbs">
  <a href="<?= e(url('admin/practices')) ?>">Practices</a> <span>/</span>
  <?= $editing ? e($practice['name']) : 'New practice' ?>
</p>

<div class="narrow-wide">
<form method="post" action="<?= e(url('practice-save')) ?>">
  <?= Csrf::field() ?>
  <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int) $practice['id'] ?>"><?php endif; ?>

  <section class="card">
    <h1 class="card-h"><?= $editing ? 'Practice details' : 'Add a practice' ?></h1>

    <div class="grid-2">
      <label class="field">
        <span>Practice name <abbr class="req" title="Required">*</abbr></span>
        <input type="text" name="name" required maxlength="180" value="<?= e($practice['name'] ?? '') ?>">
      </label>

      <label class="field">
        <span>Location</span>
        <input type="text" name="location" maxlength="180" placeholder="City, State"
               value="<?= e($practice['location'] ?? '') ?>">
      </label>

      <label class="field">
        <span>Onboarding state</span>
        <select name="onboarding_state">
          <?php foreach (PRACTICE_STATES as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= (($practice['onboarding_state'] ?? 'active') === $k) ? 'selected' : '' ?>>
              <?= e($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="field">
        <span>Target go-live date</span>
        <input type="date" name="target_go_live_date" value="<?= e($practice['target_go_live_date'] ?? '') ?>">
      </label>
    </div>

    <label class="field">
      <span>Notes</span>
      <textarea name="notes" rows="3" placeholder="Context for the team. No patient information."><?= e($practice['notes'] ?? '') ?></textarea>
    </label>

    <?php /* Archiving is a form of removal, so it stays with the
             administrator. */ ?>
    <?php if ($editing && $can_delete): ?>
      <label class="check-line">
        <input type="checkbox" name="is_archived" value="1" <?= !empty($practice['is_archived']) ? 'checked' : '' ?>>
        <span>Archived, hide from every list</span>
      </label>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2 class="card-h">Products being onboarded</h2>
    <p class="muted">
      Tick the products this practice is onboarding. Every active task defined against a ticked
      product appears in this practice's tracker automatically. Unticking a product hides its tasks
      but keeps the status already recorded, so you can tick it again later without losing anything.
    </p>

    <?php if (!$products): ?>
      <p class="muted">No products exist yet. <a href="<?= e(url('admin/products')) ?>">Add a product first.</a></p>
    <?php else: ?>
      <ul class="pick-list">
        <?php foreach ($products as $p): $on = in_array((int) $p['id'], array_map('intval', $selected), true); ?>
          <li class="<?= empty($p['is_active']) ? 'is-off' : '' ?>">
            <label>
              <input type="checkbox" name="product_ids[]" value="<?= (int) $p['id'] ?>" <?= $on ? 'checked' : '' ?>>
              <span class="pick-name"><?= e($p['name']) ?></span>
              <span class="pick-meta">
                <?= (int) $p['task_count'] ?> task<?= (int) $p['task_count'] === 1 ? '' : 's' ?>
                <?php if (empty($p['is_active'])): ?> · inactive<?php endif; ?>
              </span>
            </label>
            <?php if (!empty($p['description'])): ?>
              <span class="pick-desc"><?= e($p['description']) ?></span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <div class="form-actions form-actions-sticky">
    <button type="submit" class="btn btn-primary">Save practice</button>
    <a class="btn btn-quiet" href="<?= $editing ? e(url('practice', ['id' => (int) $practice['id']])) : e(url('admin/practices')) ?>">Cancel</a>
  </div>
</form>
</div>
