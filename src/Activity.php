<?php
declare(strict_types=1);

/** Append-only audit trail. Also the source of truth for "last updated". */
final class Activity
{
    public static function log(
        string $entity,
        string $action,
        ?int $entityId = null,
        ?int $practiceId = null,
        ?int $taskId = null,
        ?string $field = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $summary = null
    ): void {
        try {
            Database::run(
                'INSERT INTO activity_log
                   (practice_id, task_id, actor, entity, entity_id, action, field, old_value, new_value, summary)
                 VALUES (:practice_id, :task_id, :actor, :entity, :entity_id, :action, :field, :old_value, :new_value, :summary)',
                [
                    'practice_id' => $practiceId,
                    'task_id'     => $taskId,
                    'actor'       => Auth::actor(),
                    'entity'      => $entity,
                    'entity_id'   => $entityId,
                    'action'      => $action,
                    'field'       => $field,
                    'old_value'   => self::trim($oldValue),
                    'new_value'   => self::trim($newValue),
                    'summary'     => $summary === null ? null : mb_substr($summary, 0, 400),
                ]
            );
        } catch (Throwable $ex) {
            // Never let logging break the request it is describing.
            error_log('activity_log failed: ' . $ex->getMessage());
        }
    }

    private static function trim(?string $v): ?string
    {
        return $v === null ? null : mb_substr($v, 0, 2000);
    }

