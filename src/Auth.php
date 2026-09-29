<?php
declare(strict_types=1);

/**
 * Who is using the portal, and what they may do.
 *
 * Three states:
 *
 * Three kinds of user, and a locked door:
 *
 *   admin       The global administrator. Signs in with the PIN in
 *               config/config.php. May do anything, including delete.
 *   specialist  An Onboarding Specialist. Adds and edits practices,
 *               products, categories and the task library, moves any
 *               task forward on any practice, and reads the activity
 *               log. Cannot delete, deactivate or archive anything, and
 *               cannot reach People or the Archive.
 *   member      A team member. Sees everything, and may change only the
 *               tasks assigned to them.
 *   guest       Not signed in. Sees the sign-in page and nothing else.
 *
 * Permissions are asked as capability questions (canManagePractices,
 * canDelete and so on) rather than by comparing roles at each call
 * site, so adding a role later means changing this file and nothing
 * else.
 *
 * Every permission question is answered here, and every answer is
 * checked again in the request handler before anything is written.
 * Hiding a control in a template is a courtesy, not a control.
 */
final class Auth
{
    public const ADMIN      = 'admin';
    public const SPECIALIST = 'specialist';
    public const MEMBER     = 'member';
    public const GUEST      = 'guest';

    /** Shown in the top bar and on the People screen. */
    public const LEVELS = [
        'member'     => 'Team member',
        'specialist' => 'Onboarding Specialist',
    ];

    private static array $config = [];
    private static ?array $memberCache = null;

    public static function configure(array $config): void
    {
        self::$config = $config;
    }

    // -----------------------------------------------------------------
    // Who is here
    // -----------------------------------------------------------------

    public static function role(): string
    {
        if (!self::sessionFresh()) {
            return self::GUEST;
        }
        if (!empty($_SESSION['is_admin'])) {
            return self::ADMIN;
        }
        $m = self::member();
        if (!empty($_SESSION['member_id']) && $m !== null) {
            return ($m['access_level'] ?? 'member') === 'specialist'
                ? self::SPECIALIST
                : self::MEMBER;
        }
        return self::GUEST;
    }

    public static function isSpecialist(): bool
    {
        return self::role() === self::SPECIALIST;
    }

    public static function isAdmin(): bool
    {
        return self::role() === self::ADMIN;
    }

    /** True for a team member or a specialist, both of whom are people. */
    public static function isMember(): bool
    {
        $r = self::role();
        return $r === self::MEMBER || $r === self::SPECIALIST;
    }

    /** True for anyone signed in, admin or member. */
    public static function isSignedIn(): bool
    {
        return self::role() !== self::GUEST;
    }

    /** The signed-in member's row, or null. */
    public static function member(): ?array
    {
        if (empty($_SESSION['member_id'])) {
            return null;
        }
        if (self::$memberCache !== null) {
            return self::$memberCache;
        }
        $row = Database::one(
            'SELECT * FROM assignees WHERE id = :id AND is_active = 1',
            ['id' => (int) $_SESSION['member_id']]
        );
        // Deactivating someone ends their session at the next request.
        if (!$row) {
            unset($_SESSION['member_id']);
            return null;
        }
        self::$memberCache = $row;
        return $row;
    }

    /** The signed-in person's id, or null for the admin and guests. */
    public static function memberId(): ?int
    {
        $m = self::isMember() ? self::member() : null;
        return $m ? (int) $m['id'] : null;
    }

    /** Wording for the pill in the top bar. */
    public static function roleLabel(): string
    {
        switch (self::role()) {
            case self::ADMIN:      return 'Admin';
            case self::SPECIALIST: return 'Specialist';
            case self::MEMBER:     return 'Team';
            default:               return '';
        }
    }

    /** Name to show in the top bar. */
    public static function displayName(): string
    {
        if (self::isAdmin()) {
            return 'Administrator';
        }
        $m = self::member();
        return $m ? (string) $m['name'] : 'Guest';
    }

    /** Name recorded against every change in the activity log. */
    public static function actor(): string
    {
        if (self::isAdmin()) {
            return 'Administrator';
        }
        $m = self::member();
        if (!$m) {
            return 'Unknown';
        }
        $full = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
        return $full !== '' ? $full : (string) $m['name'];
    }

    private static function sessionFresh(): bool
    {
        if (empty($_SESSION['signed_in_at'])) {
            return false;
        }
        $ttl  = max(5, (int) (self::$config['session_minutes'] ?? 480)) * 60;
        $last = (int) $_SESSION['signed_in_at'];
        if ($last <= 0 || (time() - $last) > $ttl) {
            self::logout();
            return false;
        }
        return true;
    }

