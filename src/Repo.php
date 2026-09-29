<?php
declare(strict_types=1);

/**
 * All database queries live here.
 *
 * The central idea: a task is defined once, globally, against a product.
 * A practice's task list is DERIVED at read time by joining
 *   practices -> practice_products -> products -> tasks
 * and LEFT JOINing practice_tasks for the per-practice state.
 *
 * A practice_tasks row is written only when someone actually sets a
 * status, assignee, due date, or note. No row means Not Started. That is
 * why a newly created global task shows up for every practice on the
 * product instantly, with no fan-out writes.
 */
final class Repo
{
    /** The per-row status, defaulting when no practice_tasks row exists. */
    private const ST = "COALESCE(pt.status,'not_started')";

    /**
     * The effective assignee: whoever is set on this practice's copy of
     * the task, otherwise the task's default owner. Changing the default
     * in the task library therefore moves every practice that has not
     * picked someone of its own, with nothing copied or duplicated.
     */
    private const ASG = 'COALESCE(pt.assignee_id, t.default_assignee_id)';

    /** Reusable aggregate columns for any progress rollup. */
    private static function aggCols(): string
    {
        $st = self::ST;
        return "
            COUNT(t.id) AS total,
            SUM(CASE WHEN {$st} <> 'not_applicable' THEN 1 ELSE 0 END)     AS countable,
            SUM(CASE WHEN {$st} = 'completed' THEN 1 ELSE 0 END)           AS completed,
            SUM(CASE WHEN {$st} = 'in_progress' THEN 1 ELSE 0 END)         AS in_progress,
            SUM(CASE WHEN {$st} = 'waiting' THEN 1 ELSE 0 END)             AS waiting,
            SUM(CASE WHEN {$st} = 'blocked' THEN 1 ELSE 0 END)             AS blocked,
            SUM(CASE WHEN {$st} = 'not_started' THEN 1 ELSE 0 END)         AS not_started,
            SUM(CASE WHEN {$st} = 'not_applicable' THEN 1 ELSE 0 END)      AS not_applicable,
            SUM(CASE WHEN pt.due_date IS NOT NULL
                      AND pt.due_date < CURDATE()
                      AND {$st} NOT IN ('completed','not_applicable')
                     THEN 1 ELSE 0 END)                                    AS overdue,
            MAX(pt.updated_at) AS last_task_update
        ";
    }

    // =================================================================
    // Reference data
    // =================================================================

