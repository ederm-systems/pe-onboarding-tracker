<?php
/**
 * Shared page shell. Included by render() after the view variables are
 * extracted, so $__template and everything the view needs is in scope.
 *
 * @var string $__template
 * @var array  $config
 * @var bool   $is_admin
 * @var array  $flashes
 */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($page_title ?? 'Onboarding') ?> · <?= e($config['app_name']) ?></title>
<link rel="stylesheet" href="<?= e(asset('assets/app.css')) ?>">
</head>
<?php
  // The sign-in page and the practice's own shared page both stand
  // alone: no navigation, because none of it applies to whoever is
  // looking at them.
  $bare = in_array($template, ['login', 'share'], true);
?>
<body class="<?= $bare ? 'auth-page' : '' ?><?= $template === 'share' ? ' share-page' : '' ?>">

<a class="skip-link" href="#main">Skip to content</a>

<?php if (!$bare): ?>
<?php
  /**
   * Which sidebar link is lit. The practice detail and the practice
   * form both belong under Practices.
   */
  $on = static function (array $names) use ($template): string {
      foreach ($names as $n) {
          if ($template === $n || ($n !== '' && str_starts_with($template, $n . '/'))) {
              return ' class="on" aria-current="page"';
          }
      }
      return '';
  };
?>

<svg style="display:none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
  <symbol id="i-grid" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></symbol>
  <symbol id="i-build" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V7l7-4 7 4v14"/><path d="M9 21v-5h6v5"/></symbol>
  <symbol id="i-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></symbol>
  <symbol id="i-list" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol>
  <symbol id="i-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8l-9-5-9 5v8l9 5 9-5z"/><path d="M3.3 7.3 12 12l8.7-4.7M12 12v9"/></symbol>
  <symbol id="i-tag" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 12 22l-9-9V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.3"/></symbol>
  <symbol id="i-pulse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></symbol>
  <symbol id="i-people" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9"/><path d="M16 3.1a4 4 0 0 1 0 7.8"/></symbol>
  <symbol id="i-arch" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="5" rx="1.5"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></symbol>
  <symbol id="i-clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
  <symbol id="i-alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></symbol>
</svg>

<div class="shell">

<nav class="side" aria-label="Main">
  <a class="side-brand" href="<?= e(url('dashboard')) ?>">
    <span class="side-mark" aria-hidden="true">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#fff"
           stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m4 12 5.5 5.5L20 7"/></svg>
    </span>
    <span class="side-name">Onboarding<span>Practice Engine</span></span>
  </a>

  <div class="side-group">Overview</div>
  <a href="<?= e(url('dashboard')) ?>"<?= $on(['dashboard']) ?>><svg><use href="#i-grid"/></svg> Dashboard</a>
  <a href="<?= e(url('practices')) ?>"<?= $on(['practices', 'practice', 'admin/practice-form']) ?>><svg><use href="#i-build"/></svg> Practices</a>
  <a href="<?= e(url('tasks')) ?>"<?= $on(['tasks']) ?>><svg><use href="#i-check"/></svg> <?= Auth::isMember() ? 'My tasks' : 'All tasks' ?></a>

  <?php if ($can_library || $can_catalogue): ?>
    <div class="side-group">Set up</div>
    <?php if ($can_library): ?>
      <a href="<?= e(url('admin/tasks')) ?>"<?= $on(['admin/tasks', 'admin/task-form']) ?>><svg><use href="#i-list"/></svg> Task library</a>
    <?php endif; ?>
    <?php if ($can_catalogue): ?>
      <a href="<?= e(url('admin/products')) ?>"<?= $on(['admin/products']) ?>><svg><use href="#i-box"/></svg> Products</a>
      <a href="<?= e(url('admin/categories')) ?>"<?= $on(['admin/categories']) ?>><svg><use href="#i-tag"/></svg> Categories</a>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($can_activity || $is_admin): ?>
    <div class="side-group">Admin</div>
    <?php if ($can_activity): ?>
      <a href="<?= e(url('admin/activity')) ?>"<?= $on(['admin/activity']) ?>><svg><use href="#i-pulse"/></svg> Activity</a>
    <?php endif; ?>
    <?php if ($is_admin): ?>
      <a href="<?= e(url('admin/assignees')) ?>"<?= $on(['admin/assignees']) ?>><svg><use href="#i-people"/></svg> People</a>
      <a href="<?= e(url('admin/archive')) ?>"<?= $on(['admin/archive']) ?>><svg><use href="#i-arch"/></svg> Archive</a>
    <?php endif; ?>
  <?php endif; ?>

  <div class="side-foot">
    <?php if ($is_admin || Auth::isMember()): ?>
      <?php $me = $is_admin ? 'Administrator' : Auth::displayName(); ?>
      <div class="side-me">
        <?= avatar($me, false) ?>
        <span class="side-me-txt">
          <span class="side-me-name"><?= e($is_admin ? 'Administrator' : Auth::displayName()) ?></span>
          <span class="side-me-role"><?= e($is_admin ? 'Full access' : Auth::roleLabel()) ?></span>
        </span>
      </div>
      <a class="side-out" href="<?= e(url('logout')) ?>">Sign out</a>
    <?php else: ?>
      <a class="side-out" href="<?= e(url('login')) ?>">Sign in</a>
    <?php endif; ?>
  </div>
</nav>

<div class="shell-main">
<?php endif; ?>

<main id="main" class="wrap">

  <?php foreach ($flashes as $fl): ?>
    <div class="flash flash-<?= e($fl['type']) ?>" role="status"><?= e($fl['msg']) ?></div>
  <?php endforeach; ?>

  <?php require $__template; ?>

</main>

<footer class="foot">
  <p>
    Operational onboarding data only. Do not enter patient information anywhere in this tracker.
  </p>
</footer>

<?php if (!$bare): ?>
</div><!-- /shell-main -->
</div><!-- /shell -->
<?php endif; ?>

<script>
  window.POT = {
    csrf: <?= json_encode(Csrf::token()) ?>,
    isAdmin: <?= $is_admin ? 'true' : 'false' ?>,
    statuses: <?= json_encode(STATUSES) ?>
  };
</script>
<script src="<?= e(asset('assets/app.js')) ?>"></script>
</body>
</html>
