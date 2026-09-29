<?php
/**
 * Practice Onboarding Tracker - front controller.
 *
 * Every page and every write goes through here. Routes are plain query
 * parameters (index.php?p=dashboard) so the app works on any Apache
 * configuration, with or without mod_rewrite.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

// ---------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------

/** Render a template inside the shared layout. */
function render(string $template, array $vars = []): void
{
    global $config;

    $vars['config']   = $config;
    $vars['is_admin']      = Auth::isAdmin();
    $vars['member_id']     = Auth::memberId();
    $vars['can_practices'] = Auth::canManagePractices();
    $vars['can_library']   = Auth::canManageLibrary();
    $vars['can_catalogue'] = Auth::canManageCatalogue();
    $vars['can_activity']  = Auth::canViewActivity();
    // Admin and specialists may edit any task; a member only their own.
    $vars['can_edit_any']  = Auth::isAdmin() || Auth::isSpecialist();
    $vars['can_delete']    = Auth::canDelete();
    $vars['flashes']  = take_flashes();
    $vars['template'] = $template;

    extract($vars, EXTR_SKIP);
    $__template = APP_ROOT . '/templates/' . $template . '.php';

    if (!is_file($__template)) {
        http_response_code(500);
        echo 'Template not found: ' . e($template);
        exit;
    }

    require APP_ROOT . '/templates/layout.php';
}

/** Show a friendly not-found page. */
function not_found(string $message = 'That page does not exist.'): void
{
    http_response_code(404);
    render('error', ['title' => 'Not found', 'message' => $message]);
    exit;
}

// ---------------------------------------------------------------------
// Filters read from the query string
// ---------------------------------------------------------------------

/**
 * Narrow a filter set to the signed-in member's own work.
 *
 * A member defaults to their own tasks. The assignee dropdown is hidden
 * while that is the case, so the two can never contradict each other.
 * For the administrator this does nothing: the admin PIN is not linked
 * to a person, so "mine" has no meaning.
 */
function apply_scope(array $f): array
{
    $me = Auth::memberId();
    if ($me === null) {
        $f['scope'] = 'all';
        return $f;
    }
    if (($f['scope'] ?? 'mine') === 'mine') {
        $f['assignee_id'] = $me;
    }
    return $f;
}

