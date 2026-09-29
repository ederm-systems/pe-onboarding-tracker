<?php
/**
 * Admin: people, who are also the user accounts.
 *
 * A real table rather than a CSS grid. The row mixes text inputs, a
 * badge, a small form and a checkbox, and with a grid the header and
 * the fields kept drifting out of line because the action buttons sat
 * outside the grid. A table cannot misalign.
 *
 * The editable cells belong to the empty form declared above via the
 * HTML `form` attribute, so one Save button commits the whole table
 * without any form wrapping table cells.
 *
 * @var array $rows
 */
$page_title = 'People';
?>
<div class="page-head">
  <div>
    <h1>People</h1>
    <p class="sub">
      Everyone here can be assigned tasks. Give someone a PIN and they can also sign in, see
      everything, and update the tasks that are theirs.
    </p>
  </div>
</div>

<section class="card">
  <h2 class="card-h">Add a person</h2>
  <form method="post" action="<?= e(url('assignee-save')) ?>" class="row-form">
    <?= Csrf::field() ?>
    <label class="field f-grow"><span>First name <abbr class="req" title="Required">*</abbr></span>
      <input type="text" name="first_name" required maxlength="80"></label>
    <label class="field f-grow"><span>Last name</span>
      <input type="text" name="last_name" maxlength="80"></label>
    <label class="field f-grow"><span>Role</span>
      <input type="text" name="role_title" maxlength="120" placeholder="e.g. Implementation"></label>
    <label class="field"><span>Access</span>
      <select name="access_level">
        <?php foreach (Auth::LEVELS as $k => $label): ?>
          <option value="<?= e($k) ?>"><?= e($label) ?></option>
        <?php endforeach; ?>
      </select></label>
    <label class="field"><span>6-digit PIN</span>
      <input type="text" name="pin" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
             placeholder="481920" autocomplete="off"></label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary btn-sm">Add person</button>
    </div>
  </form>
  <p class="muted">Leave the PIN blank for somebody who should be assignable but not able to sign in.</p>
</section>

<form method="post" action="<?= e(url('assignees-save-all')) ?>" id="gridform"><?= Csrf::field() ?></form>

<div class="table-scroll">
<table class="grid people-grid">
  <thead>
    <tr>
      <th>First name</th>
      <th>Last name</th>
      <th>Role</th>
      <th>Access</th>
      <th class="ta-c">Sign-in</th>
      <th>Set a new PIN</th>
      <th class="ta-c">Active</th>
      <th class="c-act"></th>
    </tr>
  </thead>
  <?php /* data-grid sits on the tbody, not the table, so the Reset
           button puts rows back inside their proper parent. */ ?>
  <tbody data-grid>
    <?php foreach ($rows as $r): $id = (int) $r['id']; ?>
      <tr data-row="<?= $id ?>" class="<?= empty($r['is_active']) ? 'row-muted' : '' ?>">
        <td>
          <input form="gridform" type="text" maxlength="80" name="rows[<?= $id ?>][first_name]"
                 value="<?= e($r['first_name'] ?? '') ?>" aria-label="First name">
        </td>
        <td>
          <input form="gridform" type="text" maxlength="80" name="rows[<?= $id ?>][last_name]"
                 value="<?= e($r['last_name'] ?? '') ?>" aria-label="Last name">
        </td>
        <td>
          <input form="gridform" type="text" maxlength="120" name="rows[<?= $id ?>][role_title]"
                 value="<?= e($r['role_title'] ?? '') ?>" aria-label="Role">
        </td>

        <td>
          <select form="gridform" name="rows[<?= $id ?>][access_level]"
                  aria-label="Access level for <?= e($r['name']) ?>">
            <?php foreach (Auth::LEVELS as $k => $label): ?>
              <option value="<?= e($k) ?>"
                <?= (($r['access_level'] ?? 'member') === $k) ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </td>

        <td class="ta-c">
          <?php if (!empty($r['pin_hash'])): ?>
            <span class="badge st-completed" title="<?= $r['last_login']
                ? 'Last signed in ' . e(fmt_ago($r['last_login'])) : 'Has never signed in' ?>">Yes</span>
          <?php else: ?>
            <span class="badge st-not-started" title="Assignable, but cannot sign in">No PIN</span>
          <?php endif; ?>
        </td>

        <td>
          <?php /* Its own form, kept out of the grid form so setting a
                   PIN can never ride along with a Save by accident. A
                   form inside a cell is fine; one wrapping cells is not. */ ?>
          <form method="post" action="<?= e(url('assignee-pin')) ?>" class="pin-form" data-leaves-page>
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="text" name="pin" maxlength="6" inputmode="numeric" pattern="[0-9]{6}"
                   placeholder="6 digits" autocomplete="off"
                   aria-label="New PIN for <?= e($r['name']) ?>">
            <button type="submit" class="btn btn-quiet btn-xs">Set</button>
          </form>
        </td>

        <td class="ta-c">
          <input form="gridform" type="hidden" name="rows[<?= $id ?>][is_active]" value="0">
          <input form="gridform" type="checkbox" name="rows[<?= $id ?>][is_active]" value="1"
                 <?= !empty($r['is_active']) ? 'checked' : '' ?>
                 aria-label="<?= e($r['name']) ?> is active">
        </td>

        <td class="c-act">
          <form method="post" action="<?= e(url('assignee-delete')) ?>" class="inline-form" data-leaves-page
                data-confirm="Remove <?= e($r['name']) ?>? If they hold assignments, they will be deactivated instead of deleted.">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <button type="submit" class="btn btn-danger btn-xs">Remove</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php require APP_ROOT . '/templates/partials/savebar.php'; ?>

<p class="table-note">
  <strong>Team member</strong> sees everything and changes only the tasks assigned to them.
  <strong>Onboarding Specialist</strong> can also add and edit practices, shape the task library,
  and move any task forward on any practice. Neither can delete, deactivate or archive anything,
  and neither can reach Products, Categories, People or the Archive.
</p>
<p class="table-note">
  People sign in with their PIN alone, so every PIN has to be different. If you type one that is
  already in use it will be refused. PINs are stored scrambled and cannot be read back, by you or
  anyone else, so if somebody forgets theirs, set a new one and tell them what it is.
</p>
<p class="table-note">
  Deactivating someone signs them out on their next click and removes them from the assignee
  dropdown, without touching the tasks already assigned to them.
</p>
<p class="table-note">Work contacts only. Do not record patient details here.</p>