    // -----------------------------------------------------------------
    // What they may do
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // Capabilities. Ask these, never compare roles at the call site.
    // -----------------------------------------------------------------

    /** Products, categories, people, archive: the administrator alone. */
    public static function canManage(): bool
    {
        return self::isAdmin();
    }

    /** Add and edit practices, and choose their products. */
    public static function canManagePractices(): bool
    {
        return self::isAdmin() || self::isSpecialist();
    }

    /** Add, edit, reorder and reassign tasks in the library. */
    public static function canManageLibrary(): bool
    {
        return self::isAdmin() || self::isSpecialist();
    }

    /**
     * Add and edit products and task categories. Switching one off is
     * not included: that hides its tasks from every practice at once,
     * so it counts as removal and stays with the administrator.
     */
    public static function canManageCatalogue(): bool
    {
        return self::isAdmin() || self::isSpecialist();
    }

    /** Read the activity log. Nothing can be changed from it. */
    public static function canViewActivity(): bool
    {
        return self::isAdmin() || self::isSpecialist();
    }

    /**
     * Destroy or hide: deleting anything, deactivating a task,
     * archiving a practice. Reserved to the administrator, because
     * deactivating one task removes it from every practice at once.
     */
    public static function canDelete(): bool
    {
        return self::isAdmin();
    }

    /**
     * May the current user change this task on this practice?
     *
     * Admin: always. Member: only when the task's effective assignee is
     * them, which includes tasks they hold through a default assignee.
     */
    public static function canEditTask(int $practiceId, int $taskId): bool
    {
        // A specialist runs onboarding, so any task on any practice.
        if (self::isAdmin() || self::isSpecialist()) {
            return true;
        }
        $me = self::memberId();
        if ($me === null) {
            return false;
        }

        $owner = Database::scalar(
            'SELECT COALESCE(pt.assignee_id, t.default_assignee_id)
               FROM tasks t
               LEFT JOIN practice_tasks pt
                      ON pt.practice_id = :pid AND pt.task_id = t.id
              WHERE t.id = :tid',
            ['pid' => $practiceId, 'tid' => $taskId]
        );

        return $owner !== null && (int) $owner === $me;
    }

    // -----------------------------------------------------------------
    // Signing in
    // -----------------------------------------------------------------

    public static function pinConfigured(): bool
    {
        return trim((string) (self::$config['admin_pin_hash'] ?? '')) !== '';
    }

    /**
     * One PIN box. It is matched against the administrator PIN first,
     * then against every active person.
     *
     * Because the PIN is the only credential, a wrong one is counted
     * against the caller's address in the database, not the session,
     * so clearing cookies does not reset the count.
     *
     * Returns an error message, or null on success.
     */
    public static function attempt(string $pin): ?string
    {
        if ($err = self::throttle()) {
            return $err;
        }

        $pin = trim($pin);

        // The administrator first.
        if (self::pinConfigured() && password_verify($pin, (string) self::$config['admin_pin_hash'])) {
            usleep(200000);
            self::startSession();
            $_SESSION['is_admin'] = true;
            return null;
        }

        // Then each person who has a PIN. Hashes are salted, so there is
        // no way to look one up; each has to be verified in turn. With a
        // team of this size that is a handful of comparisons.
        $people = Database::all(
            'SELECT id, pin_hash FROM assignees
              WHERE is_active = 1 AND pin_hash IS NOT NULL AND pin_hash <> \'\''
        );

        foreach ($people as $person) {
            if (password_verify($pin, (string) $person['pin_hash'])) {
                usleep(200000);
                self::startSession();
                $_SESSION['member_id'] = (int) $person['id'];
                self::$memberCache = null;
                Database::run('UPDATE assignees SET last_login = NOW() WHERE id = :id',
                    ['id' => (int) $person['id']]);
                return null;
            }
        }

        usleep(200000);
        self::recordFailure();
        return 'That PIN was not recognised.';
    }

    /**
     * Is this PIN already somebody's? Called before setting one, because
     * two people sharing a PIN would make sign-in ambiguous.
     */
    public static function pinInUse(string $pin, ?int $exceptId = null): bool
    {
        if (self::pinConfigured() && password_verify($pin, (string) self::$config['admin_pin_hash'])) {
            return true;
        }
        $rows = Database::all(
            'SELECT id, pin_hash FROM assignees WHERE pin_hash IS NOT NULL AND pin_hash <> \'\''
        );
        foreach ($rows as $r) {
            if ($exceptId !== null && (int) $r['id'] === $exceptId) {
                continue;
            }
            if (password_verify($pin, (string) $r['pin_hash'])) {
                return true;
            }
        }
        return false;
    }

