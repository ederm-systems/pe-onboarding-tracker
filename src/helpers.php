<?php
declare(strict_types=1);

/** Task statuses, in display order. Keys match the MySQL enum. */
const STATUSES = [
    'not_started'    => 'Not Started',
    'in_progress'    => 'In Progress',
    'waiting'        => 'Waiting',
    'blocked'        => 'Blocked',
    'completed'      => 'Completed',
    'not_applicable' => 'Not Applicable',
];

const PRACTICE_STATES = [
    'active'    => 'Active',
    'on_hold'   => 'On Hold',
    'completed' => 'Completed',
];

/** Escape for HTML output. */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Human label for a status key. */
function status_label(?string $key): string
{
    return STATUSES[$key ?? 'not_started'] ?? 'Not Started';
}

/** CSS modifier suffix for a status key. */
function status_class(?string $key): string
{
    return 'st-' . str_replace('_', '-', $key ?? 'not_started');
}

/** Whole-number percentage, guarding against divide by zero. */
function progress_pct(int $completed, int $countable): int
{
    return $countable > 0 ? (int) round(($completed * 100) / $countable) : 0;
}

/** Build a URL for the front controller, preserving nothing by default. */
function url(string $page, array $params = []): string
{
    $params = array_merge(['p' => $page], $params);
    return 'index.php?' . http_build_query($params);
}

/** Current query string with some params replaced. Used by filters and sorting. */
function url_with(array $overrides): string
{
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    return 'index.php?' . http_build_query($q);
}

/** Format a date for display, or a dash. */
function fmt_date(?string $ymd): string
{
    if (!$ymd || $ymd === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($ymd);
    return $ts ? date('M j, Y', $ts) : '—';
}

/** Relative time for "last updated" columns. */
function fmt_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    if (!$ts) {
        return '—';
    }
    $d = time() - $ts;
    if ($d < 60)     return 'just now';
    if ($d < 3600)   return floor($d / 60) . 'm ago';
    if ($d < 86400)  return floor($d / 3600) . 'h ago';
    if ($d < 604800) return floor($d / 86400) . 'd ago';
    return date('M j, Y', $ts);
}

/** Days until a date. Negative means overdue. Null if no date. */
function days_until(?string $ymd): ?int
{
    if (!$ymd || $ymd === '0000-00-00') {
        return null;
    }
    $ts = strtotime($ymd . ' 00:00:00');
    if (!$ts) {
        return null;
    }
    return (int) floor(($ts - strtotime('today')) / 86400);
}

/** URL-safe slug. */
function slugify(string $text): string
{
    $s = strtolower(trim($text));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 190) : 'item';
}

/**
 * Cache-busting URL for a file in public/.
 *
 * APP_VERSION was hardcoded, so browsers kept serving a stale
 * stylesheet after every deploy and the only cure was a hard refresh.
 * Keying it to the file's modification time means a deploy invalidates
 * the cache by itself.
 */
function asset(string $file): string
{
    $path = APP_ROOT . '/public/' . ltrim($file, '/');
    $v    = is_file($path) ? (string) filemtime($path) : APP_VERSION;
    return $file . '?v=' . $v;
}

/** Send a redirect and stop. */
function redirect(string $to)
{
    header('Location: ' . $to);
    exit;
}

/** Read a trimmed string from POST. */
function post_str(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Read an int from POST, or null when blank. */
function post_int_or_null(string $key): ?int
{
    $v = $_POST[$key] ?? '';
    if ($v === '' || $v === null) {
        return null;
    }
    return (int) $v;
}

/** A YYYY-MM-DD date, or null when blank or not a real date. */
function valid_date(?string $v): ?string
{
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    // The round trip rejects 2026-02-31, which createFromFormat itself
    // happily rolls forward into March.
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** Read a YYYY-MM-DD date from POST, or null when blank or invalid. */
function post_date_or_null(string $key): ?string
{
    $v = trim((string) ($_POST[$key] ?? ''));
    if ($v === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

/** Flash a one-time message into the session. */
function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

/** Pull and clear flash messages. */
function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($f) ? $f : [];
}

/**
 * Up to two initials from a person's name. "Maya Reyes" gives MR,
 * "Cher" gives CH, an empty name gives a question mark so the slot
 * still has something in it.
 */
function initials(?string $name): string
{
    $name = trim((string) $name);
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [];
    if (count($parts) >= 2) {
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[count($parts) - 1], 0, 1));
    }
    return mb_strtoupper(mb_substr($name, 0, 2));
}

/**
 * One of eight tints, chosen from the name itself, so the same person
 * keeps the same colour on every screen without storing anything.
 */
function avatar_tint(?string $name): int
{
    $name = trim((string) $name);
    return $name === '' ? 0 : (abs(crc32(mb_strtolower($name))) % 7) + 1;
}

/**
 * An initials bubble. Pass null or an empty name for the unassigned
 * slot, which comes out grey with a question mark.
 */
function avatar(?string $name, bool $small = true): string
{
    $cls = 'avatar' . ($small ? ' avatar-sm' : '') . ' av-' . avatar_tint($name);
    $label = trim((string) $name) === '' ? 'Unassigned' : $name;
    return '<span class="' . $cls . '" aria-hidden="true">' . e(initials($name)) . '</span>'
         . '<span class="sr-only">' . e($label) . '</span>';
}

/** An initials bubble followed by the name, for table cells. */
function person(?string $name, string $fallback = 'Unassigned'): string
{
    $shown = trim((string) $name) === '' ? $fallback : $name;
    return '<span class="person">'
         . '<span class="avatar avatar-sm av-' . avatar_tint($name) . '" aria-hidden="true">'
         . e(initials($name)) . '</span>'
         . '<span class="person-name">' . e($shown) . '</span></span>';
}

/** Respond with JSON and stop. */
function json_out(array $payload, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