    /** Recent entries for one practice. */
    public static function forPractice(int $practiceId, int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            "SELECT l.*, t.name AS task_name, p.name AS product_name
               FROM activity_log l
               LEFT JOIN tasks t    ON t.id = l.task_id
               LEFT JOIN products p ON p.id = t.product_id
              WHERE l.practice_id = :pid
              ORDER BY l.created_at DESC, l.id DESC
              LIMIT {$limit}",
            ['pid' => $practiceId]
        );
    }

    // -----------------------------------------------------------------
    // Reading the log back: filtering, paging, and plain-English labels
    // -----------------------------------------------------------------

    public const PER_PAGE = 100;

    /**
     * Build the WHERE clause and its parameters from a filter set.
     *
     * The placeholders are numbered because the connection runs with
     * emulated prepares switched off, where the same named placeholder
     * cannot appear twice in one statement.
     *
     * @return array{0: string, 1: array<string,mixed>}
     */
    private static function where(array $f): array
    {
        $w = [];
        $p = [];

        // 'none' means the change was not about any one practice, such as
        // editing the task library or adding a person.
        if (($f['practice_id'] ?? null) === 'none') {
            $w[] = 'l.practice_id IS NULL';
        } elseif (!empty($f['practice_id'])) {
            $w[] = 'l.practice_id = :pid';
            $p['pid'] = (int) $f['practice_id'];
        }

        if (!empty($f['actor'])) {
            $w[] = 'l.actor = :actor';
            $p['actor'] = (string) $f['actor'];
        }
        if (!empty($f['action'])) {
            $w[] = 'l.action = :action';
            $p['action'] = (string) $f['action'];
        }
        if (!empty($f['entity'])) {
            $w[] = 'l.entity = :entity';
            $p['entity'] = (string) $f['entity'];
        }

        // A change to one named field, or one recorded against no
        // particular field at all.
        if (($f['field'] ?? null) === 'none') {
            $w[] = 'l.field IS NULL';
        } elseif (!empty($f['field'])) {
            $w[] = 'l.field = :field';
            $p['field'] = (string) $f['field'];
        }

        // Both ends of the range are inclusive: a person picking the same
        // date twice expects that day's entries, not none.
        if (!empty($f['from'])) {
            $w[] = 'l.created_at >= :from';
            $p['from'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $w[] = 'l.created_at <= :to';
            $p['to'] = $f['to'] . ' 23:59:59';
        }

        if (($f['q'] ?? '') !== '') {
            $w[] = '(l.summary LIKE :q1 OR t.name LIKE :q2 OR pr.name LIKE :q3'
                 . ' OR l.old_value LIKE :q4 OR l.new_value LIKE :q5)';
            $like = '%' . $f['q'] . '%';
            $p['q1'] = $like; $p['q2'] = $like; $p['q3'] = $like;
            $p['q4'] = $like; $p['q5'] = $like;
        }

        return [$w ? ('WHERE ' . implode(' AND ', $w)) : '', $p];
    }

    /**
     * One page of the log, filtered.
     *
     * The filters run in the database against the whole table rather than
     * over a recent slice, which is the difference between an audit trail
     * and a list of what happened lately.
     *
     * @return array{rows: array, total: int, page: int, pages: int, from: int, to: int}
     */
    public static function search(array $f, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        [$where, $params] = self::where($f);
        $perPage = max(10, min(500, $perPage));

        $total = (int) Database::scalar(
            "SELECT COUNT(*)
               FROM activity_log l
               LEFT JOIN practices pr ON pr.id = l.practice_id
               LEFT JOIN tasks t      ON t.id = l.task_id
             {$where}",
            $params
        );

        $pages = max(1, (int) ceil($total / $perPage));
        $page  = max(1, min($pages, $page));
        // Cast, not bound: MySQL will not take a placeholder in LIMIT
        // once emulated prepares are off.
        $offset = ($page - 1) * $perPage;

        $rows = $total === 0 ? [] : Database::all(
            "SELECT l.*, pr.name AS practice_name, t.name AS task_name, pd.name AS product_name
               FROM activity_log l
               LEFT JOIN practices pr ON pr.id = l.practice_id
               LEFT JOIN tasks t      ON t.id = l.task_id
               LEFT JOIN products pd  ON pd.id = t.product_id
             {$where}
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        );

        return [
            'rows'  => $rows,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'from'  => $total === 0 ? 0 : $offset + 1,
            'to'    => $offset + count($rows),
        ];
    }

    /**
     * The values that actually occur in the log, for the dropdowns.
     *
     * Read from the log rather than hard-coded, so a filter can never
     * offer something with no matches, and a new kind of entry starts
     * appearing without anybody remembering to add it here.
     */
    public static function choices(): array
    {
        // The column name is interpolated rather than bound, which a
        // placeholder cannot do. It is only ever one of the four literals
        // passed in below, never anything from the request.
        $col = static function (string $c): array {
            $rows = Database::all(
                "SELECT DISTINCT {$c} AS v FROM activity_log WHERE {$c} IS NOT NULL AND {$c} <> '' ORDER BY v"
            );
            return array_map(static fn($r) => (string) $r['v'], $rows);
        };

        return [
            'actors'   => $col('actor'),
            'actions'  => $col('action'),
            'entities' => $col('entity'),
            'fields'   => $col('field'),
            // Whether any entry has no field at all, which decides
            // whether the "No single field" option is worth offering.
            'has_null_field' => (int) Database::scalar(
                'SELECT COUNT(*) FROM activity_log WHERE field IS NULL'
            ) > 0,
            'has_null_practice' => (int) Database::scalar(
                'SELECT COUNT(*) FROM activity_log WHERE practice_id IS NULL'
            ) > 0,
        ];
    }

    /** Plain English for the stored keys. Falls back to prettifying. */
    private const LABELS = [
        // What sort of thing changed
        'practice'         => 'Practice',
        'practice_product' => 'Practice product',
        'practice_task'    => 'Task on a practice',
        'task'             => 'Task library',
        'product'          => 'Product',
        'category'         => 'Category',
        'assignee'         => 'Person',
        'auth'             => 'Sign in',
        // What was done
        'create'        => 'Created',
        'update'        => 'Updated',
        'delete'        => 'Deleted',
        'purge'         => 'Permanently deleted',
        'archive'       => 'Archived',
        'restore'       => 'Restored',
        'deactivate'    => 'Deactivated',
        'reactivate'    => 'Reactivated',
        'reorder'       => 'Reordered',
        'add'           => 'Added',
        'remove'        => 'Removed',
        'login'         => 'Signed in',
        'set_pin'       => 'PIN changed',
        'share_create'  => 'Share link created',
        'share_replace' => 'Share link replaced',
        'share_revoke'  => 'Share link revoked',
        // Which field
        'status'      => 'Status',
        'assignee_id' => 'Assignee',
        'due_date'    => 'Due date',
        'notes'       => 'Notes',
        'sort_order'  => 'Order',
        'pin'         => 'PIN',
        'name'        => 'Name',
    ];

    public static function label(?string $key): string
    {
        $key = (string) $key;
        if ($key === '') {
            return '';
        }
        return self::LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