    public static function products(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE p.is_active = 1' : '';
        return Database::all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM tasks t WHERE t.product_id = p.id AND t.is_active = 1) AS task_count,
                    (SELECT COUNT(*) FROM practice_products pp WHERE pp.product_id = p.id) AS practice_count
               FROM products p {$where}
              ORDER BY p.sort_order, p.name"
        );
    }

    public static function product(int $id): ?array
    {
        return Database::one('SELECT * FROM products WHERE id = :id', ['id' => $id]);
    }

    public static function categories(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE c.is_active = 1' : '';
        return Database::all(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM tasks t WHERE t.category_id = c.id AND t.is_active = 1) AS task_count
               FROM categories c {$where}
              ORDER BY c.sort_order, c.name"
        );
    }

    public static function category(int $id): ?array
    {
        return Database::one('SELECT * FROM categories WHERE id = :id', ['id' => $id]);
    }

    public static function assignees(bool $activeOnly = true): array
    {
        $where = $activeOnly ? 'WHERE a.is_active = 1' : '';
        return Database::all(
            "SELECT a.*,
                    (SELECT COUNT(*) FROM practice_tasks pt WHERE pt.assignee_id = a.id) AS assigned_count
               FROM assignees a {$where}
              ORDER BY a.name"
        );
    }

    public static function assignee(int $id): ?array
    {
        return Database::one('SELECT * FROM assignees WHERE id = :id', ['id' => $id]);
    }

    // =================================================================
    // Global task definitions
    // =================================================================

    public static function tasks(array $f = []): array
    {
        $where = ['1 = 1'];
        $args  = [];

        if (empty($f['include_inactive'])) {
            $where[] = 't.is_active = 1';
        }
        if (!empty($f['product_id'])) {
            $where[] = 't.product_id = :product_id';
            $args['product_id'] = (int) $f['product_id'];
        }
        if (!empty($f['category_id'])) {
            $where[] = 't.category_id = :category_id';
            $args['category_id'] = (int) $f['category_id'];
        }
        if (!empty($f['q'])) {
            $where[] = 't.name LIKE :q';
            $args['q'] = '%' . $f['q'] . '%';
        }

        return Database::all(
            'SELECT t.*, p.name AS product_name, p.sort_order AS product_sort,
                    c.name AS category_name, c.sort_order AS category_sort,
                    c.color AS category_color,
                    da.name AS default_assignee_name
               FROM tasks t
               JOIN products p    ON p.id = t.product_id
               JOIN categories c  ON c.id = t.category_id
               LEFT JOIN assignees da ON da.id = t.default_assignee_id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY p.sort_order, p.name, c.sort_order, c.name, t.sort_order, t.id',
            $args
        );
    }

    public static function task(int $id): ?array
    {
        return Database::one(
            'SELECT t.*, p.name AS product_name, c.name AS category_name
               FROM tasks t
               JOIN products p   ON p.id = t.product_id
               JOIN categories c ON c.id = t.category_id
              WHERE t.id = :id',
            ['id' => $id]
        );
    }

    /** Next sort_order value for a product's task list. */
    public static function nextTaskSort(int $productId): int
    {
        $max = (int) Database::scalar(
            'SELECT COALESCE(MAX(sort_order), 0) FROM tasks WHERE product_id = :pid',
            ['pid' => $productId]
        );
        return $max + 10;
    }

    // =================================================================
    // Practices
    // =================================================================

    public static function practice(int $id): ?array
    {
        return Database::one('SELECT * FROM practices WHERE id = :id', ['id' => $id]);
    }

    /**
     * Find a practice by its share token.
     *
     * Archived practices are excluded, so archiving a practice also
     * quietly retires its link.
     */
    public static function practiceByShareToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        return Database::one(
            'SELECT * FROM practices WHERE share_token = :t AND is_archived = 0',
            ['t' => $token]
        );
    }

    public static function practicesSimple(bool $includeArchived = false): array
    {
        $where = $includeArchived ? '' : 'WHERE is_archived = 0';
        return Database::all("SELECT id, name FROM practices {$where} ORDER BY name");
    }

    public static function practiceProductIds(int $practiceId): array
    {
        $rows = Database::all(
            'SELECT product_id FROM practice_products WHERE practice_id = :pid',
            ['pid' => $practiceId]
        );
        return array_map(static fn($r) => (int) $r['product_id'], $rows);
    }

    /** Products a practice is onboarding, in display order. */
    public static function practiceProducts(int $practiceId): array
    {
        return Database::all(
            'SELECT p.*
               FROM practice_products pp
               JOIN products p ON p.id = pp.product_id
              WHERE pp.practice_id = :pid AND p.is_active = 1
              ORDER BY p.sort_order, p.name',
            ['pid' => $practiceId]
        );
    }

    /**
     * Replace a practice's product selection.
     * Removing a product hides its tasks but keeps any recorded
     * practice_tasks state, so re-adding the product restores history.
     */
    public static function setPracticeProducts(int $practiceId, array $productIds): array
    {
        $current = self::practiceProductIds($practiceId);
        $wanted  = array_values(array_unique(array_map('intval', $productIds)));

        $added   = array_values(array_diff($wanted, $current));
        $removed = array_values(array_diff($current, $wanted));

        foreach ($added as $pid) {
            Database::run(
                'INSERT IGNORE INTO practice_products (practice_id, product_id) VALUES (:pr, :pd)',
                ['pr' => $practiceId, 'pd' => $pid]
            );
        }
        foreach ($removed as $pid) {
            Database::run(
                'DELETE FROM practice_products WHERE practice_id = :pr AND product_id = :pd',
                ['pr' => $practiceId, 'pd' => $pid]
            );
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Dashboard rows: one per practice with progress aggregates.
     * Sorting is applied in PHP because progress is a derived value.
     */
    public static function practiceSummaries(array $f = []): array
    {
        $where = ['1 = 1'];
        $args  = [];

        if (empty($f['include_archived'])) {
            $where[] = 'pr.is_archived = 0';
        }
        if (!empty($f['state'])) {
            $where[] = 'pr.onboarding_state = :state';
            $args['state'] = (string) $f['state'];
        }
        if (!empty($f['q'])) {
            // Native prepared statements will not accept the same named
            // placeholder twice, so each occurrence gets its own name.
            $where[] = '(pr.name LIKE :q1 OR pr.location LIKE :q2)';
            $args['q1'] = $args['q2'] = '%' . $f['q'] . '%';
        }
        if (!empty($f['product_id'])) {
            $where[] = 'EXISTS (SELECT 1 FROM practice_products x
                                 WHERE x.practice_id = pr.id AND x.product_id = :fproduct)';
            $args['fproduct'] = (int) $f['product_id'];
        }

        $agg = self::aggCols();

        $rows = Database::all(
            "SELECT pr.id, pr.name, pr.slug, pr.location, pr.onboarding_state,
                    pr.target_go_live_date, pr.is_archived, pr.updated_at,
                    {$agg}
               FROM practices pr
               LEFT JOIN practice_products pp ON pp.practice_id = pr.id
               LEFT JOIN products p           ON p.id = pp.product_id AND p.is_active = 1
               LEFT JOIN tasks t              ON t.product_id = p.id AND t.is_active = 1
               LEFT JOIN practice_tasks pt    ON pt.practice_id = pr.id AND pt.task_id = t.id
              WHERE " . implode(' AND ', $where) . "
              GROUP BY pr.id, pr.name, pr.slug, pr.location, pr.onboarding_state,
                       pr.target_go_live_date, pr.is_archived, pr.updated_at
              ORDER BY pr.name",
            $args
        );

        $productsByPractice = self::productNamesByPractice();

        foreach ($rows as &$r) {
            foreach (['total','countable','completed','in_progress','waiting','blocked','not_started','not_applicable','overdue'] as $k) {
                $r[$k] = (int) ($r[$k] ?? 0);
            }
            $r['products']        = $productsByPractice[(int) $r['id']] ?? [];
            $r['progress']        = progress_pct($r['completed'], $r['countable']);
            $r['remaining']       = max(0, $r['countable'] - $r['completed']);
            $r['needs_attention'] = ($r['blocked'] > 0 || $r['overdue'] > 0);
            $r['last_updated']    = self::maxDate([$r['updated_at'] ?? null, $r['last_task_update'] ?? null]);
            $r['days_to_golive']  = days_until($r['target_go_live_date'] ?? null);
        }
        unset($r);

        if (!empty($f['only_blocked'])) {
            $rows = array_values(array_filter($rows, static fn($r) => $r['blocked'] > 0));
        }
        if (!empty($f['only_overdue'])) {
            $rows = array_values(array_filter($rows, static fn($r) => $r['overdue'] > 0));
        }
        if (!empty($f['only_attention'])) {
            $rows = array_values(array_filter($rows, static fn($r) => $r['needs_attention']));
        }

        return self::sortSummaries($rows, (string) ($f['sort'] ?? 'name'), (string) ($f['dir'] ?? 'asc'));
    }

    private static function productNamesByPractice(): array
    {
        $rows = Database::all(
            'SELECT pp.practice_id, p.name, p.color
               FROM practice_products pp
               JOIN products p ON p.id = pp.product_id AND p.is_active = 1
              ORDER BY p.sort_order, p.name'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['practice_id']][] = [
                'name'  => (string) $r['name'],
                'color' => $r['color'] ?? null,
            ];
        }
        return $out;
    }

    private static function sortSummaries(array $rows, string $sort, string $dir): array
    {
        $desc = strtolower($dir) === 'desc';

        $cmp = static function (array $a, array $b) use ($sort): int {
            switch ($sort) {
                case 'progress':
                    return $a['progress'] <=> $b['progress'];
                case 'golive':
                    // Practices with no target date sort last in ascending order.
                    $av = $a['target_go_live_date'] ?: '9999-12-31';
                    $bv = $b['target_go_live_date'] ?: '9999-12-31';
                    return strcmp((string) $av, (string) $bv);
                case 'updated':
                    return strcmp((string) ($a['last_updated'] ?? ''), (string) ($b['last_updated'] ?? ''));
                case 'blocked':
                    return $a['blocked'] <=> $b['blocked'];
                case 'overdue':
                    return $a['overdue'] <=> $b['overdue'];
                case 'name':
                default:
                    return strcasecmp((string) $a['name'], (string) $b['name']);
            }
        };

        usort($rows, static function (array $a, array $b) use ($cmp, $desc): int {
            $r = $cmp($a, $b);
            if ($r === 0) {
                return strcasecmp((string) $a['name'], (string) $b['name']);
            }
            return $desc ? -$r : $r;
        });

        return $rows;
    }

    private static function maxDate(array $candidates): ?string
    {
        $best = null;
        foreach ($candidates as $c) {
            if (!$c) {
                continue;
            }
            if ($best === null || strcmp((string) $c, $best) > 0) {
                $best = (string) $c;
            }
        }
        return $best;
    }

    // =================================================================
    // Derived task list for one practice
    // =================================================================

    public static function practiceTasks(int $practiceId, array $f = []): array
    {
        $where = ['pp.practice_id = :pid'];
        $args  = ['pid' => $practiceId];
        $st    = self::ST;

        if (!empty($f['product_id'])) {
            $where[] = 'p.id = :product_id';
            $args['product_id'] = (int) $f['product_id'];
        }
        if (!empty($f['category_id'])) {
            $where[] = 'c.id = :category_id';
            $args['category_id'] = (int) $f['category_id'];
        }
        if (!empty($f['status'])) {
            $where[] = "{$st} = :status";
            $args['status'] = (string) $f['status'];
        }
        $asg = self::ASG;

        if (!empty($f['assignee_id'])) {
            if ($f['assignee_id'] === 'none') {
                $where[] = "{$asg} IS NULL";
            } else {
                $where[] = "{$asg} = :assignee_id";
                $args['assignee_id'] = (int) $f['assignee_id'];
            }
        }
        if (!empty($f['only_blocked'])) {
            $where[] = "{$st} IN ('blocked','waiting')";
        }
        if (!empty($f['only_overdue'])) {
            $where[] = "pt.due_date IS NOT NULL AND pt.due_date < CURDATE()
                        AND {$st} NOT IN ('completed','not_applicable')";
        }
        // Finished work is hidden unless explicitly asked for. An
        // explicit status filter always wins, so filtering for
        // Completed still shows completed tasks.
        if (empty($f['show_completed']) && empty($f['status'])) {
            $where[] = "{$st} <> 'completed'";
        }
        if (!empty($f['q'])) {
            $where[] = '(t.name LIKE :q1 OR pt.notes LIKE :q2)';
            $args['q1'] = $args['q2'] = '%' . $f['q'] . '%';
        }

        $rows = Database::all(
            "SELECT t.id AS task_id, t.name AS task_name, t.description, t.sort_order,
                    p.id AS product_id, p.name AS product_name, p.sort_order AS product_sort,
                    p.color AS product_color,
                    c.id AS category_id, c.name AS category_name, c.sort_order AS category_sort,
                    {$st} AS status,
                    pt.id AS state_id,
                    {$asg} AS assignee_id,
                    pt.assignee_id AS assignee_override,
                    t.default_assignee_id,
                    pt.due_date, pt.notes, pt.updated_at,
                    a.name AS assignee_name
               FROM practice_products pp
               JOIN products p             ON p.id = pp.product_id AND p.is_active = 1
               JOIN tasks t                ON t.product_id = p.id AND t.is_active = 1
               JOIN categories c           ON c.id = t.category_id
               LEFT JOIN practice_tasks pt ON pt.practice_id = pp.practice_id AND pt.task_id = t.id
               LEFT JOIN assignees a       ON a.id = {$asg}
              WHERE " . implode(' AND ', $where) . "
              ORDER BY p.sort_order, p.name, c.sort_order, c.name, t.sort_order, t.id",
            $args
        );

        foreach ($rows as &$r) {
            $r['is_overdue'] = !empty($r['due_date'])
                && $r['due_date'] < date('Y-m-d')
                && !in_array($r['status'], ['completed', 'not_applicable'], true);
            $r['days_until'] = days_until($r['due_date'] ?? null);
        }
        unset($r);

        return $rows;
    }

    /** Group a derived task list into product -> category -> tasks. */
    public static function groupTasks(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            $cid = (int) $r['category_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = [
                    'product_id'    => $pid,
                    'product_name'  => (string) $r['product_name'],
                    'product_color' => $r['product_color'] ?? null,
                    'categories'    => [],
                    'tasks'         => [],
                ];
            }
            if (!isset($out[$pid]['categories'][$cid])) {
                $out[$pid]['categories'][$cid] = [
                    'category_id'   => $cid,
                    'category_name' => (string) $r['category_name'],
                    'tasks'         => [],
                ];
            }
            $out[$pid]['categories'][$cid]['tasks'][] = $r;
            $out[$pid]['tasks'][] = $r;
        }
        return $out;
    }

    /**
     * Group a cross-practice task list by product, keeping the incoming
     * order within each group. Used by the All tasks tabs.
     */
    public static function groupTasksByProduct(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            if (!isset($out[$pid])) {
                $out[$pid] = [
                    'product_id'    => $pid,
                    'product_name'  => (string) $r['product_name'],
                    'product_color' => $r['product_color'] ?? null,
                    'rows'          => [],
                ];
            }
            $out[$pid]['rows'][] = $r;
        }
        return $out;
    }

    /** Split a list of task rows into category groups, in order. */
    public static function groupRowsByCategory(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['category_id'];
            if (!isset($out[$cid])) {
                $out[$cid] = [
                    'category_id'    => $cid,
                    'category_name'  => (string) $r['category_name'],
                    'category_color' => $r['category_color'] ?? null,
                    'rows'           => [],
                ];
            }
            $out[$cid]['rows'][] = $r;
        }
        return $out;
    }

    /** Totals for a set of derived task rows. Works for any scope. */
    public static function rollup(array $rows): array
    {
        $r = [
            'total' => 0, 'countable' => 0, 'completed' => 0, 'in_progress' => 0,
            'waiting' => 0, 'blocked' => 0, 'not_started' => 0, 'not_applicable' => 0,
            'overdue' => 0,
        ];
        foreach ($rows as $row) {
            $r['total']++;
            $status = (string) $row['status'];
            if (array_key_exists($status, $r)) {
                $r[$status]++;
            }
            if ($status !== 'not_applicable') {
                $r['countable']++;
            }
            if (!empty($row['is_overdue'])) {
                $r['overdue']++;
            }
        }
        $r['progress']  = progress_pct($r['completed'], $r['countable']);
        $r['remaining'] = max(0, $r['countable'] - $r['completed']);
        return $r;
    }

    /** Progress grouped by category across a task list. */
    public static function rollupByCategory(array $rows): array
    {
        $byCat = [];
        foreach ($rows as $r) {
            $byCat[(string) $r['category_name']][] = $r;
        }
        $out = [];
        foreach ($byCat as $name => $catRows) {
            $out[] = array_merge(['name' => $name], self::rollup($catRows));
        }
        return $out;
    }

    // =================================================================
    // Archive
    // =================================================================

    /** Deactivated task definitions, with how much history each holds. */
    public static function archivedTasks(): array
    {
        return Database::all(
            'SELECT t.*, p.name AS product_name, p.color AS product_color,
                    c.name AS category_name,
                    (SELECT COUNT(*) FROM practice_tasks pt WHERE pt.task_id = t.id) AS state_count
               FROM tasks t
               JOIN products p   ON p.id = t.product_id
               JOIN categories c ON c.id = t.category_id
              WHERE t.is_active = 0
              ORDER BY p.sort_order, t.sort_order, t.id'
        );
    }

    // =================================================================
    // Dashboard aggregates
    //
    // "Open" means everything still to do: not Completed and not marked
    // Not Applicable. Archived practices are excluded throughout, as are
    // practices whose onboarding is finished, since neither represents
    // work anybody has to pick up.
    // =================================================================

    /** The JOIN chain every dashboard aggregate shares. */
    private static function openTasksJoins(): string
    {
        return "FROM practices pr
                JOIN practice_products pp   ON pp.practice_id = pr.id
                JOIN products p             ON p.id = pp.product_id AND p.is_active = 1
                JOIN tasks t                ON t.product_id = p.id AND t.is_active = 1
                JOIN categories c           ON c.id = t.category_id
                LEFT JOIN practice_tasks pt ON pt.practice_id = pr.id AND pt.task_id = t.id";
    }

    /** Which practices count as live work. */
    private static function openTasksWhere(): string
    {
        return "WHERE pr.is_archived = 0 AND pr.onboarding_state <> 'completed'";
    }

    /** Open work per person, including everything nobody owns yet. */
    public static function openByAssignee(): array
    {
        $st    = self::ST;
        $asg   = self::ASG;
        $joins = self::openTasksJoins();
        $where = self::openTasksWhere();

        return Database::all(
            "SELECT COALESCE(a.name, 'Unassigned') AS label,
                    a.id AS assignee_id,
                    COUNT(*) AS open_count,
                    SUM(CASE WHEN {$st} = 'blocked' THEN 1 ELSE 0 END) AS blocked,
                    SUM(CASE WHEN pt.due_date IS NOT NULL AND pt.due_date < CURDATE()
                             THEN 1 ELSE 0 END) AS overdue
               {$joins}
               LEFT JOIN assignees a ON a.id = {$asg}
               {$where}
                 AND {$st} NOT IN ('completed','not_applicable')
              GROUP BY a.id, label
              ORDER BY open_count DESC, label"
        );
    }

    /** One person's open workload, for their dashboard card. */
    public static function openCountsFor(int $assigneeId): array
    {
        $st    = self::ST;
        $asg   = self::ASG;
        $joins = self::openTasksJoins();
        $where = self::openTasksWhere();

        $row = Database::one(
            "SELECT COUNT(*) AS open_count,
                    SUM(CASE WHEN {$st} = 'blocked' THEN 1 ELSE 0 END) AS blocked,
                    SUM(CASE WHEN pt.due_date IS NOT NULL AND pt.due_date < CURDATE()
                             THEN 1 ELSE 0 END) AS overdue
               {$joins}
               {$where}
                 AND {$st} NOT IN ('completed','not_applicable')
                 AND {$asg} = :me",
            ['me' => $assigneeId]
        );

        return [
            'open_count' => (int) ($row['open_count'] ?? 0),
            'blocked'    => (int) ($row['blocked'] ?? 0),
            'overdue'    => (int) ($row['overdue'] ?? 0),
        ];
    }

    /** Open work per product. */
    public static function openByProduct(): array
    {
        $st   = self::ST;
        $joins = self::openTasksJoins();
        $where = self::openTasksWhere();

        return Database::all(
            "SELECT p.name AS label, p.id AS product_id, p.color AS color,
                    COUNT(*) AS open_count,
                    SUM(CASE WHEN {$st} = 'blocked' THEN 1 ELSE 0 END) AS blocked,
                    SUM(CASE WHEN pt.due_date IS NOT NULL AND pt.due_date < CURDATE()
                             THEN 1 ELSE 0 END) AS overdue
               {$joins}
               {$where}
                 AND {$st} NOT IN ('completed','not_applicable')
              GROUP BY p.id, p.name, p.color
              ORDER BY open_count DESC, p.sort_order"
        );
    }

    /** Open work per category, which shows where onboarding stalls. */
    public static function openByCategory(): array
    {
        $st   = self::ST;
        $joins = self::openTasksJoins();
        $where = self::openTasksWhere();

        return Database::all(
            "SELECT c.name AS label, c.id AS category_id,
                    COUNT(*) AS open_count,
                    SUM(CASE WHEN {$st} = 'blocked' THEN 1 ELSE 0 END) AS blocked,
                    SUM(CASE WHEN pt.due_date IS NOT NULL AND pt.due_date < CURDATE()
                             THEN 1 ELSE 0 END) AS overdue
               {$joins}
               {$where}
                 AND {$st} NOT IN ('completed','not_applicable')
              GROUP BY c.id, c.name
              ORDER BY c.sort_order, c.name"
        );
    }

    /** Every task by status, for the distribution bar. */
    public static function countsByStatus(): array
    {
        $st   = self::ST;
        $joins = self::openTasksJoins();
        $where = self::openTasksWhere();

        $rows = Database::all(
            "SELECT {$st} AS status, COUNT(*) AS n {$joins} {$where} GROUP BY {$st}"
        );

        $out = array_fill_keys(array_keys(STATUSES), 0);
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Practices with a target go-live inside the next few weeks, soonest
     * first. The question this answers is "what lands next".
     */
    public static function upcomingGoLive(int $days = 60, int $limit = 6): array
    {
        $days  = max(1, min(365, $days));
        $limit = max(1, min(50, $limit));
        $st    = self::ST;

        return Database::all(
            "SELECT pr.id, pr.name, pr.target_go_live_date,
                    DATEDIFF(pr.target_go_live_date, CURDATE()) AS days_away,
                    SUM(CASE WHEN {$st} <> 'not_applicable' THEN 1 ELSE 0 END) AS countable,
                    SUM(CASE WHEN {$st} = 'completed' THEN 1 ELSE 0 END) AS completed
               FROM practices pr
               LEFT JOIN practice_products pp ON pp.practice_id = pr.id
               LEFT JOIN products p           ON p.id = pp.product_id AND p.is_active = 1
               LEFT JOIN tasks t              ON t.product_id = p.id AND t.is_active = 1
               LEFT JOIN practice_tasks pt    ON pt.practice_id = pr.id AND pt.task_id = t.id
              WHERE pr.is_archived = 0
                AND pr.onboarding_state <> 'completed'
                AND pr.target_go_live_date IS NOT NULL
                AND pr.target_go_live_date <= DATE_ADD(CURDATE(), INTERVAL {$days} DAY)
              GROUP BY pr.id, pr.name, pr.target_go_live_date
              ORDER BY pr.target_go_live_date
              LIMIT {$limit}"
        );
    }

    /**
     * Practices nothing has happened to in a while. A quiet practice is
     * easy to lose track of precisely because it raises no flags.
     */
    public static function stalled(int $days = 14, int $limit = 6): array
    {
        $days  = max(1, min(365, $days));
        $limit = max(1, min(50, $limit));

        return Database::all(
            "SELECT pr.id, pr.name,
                    GREATEST(pr.updated_at, COALESCE(MAX(pt.updated_at), pr.updated_at)) AS last_touch,
                    DATEDIFF(CURDATE(), DATE(GREATEST(pr.updated_at,
                             COALESCE(MAX(pt.updated_at), pr.updated_at)))) AS quiet_days
               FROM practices pr
               LEFT JOIN practice_tasks pt ON pt.practice_id = pr.id
              WHERE pr.is_archived = 0 AND pr.onboarding_state = 'active'
              GROUP BY pr.id, pr.name, pr.updated_at
             HAVING quiet_days >= {$days}
              ORDER BY quiet_days DESC
              LIMIT {$limit}"
        );
    }

    // =================================================================
    // Cross-practice task list (the All Tasks view)
    // =================================================================

    public static function tasksAcrossPractices(array $f = [], int $limit = 2000): array
    {
        $where = [];
        $args  = [];
        $st    = self::ST;

        $where[] = empty($f['include_archived']) ? 'pr.is_archived = 0' : '1 = 1';

        if (!empty($f['practice_id'])) {
            $where[] = 'pr.id = :practice_id';
            $args['practice_id'] = (int) $f['practice_id'];
        }
        if (!empty($f['state'])) {
            $where[] = 'pr.onboarding_state = :state';
            $args['state'] = (string) $f['state'];
        }
        if (!empty($f['product_id'])) {
            $where[] = 'p.id = :product_id';
            $args['product_id'] = (int) $f['product_id'];
        }
        if (!empty($f['category_id'])) {
            $where[] = 'c.id = :category_id';
            $args['category_id'] = (int) $f['category_id'];
        }
        if (!empty($f['status'])) {
            $where[] = "{$st} = :status";
            $args['status'] = (string) $f['status'];
        }
        $asg = self::ASG;

        if (!empty($f['assignee_id'])) {
            if ($f['assignee_id'] === 'none') {
                $where[] = "{$asg} IS NULL";
            } else {
                $where[] = "{$asg} = :assignee_id";
                $args['assignee_id'] = (int) $f['assignee_id'];
            }
        }
        if (!empty($f['only_blocked'])) {
            $where[] = "{$st} IN ('blocked','waiting')";
        }
        if (!empty($f['only_overdue'])) {
            $where[] = "pt.due_date IS NOT NULL AND pt.due_date < CURDATE()
                        AND {$st} NOT IN ('completed','not_applicable')";
        }
        if (empty($f['show_completed']) && empty($f['status'])) {
            $where[] = "{$st} <> 'completed'";
        }
        if (!empty($f['q'])) {
            $where[] = '(t.name LIKE :q1 OR pt.notes LIKE :q2 OR pr.name LIKE :q3)';
            $args['q1'] = $args['q2'] = $args['q3'] = '%' . $f['q'] . '%';
        }

        $limit = max(1, min(5000, $limit));

        $rows = Database::all(
            "SELECT pr.id AS practice_id, pr.name AS practice_name,
                    t.id AS task_id, t.name AS task_name,
                    p.id AS product_id, p.name AS product_name, p.color AS product_color,
                    c.id AS category_id, c.name AS category_name, c.color AS category_color,
                    c.sort_order AS category_sort,
                    {$st} AS status,
                    {$asg} AS assignee_id,
                    pt.due_date, pt.notes, pt.updated_at,
                    a.name AS assignee_name
               FROM practices pr
               JOIN practice_products pp   ON pp.practice_id = pr.id
               JOIN products p             ON p.id = pp.product_id AND p.is_active = 1
               JOIN tasks t                ON t.product_id = p.id AND t.is_active = 1
               JOIN categories c           ON c.id = t.category_id
               LEFT JOIN practice_tasks pt ON pt.practice_id = pr.id AND pt.task_id = t.id
               LEFT JOIN assignees a       ON a.id = {$asg}
              WHERE " . implode(' AND ', $where) . "
              ORDER BY p.sort_order, c.sort_order, c.name, pr.name, t.sort_order, t.id
              LIMIT {$limit}",
            $args
        );

        foreach ($rows as &$r) {
            $r['is_overdue'] = !empty($r['due_date'])
                && $r['due_date'] < date('Y-m-d')
                && !in_array($r['status'], ['completed', 'not_applicable'], true);
        }
        unset($r);

        return $rows;
    }

    // =================================================================
    // Writes to per-practice task state
    // =================================================================

    /**
     * Update per-practice state for one task, creating the row if needed.
     * Returns the refreshed derived row.
     *
     * @param array<string,mixed> $changes keys: status, assignee_id, due_date, notes
     */
    public static function saveTaskState(int $practiceId, int $taskId, array $changes): array
    {
        // The task must belong to a product this practice actually onboards.
        $valid = Database::scalar(
            'SELECT 1
               FROM tasks t
               JOIN practice_products pp ON pp.product_id = t.product_id
              WHERE t.id = :tid AND pp.practice_id = :pid AND t.is_active = 1
              LIMIT 1',
            ['tid' => $taskId, 'pid' => $practiceId]
        );
        if (!$valid) {
            throw new RuntimeException('That task does not apply to this practice.');
        }

        $existing = Database::one(
            'SELECT * FROM practice_tasks WHERE practice_id = :pid AND task_id = :tid',
            ['pid' => $practiceId, 'tid' => $taskId]
        );

        $before = [
            'status'      => $existing['status'] ?? 'not_started',
            'assignee_id' => isset($existing['assignee_id']) ? (int) $existing['assignee_id'] : null,
            'due_date'    => $existing['due_date'] ?? null,
            'notes'       => $existing['notes'] ?? null,
        ];

        $after = $before;
        foreach (['status', 'assignee_id', 'due_date', 'notes'] as $k) {
            if (array_key_exists($k, $changes)) {
                $after[$k] = $changes[$k];
            }
        }

        if (!isset(STATUSES[(string) $after['status']])) {
            throw new RuntimeException('Unknown status.');
        }
        if ($after['assignee_id'] !== null && !self::assignee((int) $after['assignee_id'])) {
            throw new RuntimeException('Unknown assignee.');
        }

        if ($existing) {
            Database::run(
                'UPDATE practice_tasks
                    SET status = :status, assignee_id = :assignee_id,
                        due_date = :due_date, notes = :notes
                  WHERE id = :id',
                [
                    'status'      => $after['status'],
                    'assignee_id' => $after['assignee_id'],
                    'due_date'    => $after['due_date'],
                    'notes'       => $after['notes'],
                    'id'          => (int) $existing['id'],
                ]
            );
        } else {
            Database::run(
                'INSERT INTO practice_tasks (practice_id, task_id, status, assignee_id, due_date, notes)
                 VALUES (:pid, :tid, :status, :assignee_id, :due_date, :notes)',
                [
                    'pid'         => $practiceId,
                    'tid'         => $taskId,
                    'status'      => $after['status'],
                    'assignee_id' => $after['assignee_id'],
                    'due_date'    => $after['due_date'],
                    'notes'       => $after['notes'],
                ]
            );
        }

        // Log only the fields that actually changed.
        foreach (['status', 'assignee_id', 'due_date', 'notes'] as $field) {
            if ((string) ($before[$field] ?? '') === (string) ($after[$field] ?? '')) {
                continue;
            }
            Activity::log(
                'practice_task',
                'update',
                $taskId,
                $practiceId,
                $taskId,
                $field,
                self::describe($field, $before[$field]),
                self::describe($field, $after[$field]),
                self::changeSummary($field, $before[$field], $after[$field])
            );
        }

        // Touch the practice so Last updated moves even for a note-only edit.
        Database::run('UPDATE practices SET updated_at = NOW() WHERE id = :id', ['id' => $practiceId]);

        return self::taskStateRow($practiceId, $taskId);
    }

    /** Fetch one derived task row after a write, for the JSON response. */
    public static function taskStateRow(int $practiceId, int $taskId): array
    {
        $st  = self::ST;
        $asg = self::ASG;
        $row = Database::one(
            "SELECT t.id AS task_id, t.name AS task_name,
                    p.id AS product_id, p.name AS product_name, p.color AS product_color,
                    c.id AS category_id, c.name AS category_name, c.color AS category_color,
                    c.sort_order AS category_sort,
                    {$st} AS status,
                    {$asg} AS assignee_id,
                    pt.assignee_id AS assignee_override,
                    t.default_assignee_id,
                    pt.due_date, pt.notes, pt.updated_at,
                    a.name AS assignee_name
               FROM tasks t
               JOIN products p   ON p.id = t.product_id
               JOIN categories c ON c.id = t.category_id
               LEFT JOIN practice_tasks pt ON pt.practice_id = :pid AND pt.task_id = t.id
               LEFT JOIN assignees a       ON a.id = {$asg}
              WHERE t.id = :tid",
            ['pid' => $practiceId, 'tid' => $taskId]
        );
        if (!$row) {
            throw new RuntimeException('Task not found.');
        }
        $row['is_overdue'] = !empty($row['due_date'])
            && $row['due_date'] < date('Y-m-d')
            && !in_array($row['status'], ['completed', 'not_applicable'], true);
        return $row;
    }

    private static function describe(string $field, $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($field === 'status') {
            return status_label((string) $value);
        }
        if ($field === 'assignee_id') {
            $a = self::assignee((int) $value);
            return $a['name'] ?? ('#' . $value);
        }
        return (string) $value;
    }

    private static function changeSummary(string $field, $old, $new): string
    {
        $labels = [
            'status' => 'Status', 'assignee_id' => 'Assignee',
            'due_date' => 'Due date', 'notes' => 'Notes',
        ];
        $label = $labels[$field] ?? $field;

        if ($field === 'notes') {
            return ($new === null || $new === '') ? 'Notes cleared' : 'Notes updated';
        }
        $o = self::describe($field, $old) ?? 'none';
        $n = self::describe($field, $new) ?? 'none';
        return sprintf('%s changed from %s to %s', $label, $o, $n);
    }

    /** Apply one change to many tasks at once. Returns the number affected. */
    public static function bulkUpdate(int $practiceId, array $taskIds, array $changes): int
    {
        $n = 0;
        foreach ($taskIds as $tid) {
            $tid = (int) $tid;
            if ($tid <= 0) {
                continue;
            }
            try {
                self::saveTaskState($practiceId, $tid, $changes);
                $n++;
            } catch (Throwable $ex) {
                error_log('bulkUpdate skipped task ' . $tid . ': ' . $ex->getMessage());
            }
        }
        return $n;
    }
}