    private static function startSession(): void
    {
        // A successful sign-in clears the failures for this address.
        try {
            Database::run('DELETE FROM login_attempts WHERE ip = :ip', ['ip' => self::ipKey()]);
        } catch (Throwable $ex) {
            error_log('could not clear sign-in failures: ' . $ex->getMessage());
        }
        session_regenerate_id(true);
        $_SESSION['signed_in_at'] = time();
    }

    /** The caller's address, packed, for the attempts table. */
    private static function ipKey(): string
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $packed = @inet_pton($ip);
        return $packed !== false ? $packed : inet_pton('0.0.0.0');
    }

    /**
     * Refuse further attempts after too many failures from one address
     * in fifteen minutes. Counted in the database rather than the
     * session, so it survives the cookie being thrown away.
     */
    private static function throttle(): ?string
    {
        try {
            $n = (int) Database::scalar(
                'SELECT COUNT(*) FROM login_attempts
                  WHERE ip = :ip AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
                ['ip' => self::ipKey()]
            );
        } catch (Throwable $ex) {
            // If the table is missing, fail closed on counting but let
            // people in, rather than locking everybody out of the portal.
            error_log('login throttle unavailable: ' . $ex->getMessage());
            return null;
        }

        return $n >= 10
            ? 'Too many incorrect PINs from this connection. Wait 15 minutes and try again.'
            : null;
    }

    private static function recordFailure(): void
    {
        try {
            Database::run('INSERT INTO login_attempts (ip) VALUES (:ip)', ['ip' => self::ipKey()]);
            // Keep the table from growing without limit.
            if (random_int(1, 50) === 1) {
                Database::run(
                    'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)'
                );
            }
        } catch (Throwable $ex) {
            error_log('could not record a failed sign-in: ' . $ex->getMessage());
        }
    }

    public static function logout(): void
    {
        unset($_SESSION['is_admin'], $_SESSION['member_id'], $_SESSION['signed_in_at']);
        self::$memberCache = null;
    }

    /** Confirm the administrator PIN again, for destructive actions. */
    public static function confirmAdminPin(string $pin): bool
    {
        return self::pinConfigured()
            && password_verify($pin, (string) self::$config['admin_pin_hash']);
    }

    // -----------------------------------------------------------------
    // Guards
    // -----------------------------------------------------------------

    /** Everything needs a sign-in now. */
    public static function requireLogin(bool $json = false): void
    {
        if (self::isSignedIn()) {
            return;
        }
        if ($json) {
            json_out(['ok' => false, 'error' => 'Your session ended. Reload and sign in again.'], 401);
        }
        redirect(url('login', ['next' => $_SERVER['REQUEST_URI'] ?? '']));
    }

    /** Refuse unless the given capability holds. */
    public static function requireCan(string $capability, bool $json = false): void
    {
        $ok = false;
        switch ($capability) {
            case 'manage':           $ok = self::canManage();          break;
            case 'manage_practices': $ok = self::canManagePractices(); break;
            case 'manage_library':   $ok = self::canManageLibrary();   break;
            case 'manage_catalogue': $ok = self::canManageCatalogue(); break;
            case 'view_activity':    $ok = self::canViewActivity();    break;
            case 'delete':           $ok = self::canDelete();          break;
        }
        if ($ok) {
            return;
        }
        if ($json) {
            json_out(['ok' => false, 'error' => 'You do not have access to that.'], 403);
        }
        if (self::isSignedIn()) {
            http_response_code(403);
            render('error', [
                'title'   => 'Not available to you',
                'message' => $capability === 'delete'
                    ? 'Only the administrator can delete, deactivate or archive. Ask them if something needs removing.'
                    : 'That screen is for the administrator. You can view everything, and change what your role allows.',
            ]);
            exit;
        }
        redirect(url('login', ['next' => $_SERVER['REQUEST_URI'] ?? '']));
    }

    /** Administration only. */
    public static function requireAdmin(bool $json = false): void
    {
        if (self::isAdmin()) {
            return;
        }
        if ($json) {
            json_out(['ok' => false, 'error' => 'Only the administrator can do that.'], 403);
        }
        if (self::isSignedIn()) {
            http_response_code(403);
            render('error', [
                'title'   => 'Not available to you',
                'message' => 'That screen is for the administrator. You can view everything, '
                           . 'and change what your role allows.',
            ]);
            exit;
        }
        redirect(url('login', ['next' => $_SERVER['REQUEST_URI'] ?? '']));
    }
}