function read_filters(): array
{
    return [
        'q'            => trim((string) ($_GET['q'] ?? '')),
        'practice_id'  => (int) ($_GET['practice_id'] ?? 0) ?: null,
        'product_id'   => (int) ($_GET['product_id'] ?? 0) ?: null,
        'category_id'  => (int) ($_GET['category_id'] ?? 0) ?: null,
        'status'       => isset(STATUSES[(string) ($_GET['status'] ?? '')]) ? (string) $_GET['status'] : null,
        'assignee_id'  => ($_GET['assignee_id'] ?? '') === 'none'
                            ? 'none'
                            : ((int) ($_GET['assignee_id'] ?? 0) ?: null),
        'state'        => isset(PRACTICE_STATES[(string) ($_GET['state'] ?? '')]) ? (string) $_GET['state'] : null,
        'only_blocked' => !empty($_GET['blocked']),
        'only_overdue' => !empty($_GET['overdue']),
        'only_attention' => !empty($_GET['attention']),
        // Completed work is hidden unless asked for. Progress figures
        // always count it; this only affects what is listed.
        'show_completed' => !empty($_GET['completed']),
        // Team members see their own work first. 'mine' or 'all'.
        // Meaningless for the administrator, who is not a person.
        'scope'          => ((string) ($_GET['scope'] ?? '')) === 'all' ? 'all' : 'mine',
        'include_archived' => !empty($_GET['archived']),
        'sort'         => (string) ($_GET['sort'] ?? 'name'),
        'dir'          => (($_GET['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc',
    ];
}

// ---------------------------------------------------------------------
// Saving a whole grid of admin rows at once
// ---------------------------------------------------------------------

/** Coerce one submitted grid cell to the right shape. */
function grid_cast(string $type, $raw)
{
    $v = is_string($raw) ? trim($raw) : $raw;
    switch ($type) {
        case 'int':     return (int) $v;
        case 'intnull': return ($v === '' || $v === null) ? null : (int) $v;
        case 'bool':    return !empty($v) ? 1 : 0;
        case 'strnull': return ($v === '' || $v === null) ? null : (string) $v;
        case 'str':
        default:        return (string) $v;
    }
}

/**
 * Apply edits from an admin grid.
 *
 * Only fields that actually changed are written, so untouched rows cost
 * nothing and the activity log stays readable. $fields is a fixed
 * whitelist defined in code, never taken from the request, which is what
 * makes interpolating the column names here safe.
 *
 * @param array<string,string> $fields column => type
 * @return array{changed:int, skipped:array<string>}
 */
function save_grid(string $table, string $entity, array $fields, array $rows, array $required = ['name']): array
{
    $changed = 0;
    $skipped = [];

    foreach ($rows as $id => $vals) {
        $id = (int) $id;
        if ($id <= 0 || !is_array($vals)) {
            continue;
        }

        $current = Database::one("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
        if (!$current) {
            continue;
        }

        $label = (string) ($current['name'] ?? ('#' . $id));

        // A blank required field means the row is skipped, not blanked out.
        $blank = false;
        foreach ($required as $req) {
            if (array_key_exists($req, $vals) && trim((string) $vals[$req]) === '') {
                $blank = true;
            }
        }
        if ($blank) {
            $skipped[] = $label;
            continue;
        }

        $set = [];
        $args = ['id' => $id];
        $fieldsChanged = [];

        foreach ($fields as $col => $type) {
            if (!array_key_exists($col, $vals)) {
                continue;
            }
            $new = grid_cast($type, $vals[$col]);
            $old = $current[$col];

            if ((string) $old === (string) $new) {
                continue;
            }
            $set[] = "{$col} = :{$col}";
            $args[$col] = $new;
            $fieldsChanged[] = $col;
        }

        if (!$set) {
            continue;
        }

        Database::run("UPDATE {$table} SET " . implode(', ', $set) . ' WHERE id = :id', $args);
        $changed++;

        $newLabel = isset($vals['name']) ? trim((string) $vals['name']) : $label;
        Activity::log(
            $entity,
            'update',
            $id,
            null,
            $entity === 'task' ? $id : null,
            implode(', ', $fieldsChanged),
            null,
            null,
            sprintf('%s updated: %s (%s)', ucfirst($entity), $newLabel, implode(', ', $fieldsChanged))
        );
    }

    return ['changed' => $changed, 'skipped' => $skipped];
}

/** Turn a save_grid result into a message for the user. */
function grid_flash(array $result, string $noun): void
{
    if (!empty($result['skipped'])) {
        flash(sprintf(
            'Skipped %s because the name was left blank: %s',
            count($result['skipped']) === 1 ? 'one row' : count($result['skipped']) . ' rows',
            implode(', ', $result['skipped'])
        ), 'error');
    }

    if ($result['changed'] === 0 && empty($result['skipped'])) {
        flash('Nothing had changed, so nothing was saved.');
        return;
    }
    if ($result['changed'] > 0) {
        flash(sprintf(
            'Saved %d %s.',
            $result['changed'],
            $result['changed'] === 1 ? $noun : $noun . 's'
        ));
    }
}

// ---------------------------------------------------------------------
// Guard: database reachable and installed
// ---------------------------------------------------------------------

$route  = (string) ($_GET['p'] ?? 'dashboard');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    Database::pdo();
} catch (Throwable $ex) {
    error_log('DB connect failed: ' . $ex->getMessage());
    http_response_code(500);
    render('error', [
        'title'   => 'Cannot reach the database',
        'message' => 'Check the credentials in config/config.php. The exact error was written to the server error log.',
    ]);
    exit;
}

if (!Database::isInstalled() && $route !== 'login') {
    render('error', [
        'title'   => 'Database not set up yet',
        'message' => 'The tables have not been created. Open install.php in this folder to run the schema, '
                   . 'or import db/schema.sql and db/seed.sql through phpMyAdmin.',
    ]);
    exit;
}

// =====================================================================
// POST routes
// =====================================================================

if ($method === 'POST') {

    // ---- JSON endpoints (inline editing) -----------------------------
    if ($route === 'api/task-save') {
        Csrf::requireValid(true);
        Auth::requireLogin(true);

        $practiceId = (int) ($_POST['practice_id'] ?? 0);
        $taskId     = (int) ($_POST['task_id'] ?? 0);
        $field      = (string) ($_POST['field'] ?? '');

        // A member may only touch a task assigned to them. Checked here,
        // on the server, not merely hidden in the template.
        if (!Auth::canEditTask($practiceId, $taskId)) {
            json_out([
                'ok' => false,
                'error' => 'That task is not assigned to you, so you cannot change it.',
            ], 403);
        }

        $allowed = ['status', 'assignee_id', 'due_date', 'notes'];
        if (!in_array($field, $allowed, true)) {
            json_out(['ok' => false, 'error' => 'Unknown field.'], 400);
        }

        $raw = $_POST['value'] ?? '';
        $raw = is_string($raw) ? trim($raw) : '';

        switch ($field) {
            case 'status':
                if (!isset(STATUSES[$raw])) {
                    json_out(['ok' => false, 'error' => 'Unknown status.'], 400);
                }
                $value = $raw;
                break;
            case 'assignee_id':
                $value = $raw === '' ? null : (int) $raw;
                break;
            case 'due_date':
                if ($raw === '') {
                    $value = null;
                } else {
                    $d = DateTime::createFromFormat('Y-m-d', $raw);
                    if (!$d || $d->format('Y-m-d') !== $raw) {
                        json_out(['ok' => false, 'error' => 'Use a YYYY-MM-DD date.'], 400);
                    }
                    $value = $raw;
                }
                break;
            default: // notes
                $value = $raw === '' ? null : mb_substr($raw, 0, 5000);
                break;
        }

        try {
            $row = Repo::saveTaskState($practiceId, $taskId, [$field => $value]);
        } catch (Throwable $ex) {
            json_out(['ok' => false, 'error' => $ex->getMessage()], 400);
        }

        $all = Repo::practiceTasks($practiceId);
        json_out([
            'ok'      => true,
            'task'    => $row,
            'rollup'  => Repo::rollup($all),
            'by_product' => array_map(
                static fn($g) => [
                    'product_id'   => $g['product_id'],
                    'product_name' => $g['product_name'],
                    'rollup'       => Repo::rollup($g['tasks']),
                ],
                array_values(Repo::groupTasks($all))
            ),
        ]);
    }

    // ---- Everything else is a form post ------------------------------
    Csrf::requireValid();

    if ($route === 'login') {
        $err = Auth::attempt((string) ($_POST['pin'] ?? ''));

        if ($err === null) {
            Activity::log('auth', 'login', null, null, null, null, null, null,
                Auth::actor() . ' signed in');
            $next = (string) ($_POST['next'] ?? '');
            redirect($next !== '' && str_starts_with($next, 'index.php') ? $next : url('dashboard'));
        }
        render('login', ['error' => $err, 'next' => (string) ($_POST['next'] ?? '')]);
        exit;
    }

    // Past this point every write needs at least a signed-in member.
    // Individual handlers narrow that further.
    Auth::requireLogin();

    // Which writes each role may reach. Anything not listed is the
    // administrator's alone, so a new route is locked down by default
    // rather than accidentally open.
    $openToAnyone = ['api/task-save', 'bulk-save', 'practice-notes-save', 'task-done'];

    $needsPractices = ['practice-save', 'practice-share'];
    $needsLibrary   = ['task-save', 'tasks-save-all', 'task-move', 'tasks-renumber'];
    $needsCatalogue = ['product-save', 'products-save-all',
                       'category-save', 'categories-save-all'];

    if (in_array($route, $openToAnyone, true)) {
        // Per-task permission is checked inside each handler.
    } elseif (in_array($route, $needsPractices, true)) {
        Auth::requireCan('manage_practices');
    } elseif (in_array($route, $needsLibrary, true)) {
        Auth::requireCan('manage_library');
    } elseif (in_array($route, $needsCatalogue, true)) {
        Auth::requireCan('manage_catalogue');
    } else {
        Auth::requireAdmin();
    }

    switch ($route) {

        // ---- Practices ------------------------------------------------
        case 'practice-save': {
            $id    = (int) ($_POST['id'] ?? 0);
            $name  = post_str('name');
            if ($name === '') {
                flash('A practice needs a name.', 'error');
                redirect($id ? url('admin/practice-form', ['id' => $id]) : url('admin/practice-form'));
            }

            $fields = [
                'name'                => $name,
                'location'            => post_str('location') ?: null,
                'onboarding_state'    => isset(PRACTICE_STATES[post_str('onboarding_state')])
                                            ? post_str('onboarding_state') : 'active',
                'target_go_live_date' => post_date_or_null('target_go_live_date'),
                'notes'               => post_str('notes') ?: null,
                // Archiving is a form of removal, so it stays with the
                // administrator. A specialist's post cannot set it.
                'is_archived'         => Auth::canDelete()
                                            ? (!empty($_POST['is_archived']) ? 1 : 0)
                                            : (int) (Repo::practice($id)['is_archived'] ?? 0),
            ];

            if ($id > 0) {
                if (!Repo::practice($id)) {
                    not_found('That practice does not exist.');
                }
                Database::run(
                    'UPDATE practices
                        SET name = :name, location = :location, onboarding_state = :onboarding_state,
                            target_go_live_date = :target_go_live_date, notes = :notes,
                            is_archived = :is_archived
                      WHERE id = :id',
                    $fields + ['id' => $id]
                );
                Activity::log('practice', 'update', $id, $id, null, null, null, null, 'Practice details updated');
            } else {
                $slug = slugify($name);
                $n = 1;
                while (Database::scalar('SELECT 1 FROM practices WHERE slug = :s', ['s' => $slug])) {
                    $slug = slugify($name) . '-' . (++$n);
                }
                Database::run(
                    'INSERT INTO practices (name, slug, location, onboarding_state, target_go_live_date, notes, is_archived)
                     VALUES (:name, :slug, :location, :onboarding_state, :target_go_live_date, :notes, :is_archived)',
                    $fields + ['slug' => $slug]
                );
                $id = Database::lastId();
                Activity::log('practice', 'create', $id, $id, null, null, null, $name, 'Practice created');
            }

            $productIds = array_map('intval', (array) ($_POST['product_ids'] ?? []));
            $diff = Repo::setPracticeProducts($id, $productIds);
            foreach ($diff['added'] as $pid) {
                $p = Repo::product((int) $pid);
                Activity::log('practice_product', 'add', (int) $pid, $id, null, null, null,
                    $p['name'] ?? null, 'Product added: ' . ($p['name'] ?? ('#' . $pid)));
            }
            foreach ($diff['removed'] as $pid) {
                $p = Repo::product((int) $pid);
                Activity::log('practice_product', 'remove', (int) $pid, $id, null, null,
                    $p['name'] ?? null, null, 'Product removed: ' . ($p['name'] ?? ('#' . $pid)));
            }

            flash('Practice saved.');
            redirect(url('practice', ['id' => $id]));
        }

        case 'practice-delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $p  = Repo::practice($id);
            if (!$p) {
                not_found('That practice does not exist.');
            }
            if (!empty($_POST['hard'])) {
                Database::run('DELETE FROM practices WHERE id = :id', ['id' => $id]);
                Activity::log('practice', 'delete', $id, null, null, null, (string) $p['name'], null,
                    'Practice deleted: ' . $p['name']);
                flash('Practice deleted.');
            } else {
                Database::run('UPDATE practices SET is_archived = 1 WHERE id = :id', ['id' => $id]);
                Activity::log('practice', 'archive', $id, $id, null, null, null, null, 'Practice archived');
                flash('Practice archived. It is hidden from the dashboard but nothing was lost.');
            }
            redirect(url('admin/practices'));
        }

        // ---- Products -------------------------------------------------
        case 'product-save': {
            $id   = (int) ($_POST['id'] ?? 0);
            $name = post_str('name');
            if ($name === '') {
                flash('A product needs a name.', 'error');
                redirect(url('admin/products'));
            }
            $desc = post_str('description') ?: null;
            $sort = (int) ($_POST['sort_order'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;

            if ($id > 0) {
                Database::run(
                    'UPDATE products SET name = :name, description = :description,
                            sort_order = :sort_order, is_active = :is_active
                      WHERE id = :id',
                    ['name' => $name, 'description' => $desc, 'sort_order' => $sort,
                     'is_active' => $active, 'id' => $id]
                );
                Activity::log('product', 'update', $id, null, null, null, null, $name, 'Product updated: ' . $name);
                flash('Product updated.');
            } else {
                $slug = slugify($name);
                $n = 1;
                while (Database::scalar('SELECT 1 FROM products WHERE slug = :s', ['s' => $slug])) {
                    $slug = slugify($name) . '-' . (++$n);
                }
                if ($sort === 0) {
                    $sort = (int) Database::scalar('SELECT COALESCE(MAX(sort_order),0) + 10 FROM products');
                }
                $color = post_str('color');
                if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
                    $color = '#334155';
                }
                Database::run(
                    'INSERT INTO products (name, slug, description, color, sort_order, is_active)
                     VALUES (:name, :slug, :description, :color, :sort_order, 1)',
                    ['name' => $name, 'slug' => $slug, 'description' => $desc,
                     'color' => $color, 'sort_order' => $sort]
                );
                Activity::log('product', 'create', Database::lastId(), null, null, null, null, $name,
                    'Product created: ' . $name);
                flash('Product added. Add its onboarding tasks next.');
            }
            redirect(url('admin/products'));
        }

        case 'product-delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $p  = Repo::product($id);
            if (!$p) {
                not_found('That product does not exist.');
            }
            $inUse = (int) Database::scalar(
                'SELECT COUNT(*) FROM practice_products WHERE product_id = :id',
                ['id' => $id]
            );
            if ($inUse > 0 && empty($_POST['force'])) {
                Database::run('UPDATE products SET is_active = 0 WHERE id = :id', ['id' => $id]);
                Activity::log('product', 'deactivate', $id, null, null, null, null, null,
                    'Product deactivated: ' . $p['name']);
                flash(sprintf(
                    '%s is used by %d practice(s), so it was deactivated rather than deleted. Its tasks are hidden but nothing was lost.',
                    $p['name'],
                    $inUse
                ));
            } else {
                Database::run('DELETE FROM products WHERE id = :id', ['id' => $id]);
                Activity::log('product', 'delete', $id, null, null, null, (string) $p['name'], null,
                    'Product deleted: ' . $p['name']);
                flash('Product deleted along with its task definitions.');
            }
            redirect(url('admin/products'));
        }

        // ---- Grid saves: one button for the whole table ----------------
        case 'products-save-all': {
            $rows = (array) ($_POST['rows'] ?? []);
            foreach ($rows as $id => $vals) {
                if (isset($vals['color']) && !preg_match('/^#[0-9A-Fa-f]{6}$/', trim((string) $vals['color']))) {
                    unset($rows[$id]['color']);
                }
            }
            $fields = [
                'sort_order'  => 'int',
                'name'        => 'str',
                'color'       => 'strnull',
                'description' => 'strnull',
            ];
            if (Auth::canDelete()) {
                $fields['is_active'] = 'bool';
            }
            $r = save_grid('products', 'product', $fields, $rows);
            grid_flash($r, 'product');
            redirect(url('admin/products'));
        }

        case 'categories-save-all': {
            $fields = ['sort_order' => 'int', 'name' => 'str', 'is_customer' => 'bool'];
            if (Auth::canDelete()) {
                $fields['is_active'] = 'bool';
            }
            $r = save_grid('categories', 'category', $fields, (array) ($_POST['rows'] ?? []));
            grid_flash($r, 'category');
            redirect(url('admin/categories'));
        }

        case 'assignees-save-all': {
            $rows = (array) ($_POST['rows'] ?? []);
            foreach ($rows as $id => $vals) {
                if (isset($vals['access_level'])
                    && !array_key_exists((string) $vals['access_level'], Auth::LEVELS)) {
                    unset($rows[$id]['access_level']);
                }
            }

            $r = save_grid('assignees', 'assignee', [
                'first_name'   => 'str',
                'last_name'    => 'strnull',
                'role_title'   => 'strnull',
                'access_level' => 'str',
                'is_active'    => 'bool',
            ], $rows, ['first_name']);

            // `name` is the denormalised display name, so keep it in step.
            Database::run(
                "UPDATE assignees
                    SET name = TRIM(CONCAT(first_name, ' ', COALESCE(last_name, '')))
                  WHERE name <> TRIM(CONCAT(first_name, ' ', COALESCE(last_name, '')))"
            );

            grid_flash($r, 'person');
            redirect(url('admin/assignees'));
        }

        case 'tasks-save-all': {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $rows      = (array) ($_POST['rows'] ?? []);

            // A task may only be moved to a category that exists, and only
            // be defaulted to somebody who exists.
            $validCats = array_map(static fn($c) => (int) $c['id'], Repo::categories());
            $validPpl  = array_map(static fn($a) => (int) $a['id'], Repo::assignees());

            foreach ($rows as $id => $vals) {
                if (isset($vals['category_id']) && !in_array((int) $vals['category_id'], $validCats, true)) {
                    unset($rows[$id]['category_id']);
                }
                if (isset($vals['default_assignee_id'])) {
                    $v = trim((string) $vals['default_assignee_id']);
                    if ($v === '') {
                        $rows[$id]['default_assignee_id'] = null;
                    } elseif (!in_array((int) $v, $validPpl, true)) {
                        unset($rows[$id]['default_assignee_id']);
                    }
                }
            }

            // A specialist edits the library but cannot switch tasks
            // off, so is_active is simply not one of their fields.
            $fields = [
                'name'                => 'str',
                'category_id'         => 'int',
                'default_assignee_id' => 'intnull',
                'description'         => 'strnull',
            ];
            if (Auth::canDelete()) {
                $fields['is_active'] = 'bool';
            }
            $r = save_grid('tasks', 'task', $fields, $rows);

            // Persist a drag-and-drop reorder, if there was one. The order
            // arrives as task ids; anything not listed keeps its place at
            // the end, and ids from another product are ignored.
            $orderRaw = trim((string) ($_POST['task_order'] ?? ''));
            if ($orderRaw !== '' && $productId > 0) {
                $current = array_map(
                    static fn($x) => (int) $x['id'],
                    Database::all('SELECT id FROM tasks WHERE product_id = :pid ORDER BY sort_order, id',
                        ['pid' => $productId])
                );
                $wanted = array_values(array_intersect(
                    array_values(array_unique(array_map('intval', explode(',', $orderRaw)))),
                    $current
                ));
                $final = array_merge($wanted, array_values(array_diff($current, $wanted)));

                if ($final !== $current) {
                    $pdo = Database::pdo();
                    $pdo->beginTransaction();
                    try {
                        $n = 0;
                        foreach ($final as $taskId) {
                            $n += 10;
                            Database::run('UPDATE tasks SET sort_order = :so WHERE id = :id',
                                ['so' => $n, 'id' => $taskId]);
                        }
                        $pdo->commit();
                        $r['changed']++;
                        Activity::log('task', 'reorder', null, null, null, 'sort_order', null, null,
                            'Task order changed for ' . (Repo::product($productId)['name'] ?? 'a product'));
                    } catch (Throwable $ex) {
                        $pdo->rollBack();
                        error_log('task reorder failed: ' . $ex->getMessage());
                        flash('The task order could not be saved.', 'error');
                    }
                }
            }

            grid_flash($r, 'change');
            redirect(url('admin/tasks', ['product_id' => $productId ?: null]));
        }

        // ---- Categories -----------------------------------------------
        case 'category-save': {
            $id   = (int) ($_POST['id'] ?? 0);
            $name = post_str('name');
            if ($name === '') {
                flash('A category needs a name.', 'error');
                redirect(url('admin/categories'));
            }
            $sort   = (int) ($_POST['sort_order'] ?? 0);
            $active = !empty($_POST['is_active']) ? 1 : 0;

            try {
                if ($id > 0) {
                    Database::run(
                        'UPDATE categories SET name = :name, sort_order = :sort_order, is_active = :is_active
                          WHERE id = :id',
                        ['name' => $name, 'sort_order' => $sort, 'is_active' => $active, 'id' => $id]
                    );
                    Activity::log('category', 'update', $id, null, null, null, null, $name,
                        'Category updated: ' . $name);
                    flash('Category updated.');
                } else {
                    if ($sort === 0) {
                        $sort = (int) Database::scalar('SELECT COALESCE(MAX(sort_order),0) + 10 FROM categories');
                    }
                    Database::run(
                        'INSERT INTO categories (name, sort_order, is_customer, is_active)
                         VALUES (:name, :sort_order, :is_customer, 1)',
                        ['name' => $name, 'sort_order' => $sort,
                         'is_customer' => !empty($_POST['is_customer']) ? 1 : 0]
                    );
                    Activity::log('category', 'create', Database::lastId(), null, null, null, null, $name,
                        'Category created: ' . $name);
                    flash('Category added. It is available to every product straight away.');
                }
            } catch (PDOException $ex) {
                flash('A category with that name already exists.', 'error');
            }
            redirect(url('admin/categories'));
        }

        case 'category-delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $c  = Repo::category($id);
            if (!$c) {
                not_found('That category does not exist.');
            }
            $inUse = (int) Database::scalar('SELECT COUNT(*) FROM tasks WHERE category_id = :id', ['id' => $id]);
            if ($inUse > 0) {
                Database::run('UPDATE categories SET is_active = 0 WHERE id = :id', ['id' => $id]);
                Activity::log('category', 'deactivate', $id, null, null, null, null, null,
                    'Category deactivated: ' . $c['name']);
                flash(sprintf('%s is used by %d task(s), so it was deactivated rather than deleted.', $c['name'], $inUse));
            } else {
                Database::run('DELETE FROM categories WHERE id = :id', ['id' => $id]);
                Activity::log('category', 'delete', $id, null, null, null, (string) $c['name'], null,
                    'Category deleted: ' . $c['name']);
                flash('Category deleted.');
            }
            redirect(url('admin/categories'));
        }

        // ---- Assignees ------------------------------------------------
        case 'assignee-save': {
            $first = post_str('first_name');
            $last  = post_str('last_name');
            if ($first === '' && $last === '') {
                flash('A person needs a first or last name.', 'error');
                redirect(url('admin/assignees'));
            }
            $full = trim($first . ' ' . $last);

            $pin = post_str('pin');
            if ($pin !== '' && !preg_match('/^[0-9]{6}$/', $pin)) {
                flash('A PIN must be exactly 6 digits.', 'error');
                redirect(url('admin/assignees'));
            }
            // Two people sharing a PIN would make sign-in ambiguous.
            if ($pin !== '' && Auth::pinInUse($pin)) {
                flash('That PIN is already in use. Pick a different one.', 'error');
                redirect(url('admin/assignees'));
            }

            Database::run(
                'INSERT INTO assignees
                    (first_name, last_name, name, pin_hash, role_title, access_level, is_active)
                 VALUES (:first_name, :last_name, :name, :pin_hash, :role_title, :access_level, 1)',
                [
                    'first_name'   => $first,
                    'last_name'    => $last,
                    'name'         => $full,
                    'pin_hash'     => $pin !== '' ? password_hash($pin, PASSWORD_DEFAULT) : null,
                    'role_title'   => post_str('role_title') ?: null,
                    'access_level' => array_key_exists(post_str('access_level'), Auth::LEVELS)
                                        ? post_str('access_level') : 'member',
                ]
            );

            Activity::log('assignee', 'create', Database::lastId(), null, null, null, null, $full,
                'Person added: ' . $full);
            flash($pin !== ''
                ? $full . ' can now sign in with that PIN.'
                : $full . ' added. Give them a PIN to let them sign in.');
            redirect(url('admin/assignees'));
        }

        // ---- Set or reset somebody's PIN -------------------------------
        case 'assignee-pin': {
            $id  = (int) ($_POST['id'] ?? 0);
            $a   = Repo::assignee($id);
            $pin = post_str('pin');

            if (!$a) {
                not_found('That person does not exist.');
            }
            if (!preg_match('/^[0-9]{6}$/', $pin)) {
                flash('A PIN must be exactly 6 digits.', 'error');
                redirect(url('admin/assignees'));
            }
            if (Auth::pinInUse($pin, $id)) {
                flash('That PIN is already in use by someone else. Pick a different one.', 'error');
                redirect(url('admin/assignees'));
            }

            Database::run('UPDATE assignees SET pin_hash = :h WHERE id = :id', [
                'h'  => password_hash($pin, PASSWORD_DEFAULT),
                'id' => $id,
            ]);
            // Only the hash is written; the PIN itself is never stored.
            Activity::log('assignee', 'set_pin', $id, null, null, 'pin', null, null,
                'PIN set for ' . $a['name']);
            flash('New PIN set for ' . $a['name'] . '. Tell them what it is; it cannot be looked up later.');
            redirect(url('admin/assignees'));
        }

        case 'assignee-delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $a  = Repo::assignee($id);
            if (!$a) {
                not_found('That assignee does not exist.');
            }
            $inUse = (int) Database::scalar(
                'SELECT COUNT(*) FROM practice_tasks WHERE assignee_id = :id',
                ['id' => $id]
            );
            if ($inUse > 0) {
                Database::run('UPDATE assignees SET is_active = 0 WHERE id = :id', ['id' => $id]);
                Activity::log('assignee', 'deactivate', $id, null, null, null, null, null,
                    'Assignee deactivated: ' . $a['name']);
                flash(sprintf(
                    '%s is assigned to %d task(s), so they were deactivated rather than deleted. Existing assignments are untouched.',
                    $a['name'],
                    $inUse
                ));
            } else {
                Database::run('DELETE FROM assignees WHERE id = :id', ['id' => $id]);
                Activity::log('assignee', 'delete', $id, null, null, null, (string) $a['name'], null,
                    'Assignee deleted: ' . $a['name']);
                flash('Assignee deleted.');
            }
            redirect(url('admin/assignees'));
        }

        // ---- Global tasks ---------------------------------------------
        case 'task-save': {
            $id         = (int) ($_POST['id'] ?? 0);
            // Editing a task is allowed; switching it off is not.
            if (!Auth::canDelete() && isset($_POST['is_active']) && empty($_POST['is_active'])) {
                flash('Only the administrator can deactivate a task.', 'error');
                redirect(url('admin/tasks', ['product_id' => (int) ($_POST['product_id'] ?? 0) ?: null]));
            }
            $productId  = (int) ($_POST['product_id'] ?? 0);
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            $name       = post_str('name');
            $back       = url('admin/tasks', ['product_id' => $productId ?: null]);

            if ($name === '' || !Repo::product($productId) || !Repo::category($categoryId)) {
                flash('A task needs a name, a product, and a category.', 'error');
                redirect($back);
            }

            $desc   = post_str('description') ?: null;
            $active = !empty($_POST['is_active']) ? 1 : 0;

            $defaultAssignee = post_int_or_null('default_assignee_id');
            if ($defaultAssignee !== null && !Repo::assignee($defaultAssignee)) {
                $defaultAssignee = null;
            }

            if ($id > 0) {
                $old = Repo::task($id);
                if (!$old) {
                    not_found('That task does not exist.');
                }
                Database::run(
                    'UPDATE tasks SET product_id = :product_id, category_id = :category_id,
                            name = :name, description = :description, is_active = :is_active
                      WHERE id = :id',
                    ['product_id' => $productId, 'category_id' => $categoryId, 'name' => $name,
                     'description' => $desc, 'is_active' => $active, 'id' => $id]
                );
                Activity::log('task', 'update', $id, null, $id, null, (string) $old['name'], $name,
                    'Global task updated: ' . $name);
                flash('Task updated everywhere it appears.');
            } else {
                Database::run(
                    'INSERT INTO tasks (product_id, category_id, default_assignee_id,
                                        name, description, sort_order, is_active)
                     VALUES (:product_id, :category_id, :default_assignee_id,
                             :name, :description, :sort_order, 1)',
                    ['product_id' => $productId, 'category_id' => $categoryId,
                     'default_assignee_id' => $defaultAssignee, 'name' => $name,
                     'description' => $desc, 'sort_order' => Repo::nextTaskSort($productId)]
                );
                $newId = Database::lastId();
                $count = (int) Database::scalar(
                    'SELECT COUNT(*) FROM practice_products WHERE product_id = :pid',
                    ['pid' => $productId]
                );
                Activity::log('task', 'create', $newId, null, $newId, null, null, $name,
                    'Global task created: ' . $name);
                flash(sprintf(
                    'Task added. It now appears for %d practice(s) onboarding this product, as Not Started.',
                    $count
                ));
            }
            redirect($back);
        }

        case 'task-delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $t  = Repo::task($id);
            if (!$t) {
                not_found('That task does not exist.');
            }
            $back  = url('admin/tasks', ['product_id' => (int) $t['product_id']]);
            $inUse = (int) Database::scalar('SELECT COUNT(*) FROM practice_tasks WHERE task_id = :id', ['id' => $id]);

            if (!empty($_POST['hard'])) {
                Database::run('DELETE FROM tasks WHERE id = :id', ['id' => $id]);
                Activity::log('task', 'delete', $id, null, null, null, (string) $t['name'], null,
                    'Global task deleted: ' . $t['name']);
                flash('Task deleted, along with the status recorded against it for every practice.');
            } else {
                Database::run('UPDATE tasks SET is_active = 0 WHERE id = :id', ['id' => $id]);
                Activity::log('task', 'deactivate', $id, null, $id, null, null, null,
                    'Global task deactivated: ' . $t['name']);
                flash(sprintf(
                    'Task deactivated. It is hidden from every practice, and the status recorded for %d practice(s) is preserved in case you reactivate it.',
                    $inUse
                ));
            }
            redirect($back);
        }

        case 'task-reactivate': {
            $id = (int) ($_POST['id'] ?? 0);
            $t  = Repo::task($id);
            if (!$t) {
                not_found('That task does not exist.');
            }
            Database::run('UPDATE tasks SET is_active = 1 WHERE id = :id', ['id' => $id]);
            Activity::log('task', 'reactivate', $id, null, $id, null, null, null,
                'Global task reactivated: ' . $t['name']);
            flash('Task reactivated.');
            redirect(url('admin/tasks', ['product_id' => (int) $t['product_id'], 'inactive' => 1]));
        }

        case 'task-move': {
            $id  = (int) ($_POST['id'] ?? 0);
            $dir = ($_POST['dir'] ?? 'up') === 'down' ? 'down' : 'up';
            $t   = Repo::task($id);
            if (!$t) {
                not_found('That task does not exist.');
            }
            $productId = (int) $t['product_id'];

            // Read the product's tasks in their current order, swap the
            // one being moved with its neighbour, then renumber the whole
            // list in tens. Renumbering avoids the tie-breaking problems
            // that come from nudging individual sort_order values, and it
            // keeps the numbers tidy for free.
            //
            // Deactivated tasks are included so the stored order stays
            // consistent. If one is hidden between two visible tasks, a
            // move can take two clicks rather than one.
            $ids = array_map(
                static fn($r) => (int) $r['id'],
                Database::all(
                    'SELECT id FROM tasks WHERE product_id = :pid ORDER BY sort_order, id',
                    ['pid' => $productId]
                )
            );

            $i = array_search($id, $ids, true);
            $j = ($i === false) ? -1 : ($dir === 'up' ? $i - 1 : $i + 1);

            if ($i !== false && $j >= 0 && $j < count($ids)) {
                $tmp = $ids[$i];
                $ids[$i] = $ids[$j];
                $ids[$j] = $tmp;

                $pdo = Database::pdo();
                $pdo->beginTransaction();
                try {
                    $order = 0;
                    foreach ($ids as $taskId) {
                        $order += 10;
                        Database::run(
                            'UPDATE tasks SET sort_order = :so WHERE id = :id',
                            ['so' => $order, 'id' => $taskId]
                        );
                    }
                    $pdo->commit();
                } catch (Throwable $ex) {
                    $pdo->rollBack();
                    error_log('task-move failed: ' . $ex->getMessage());
                    flash('Could not reorder that task.', 'error');
                }
            }

            redirect(url('admin/tasks', ['product_id' => $productId]));
        }

        case 'tasks-renumber': {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $rows = Database::all(
                'SELECT id FROM tasks WHERE product_id = :pid ORDER BY sort_order, id',
                ['pid' => $productId]
            );
            $i = 0;
            foreach ($rows as $r) {
                $i += 10;
                Database::run('UPDATE tasks SET sort_order = :so WHERE id = :id',
                    ['so' => $i, 'id' => (int) $r['id']]);
            }
            flash('Task order renumbered cleanly.');
            redirect(url('admin/tasks', ['product_id' => $productId]));
        }

        // ---- Bulk task updates on a practice ---------------------------
        case 'bulk-save': {
            $practiceId = (int) ($_POST['practice_id'] ?? 0);
            if (!Repo::practice($practiceId)) {
                not_found('That practice does not exist.');
            }
            $taskIds = array_map('intval', (array) ($_POST['task_ids'] ?? []));
            if (!$taskIds) {
                flash('No tasks were selected.', 'error');
                redirect(url('practice', ['id' => $practiceId]));
            }

            // Silently dropping other people's tasks would be confusing,
            // so say how many were skipped.
            $allowed = array_values(array_filter(
                $taskIds,
                static fn($tid) => Auth::canEditTask($practiceId, (int) $tid)
            ));
            $refused = count($taskIds) - count($allowed);
            if ($refused > 0) {
                flash(sprintf(
                    '%d of the selected tasks %s not assigned to you and %s left alone.',
                    $refused,
                    $refused === 1 ? 'is' : 'are',
                    $refused === 1 ? 'was' : 'were'
                ), 'error');
            }
            $taskIds = $allowed;
            if (!$taskIds) {
                redirect(url('practice', ['id' => $practiceId]));
            }

            $changes = [];
            $status = (string) ($_POST['bulk_status'] ?? '');
            if ($status !== '' && isset(STATUSES[$status])) {
                $changes['status'] = $status;
            }
            $assignee = (string) ($_POST['bulk_assignee'] ?? '');
            if ($assignee === 'clear') {
                $changes['assignee_id'] = null;
            } elseif ($assignee !== '') {
                $changes['assignee_id'] = (int) $assignee;
            }
            $due = (string) ($_POST['bulk_due'] ?? '');
            if ($due === 'clear') {
                $changes['due_date'] = null;
            } elseif ($due !== '') {
                $d = DateTime::createFromFormat('Y-m-d', $due);
                if ($d && $d->format('Y-m-d') === $due) {
                    $changes['due_date'] = $due;
                }
            }

            if (!$changes) {
                flash('Pick at least one thing to change.', 'error');
                redirect(url('practice', ['id' => $practiceId]));
            }

            $n = Repo::bulkUpdate($practiceId, $taskIds, $changes);
            flash(sprintf('Updated %d task(s).', $n));
            redirect(url('practice', ['id' => $practiceId]));
        }

        // ---- Tick off a task the practice was holding up ---------------
        case 'task-done': {
            $practiceId = (int) ($_POST['practice_id'] ?? 0);
            $taskId     = (int) ($_POST['task_id'] ?? 0);

            // Same rule as any other edit: admin and specialists may
            // touch anything, a member only their own.
            if (!Auth::canEditTask($practiceId, $taskId)) {
                flash('That task is not assigned to you, so you cannot change it.', 'error');
                redirect(url('practice', ['id' => $practiceId]));
            }

            try {
                $row = Repo::saveTaskState($practiceId, $taskId, ['status' => 'completed']);
                flash('"' . $row['task_name'] . '" marked done. It has come off the practice\'s page.');
            } catch (Throwable $ex) {
                error_log('task-done failed: ' . $ex->getMessage());
                flash('That task could not be updated.', 'error');
            }
            redirect(url('practice', ['id' => $practiceId]));
        }

        // ---- The practice's own shareable link -------------------------
        case 'practice-share': {
            $id = (int) ($_POST['id'] ?? 0);
            $p  = Repo::practice($id);
            if (!$p) {
                not_found('That practice does not exist.');
            }

            $action = (string) ($_POST['do'] ?? 'create');

            if ($action === 'revoke') {
                Database::run(
                    'UPDATE practices SET share_token = NULL, share_created_at = NULL WHERE id = :id',
                    ['id' => $id]
                );
                Activity::log('practice', 'share_revoke', $id, $id, null, null, null, null,
                    'Shared link turned off for ' . $p['name']);
                flash('The link has been turned off. Anyone holding it now sees a not-valid message.');
            } else {
                // 32 hex characters from a cryptographic source, so the
                // link cannot be guessed by trying practice ids.
                $token = bin2hex(random_bytes(16));
                Database::run(
                    'UPDATE practices SET share_token = :t, share_created_at = NOW() WHERE id = :id',
                    ['t' => $token, 'id' => $id]
                );
                Activity::log('practice', 'share_' . ($action === 'replace' ? 'replace' : 'create'),
                    $id, $id, null, null, null, null,
                    ($action === 'replace' ? 'Shared link replaced for ' : 'Shared link created for ') . $p['name']);
                flash($action === 'replace'
                    ? 'A new link has been created. The previous one no longer works.'
                    : 'Link created. Copy it and send it to the practice.');
            }
            redirect(url('practice', ['id' => $id]));
        }

        // ---- Archive: restore and permanent delete ---------------------
        case 'practice-restore': {
            $id = (int) ($_POST['id'] ?? 0);
            $p  = Repo::practice($id);
            if (!$p) {
                not_found('That practice does not exist.');
            }
            Database::run('UPDATE practices SET is_archived = 0 WHERE id = :id', ['id' => $id]);
            Activity::log('practice', 'restore', $id, $id, null, null, null, null,
                'Practice restored from the archive: ' . $p['name']);
            flash($p['name'] . ' restored.');
            redirect(url('admin/archive'));
        }

        case 'purge': {
            // Destroying data asks for the PIN again rather than trusting
            // that the session is still in the right hands.
            if (!Auth::confirmAdminPin((string) ($_POST['pin'] ?? ''))) {
                flash('That PIN was not correct, so nothing was deleted.', 'error');
                redirect(url('admin/archive'));
            }

            $kind = (string) ($_POST['kind'] ?? '');
            $id   = (int) ($_POST['id'] ?? 0);

            if ($kind === 'practice') {
                $p = Repo::practice($id);
                if (!$p) {
                    not_found('That practice does not exist.');
                }
                if (empty($p['is_archived'])) {
                    flash('Only archived practices can be deleted. Archive it first.', 'error');
                    redirect(url('admin/archive'));
                }
                Database::run('DELETE FROM practices WHERE id = :id', ['id' => $id]);
                Activity::log('practice', 'purge', $id, null, null, null, (string) $p['name'], null,
                    'Practice permanently deleted: ' . $p['name']);
                flash($p['name'] . ' was permanently deleted.');
            } elseif ($kind === 'task') {
                $t = Repo::task($id);
                if (!$t) {
                    not_found('That task does not exist.');
                }
                if (!empty($t['is_active'])) {
                    flash('Only deactivated tasks can be deleted. Deactivate it first.', 'error');
                    redirect(url('admin/archive'));
                }
                Database::run('DELETE FROM tasks WHERE id = :id', ['id' => $id]);
                Activity::log('task', 'purge', $id, null, null, null, (string) $t['name'], null,
                    'Task permanently deleted: ' . $t['name']);
                flash('"' . $t['name'] . '" was permanently deleted.');
            } else {
                flash('Nothing to delete.', 'error');
            }
            redirect(url('admin/archive'));
        }

        // ---- Notes on a practice --------------------------------------
        case 'practice-notes-save': {
            $practiceId = (int) ($_POST['id'] ?? 0);
            if (!Repo::practice($practiceId)) {
                not_found('That practice does not exist.');
            }
            Database::run('UPDATE practices SET notes = :n WHERE id = :id', [
                'n'  => post_str('notes') ?: null,
                'id' => $practiceId,
            ]);
            Activity::log('practice', 'update', $practiceId, $practiceId, null, 'notes', null, null,
                'Practice notes updated');
            flash('Notes saved.');
            redirect(url('practice', ['id' => $practiceId]));
        }
    }

    not_found('Unknown action.');
}

