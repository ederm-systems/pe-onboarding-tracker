<?php
/**
 * Sign in.
 *
 * One PIN box: the administrator PIN and each person's PIN both go
 * here, and the portal works out which it is. Rendered without the top
 * bar, since none of its links go anywhere until you are signed in.
 *
 * @var ?string $error
 * @var string  $next
 * @var array   $config
 */
$page_title = 'Sign in';
?>
<div class="auth">
  <div class="auth-card">

    <div class="auth-brand">
      <span class="auth-mark" aria-hidden="true"></span>
      <span class="auth-name"><?= e($config['app_name']) ?></span>
    </div>

    <h1 class="auth-title">Welcome back</h1>
    <p class="auth-sub">Enter your 6-digit PIN to continue.</p>

    <?php if ($error): ?>
      <div class="flash flash-error" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('login')) ?>" autocomplete="off" class="auth-form">
      <?= Csrf::field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">

      <div class="auth-pin">
        <label for="pin" class="sr-only">PIN</label>
        <?php /* No maxlength of 6: the administrator PIN may be a
                 longer passphrase, and capping the field would stop it
                 being typed at all. */ ?>
        <input id="pin" type="password" name="pin" required autofocus
               class="pin-input" inputmode="numeric" maxlength="64"
               autocomplete="current-password" spellcheck="false"
               placeholder="••••••">
        <button type="button" class="pin-peek" data-pin-peek
                aria-label="Show PIN" title="Show PIN">Show</button>
      </div>

      <button type="submit" class="btn btn-primary auth-submit">Sign in</button>
    </form>

    <p class="auth-help">Forgotten your PIN? Contact your administrator.</p>
  </div>

  <p class="auth-foot">
    Operational onboarding data only. No patient information is held in this portal.
  </p>
</div>
