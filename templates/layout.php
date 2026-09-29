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

<?php /* The top bar is hidden on the sign-in page: none of its links
         lead anywhere until you are signed in. */ ?>
<?php if (!$bare): ?>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="<?= e(url('dashboard')) ?>">
      <span class="brand-mark" aria-hidden="true"></span>
      <span class="brand-text"><?= e($config['app_name']) ?></span>
    </a>

    <nav class="nav" aria-label="Main">
      <a href="<?= e(url('dashboard')) ?>" <?= ($template === 'dashboard') ? 'class="on" aria-current="page"' : '' ?>>Dashboard</a>
      <a href="<?= e(url('practices')) ?>" <?= ($template === 'practices' || str_starts_with($template, 'admin/practice')) ? 'class="on" aria-current="page"' : '' ?>>Practices</a>
      <a href="<?= e(url('tasks')) ?>" <?= ($template === 'tasks') ? 'class="on" aria-current="page"' : '' ?>><?= Auth::isMember() ? 'Tasks' : 'All tasks' ?></a>
      <?php if ($can_library): ?>
        <a href="<?= e(url('admin/tasks')) ?>" <?= ($template === 'admin/tasks') ? 'class="on"' : '' ?>>Task library</a>
      <?php endif; ?>
      <?php if ($can_catalogue): ?>
        <a href="<?= e(url('admin/products')) ?>" <?= ($template === 'admin/products') ? 'class="on"' : '' ?>>Products</a>
        <a href="<?= e(url('admin/categories')) ?>" <?= ($template === 'admin/categories') ? 'class="on"' : '' ?>>Categories</a>
      <?php endif; ?>
      <?php if ($can_activity): ?>
        <a href="<?= e(url('admin/activity')) ?>" <?= ($template === 'admin/activity') ? 'class="on"' : '' ?>>Activity</a>
      <?php endif; ?>
      <?php if ($is_admin): ?>
        <a href="<?= e(url('admin/assignees')) ?>" <?= ($template === 'admin/assignees') ? 'class="on"' : '' ?>>People</a>
        <a href="<?= e(url('admin/archive')) ?>" <?= ($template === 'admin/archive') ? 'class="on"' : '' ?>>Archive</a>
      <?php endif; ?>
    </nav>

    <div class="topbar-right">
      <?php if ($is_admin): ?>
        <span class="mode-pill mode-admin" title="You can change anything">Admin</span>
        <a class="btn btn-quiet btn-sm" href="<?= e(url('logout')) ?>">Sign out</a>
      <?php elseif (Auth::isMember()): ?>
        <span class="who"><?= e(Auth::displayName()) ?></span>
        <span class="mode-pill <?= Auth::isSpecialist() ? 'mode-spec' : 'mode-view' ?>"
              title="<?= Auth::isSpecialist()
                ? 'You can manage practices and the task library, and move any task forward'
                : 'You can change the tasks assigned to you' ?>"><?= e(Auth::roleLabel()) ?></span>
        <a class="btn btn-quiet btn-sm" href="<?= e(url('logout')) ?>">Sign out</a>
      <?php else: ?>
        <a class="btn btn-quiet btn-sm" href="<?= e(url('login')) ?>">Sign in</a>
      <?php endif; ?>
    </div>
  </div>
</header>
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