// =====================================================================
// GET routes
// =====================================================================

// Reading the portal now needs an account. The login page and the
// sign-out link are the only exceptions.
// The shared practice page is opened with a link and no account, so it
// is the one read route outside the sign-in wall. The token is the
// credential; everything it can reach is one practice, read only.
if (!in_array($route, ['login', 'logout', 'share'], true)) {
    Auth::requireLogin();
}

switch ($route) {

    case 'logout':
        Activity::log('auth', 'logout', null, null, null, null, null, null,
            Auth::actor() . ' signed out');
        Auth::logout();
        flash('Signed out.');
        redirect(url('login'));

    case 'login':
        if (Auth::isSignedIn()) {
            redirect(url('dashboard'));
        }
        render('login', ['error' => null, 'next' => (string) ($_GET['next'] ?? '')]);
        break;

    case 'dashboard': {
        // Totals come from the same summaries the practices page uses,
        // so the two pages can never disagree.
        $rows = Repo::practiceSummaries(['sort' => 'name']);

        $totals = [
            'practices' => count($rows),
            'blocked'   => array_sum(array_column($rows, 'blocked')),
            'overdue'   => array_sum(array_column($rows, 'overdue')),
            'attention' => count(array_filter(
                $rows,
                static fn($r) => $r['needs_attention'] && $r['onboarding_state'] === 'active'
            )),
            'completed' => array_sum(array_column($rows, 'completed')),
            'countable' => array_sum(array_column($rows, 'countable')),
        ];
        $totals['progress'] = progress_pct($totals['completed'], $totals['countable']);

        // Practice health, derived from the same summaries so the donut
        // and the practices page can never disagree.
        $health = ['total' => count($rows), 'on_track' => 0, 'attention' => 0,
                   'on_hold' => 0, 'completed' => 0];
        foreach ($rows as $r) {
            if ($r['onboarding_state'] === 'completed') {
                $health['completed']++;
            } elseif ($r['onboarding_state'] === 'on_hold') {
                $health['on_hold']++;
            } elseif ($r['needs_attention']) {
                $health['attention']++;
            } else {
                $health['on_track']++;
            }
        }

        $me = Auth::memberId();

        render('dashboard', [
            'totals'      => $totals,
            'health'      => $health,
            'my_work'     => $me !== null ? Repo::openCountsFor($me) : null,
            'by_status'   => Repo::countsByStatus(),
            'by_assignee' => Repo::openByAssignee(),
            'by_product'  => Repo::openByProduct(),
            'by_category' => Repo::openByCategory(),
            'upcoming'    => Repo::upcomingGoLive(),
            'stalled'     => Repo::stalled(),
        ]);
        break;
    }

    case 'share': {
        $practice = Repo::practiceByShareToken((string) ($_GET['t'] ?? ''));
        if (!$practice) {
            http_response_code(404);
            render('error', [
                'title'   => 'This link is not valid',
                'message' => 'It may have been replaced or turned off. Ask your onboarding contact for a new one.',
            ]);
            exit;
        }

        $pid  = (int) $practice['id'];
        $rows = Repo::practiceTasks($pid, ['show_completed' => true]);

        // Per product: how far along, and which stage it is currently
        // in, meaning the first category still holding open work.
        $products = [];
        foreach (Repo::groupTasks($rows) as $g) {
            $stage = null;
            foreach ($g['categories'] as $cat) {
                foreach ($cat['tasks'] as $t) {
                    if (!in_array($t['status'], ['completed', 'not_applicable'], true)) {
                        $stage = $cat['category_name'];
                        break 2;
                    }
                }
            }
            $products[] = [
                'name'   => $g['product_name'],
                'color'  => $g['product_color'] ?? null,
                'stage'  => $stage,
                'rollup' => Repo::rollup($g['tasks']),
            ];
        }

        // The only tasks named on the page: open work in a category
        // marked as the practice's own. Overdue first, then by date.
        $actions = array_values(array_filter($rows, static fn($r) =>
            !empty($r['is_customer'])
            && !in_array($r['status'], ['completed', 'not_applicable'], true)
        ));
        usort($actions, static function ($a, $b) {
            if (!empty($a['is_overdue']) !== !empty($b['is_overdue'])) {
                return !empty($a['is_overdue']) ? -1 : 1;
            }
            return strcmp((string) ($a['due_date'] ?? '9999'), (string) ($b['due_date'] ?? '9999'));
        });

        render('share', [
            'practice' => $practice,
            'rollup'   => Repo::rollup($rows),
            'products' => $products,
            'actions'  => $actions,
        ]);
        break;
    }

    case 'practices': {
        $f = read_filters();
        if (!Auth::isAdmin()) {
            $f['include_archived'] = false;
        }
        render('practices', [
            'rows'     => Repo::practiceSummaries($f),
            'filters'  => $f,
            'products' => Repo::products(),
        ]);
        break;
    }

    case 'practice': {
        $id       = (int) ($_GET['id'] ?? 0);
        $practice = Repo::practice($id);
        if (!$practice) {
            not_found('That practice does not exist.');
        }

        $f = apply_scope(read_filters());

        // Unfiltered rows drive the progress figures, so neither the
        // filters, hiding completed work, nor the My tasks scope
        // changes the percentages.
        $allRows      = Repo::practiceTasks($id, ['show_completed' => true]);
        $filteredRows = Repo::practiceTasks($id, $f);

        $grouped   = Repo::groupTasks($filteredRows);
        $allByProd = Repo::groupTasks($allRows);

        $byProduct = [];
        foreach ($allByProd as $pid => $g) {
            $byProduct[$pid] = [
                'product_id'   => $g['product_id'],
                'product_name' => $g['product_name'],
                'rollup'       => Repo::rollup($g['tasks']),
            ];
        }

        render('practice', [
            'practice'    => $practice,
            'grouped'     => $grouped,
            'rollup'      => Repo::rollup($allRows),
            'by_product'  => $byProduct,
            'by_category' => Repo::rollupByCategory($allRows),
            'all_rows'    => $allRows,
            'filters'     => $f,
            'filtered'    => count($filteredRows) !== count($allRows),
            'hiding_done' => empty($f['show_completed']),
            'shown'       => count($filteredRows),
            'products'    => Repo::practiceProducts($id),
            'categories'  => Repo::categories(),
            'assignees'   => Repo::assignees(),
            'activity'    => Activity::forPractice($id, 20),
        ]);
        break;
    }

    case 'tasks': {
        $f    = apply_scope(read_filters());
        $rows = Repo::tasksAcrossPractices($f);
        render('tasks', [
            'rows'       => $rows,
            'grouped'    => Repo::groupTasksByProduct($rows),
            'rollup'     => Repo::rollup($rows),
            'filters'    => $f,
            'practices'  => Repo::practicesSimple(),
            'products'   => Repo::products(),
            'categories' => Repo::categories(),
            'assignees'  => Repo::assignees(),
        ]);
        break;
    }

    case 'export': {
        $f     = apply_scope(read_filters());
        $rows  = Repo::tasksAcrossPractices($f, 5000);
        $stamp = date('Y-m-d');

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="onboarding-tasks-' . $stamp . '.csv"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['Practice', 'Product', 'Category', 'Task', 'Status', 'Assignee', 'Due date', 'Overdue', 'Notes', 'Last updated']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['practice_name'],
                $r['product_name'],
                $r['category_name'],
                $r['task_name'],
                status_label($r['status']),
                $r['assignee_name'] ?? '',
                $r['due_date'] ?? '',
                !empty($r['is_overdue']) ? 'Yes' : '',
                $r['notes'] ?? '',
                $r['updated_at'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    // ---- Admin screens -------------------------------------------------

    case 'admin/practices':
        // Kept so old links and bookmarks still work. There is one
        // practices page now, with the admin actions shown inline.
        redirect(url('practices', ['archived' => 1]));

    case 'admin/practice-form': {
        Auth::requireCan('manage_practices');
        $id       = (int) ($_GET['id'] ?? 0);
        $practice = $id > 0 ? Repo::practice($id) : null;
        if ($id > 0 && !$practice) {
            not_found('That practice does not exist.');
        }
        render('admin/practice_form', [
            'practice' => $practice,
            'products' => Repo::products(false),
            'selected' => $practice ? Repo::practiceProductIds((int) $practice['id']) : [],
        ]);
        break;
    }

    case 'admin/products':
        Auth::requireCan('manage_catalogue');
        render('admin/products', ['rows' => Repo::products(false)]);
        break;

    case 'admin/categories':
        Auth::requireCan('manage_catalogue');
        render('admin/categories', ['rows' => Repo::categories(false)]);
        break;

    case 'admin/assignees':
        Auth::requireAdmin();
        render('admin/assignees', ['rows' => Repo::assignees(false)]);
        break;

    case 'admin/tasks': {
        Auth::requireCan('manage_library');
        $productId       = (int) ($_GET['product_id'] ?? 0);
        $includeInactive = !empty($_GET['inactive']);
        $products        = Repo::products(false);

        if ($productId === 0 && $products) {
            $productId = (int) $products[0]['id'];
        }

        render('admin/tasks', [
            'products'         => $products,
            'product_id'       => $productId,
            'product'          => $productId ? Repo::product($productId) : null,
            'categories'       => Repo::categories(),
            'assignees'        => Repo::assignees(),
            'rows'             => $productId
                                    ? Repo::tasks(['product_id' => $productId, 'include_inactive' => $includeInactive])
                                    : [],
            'include_inactive' => $includeInactive,
        ]);
        break;
    }

    case 'admin/archive':
        Auth::requireAdmin();
        render('admin/archive', [
            'practices' => array_values(array_filter(
                Repo::practiceSummaries(['include_archived' => true, 'sort' => 'name']),
                static fn($r) => !empty($r['is_archived'])
            )),
            'tasks' => Repo::archivedTasks(),
        ]);
        break;

    case 'admin/activity': {
        Auth::requireCan('view_activity');
        $rows = Database::all(
            'SELECT l.*, pr.name AS practice_name, t.name AS task_name
               FROM activity_log l
               LEFT JOIN practices pr ON pr.id = l.practice_id
               LEFT JOIN tasks t      ON t.id = l.task_id
              ORDER BY l.created_at DESC, l.id DESC
              LIMIT 300'
        );
        render('admin/activity', ['rows' => $rows]);
        break;
    }

    default:
        not_found();
}
