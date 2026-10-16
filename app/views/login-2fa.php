<?php
/**
 * Login step 2: code from the authenticator app or a recovery code.
 *
 * @var string $csrf
 * @var string $username
 * @var string|null $error
 */
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Two-Factor Verification &middot; FPanel</title>
  <link rel="icon" href="/assets/img/favicon.svg">
  <link rel="apple-touch-icon" href="/assets/img/favicon-180x180.png">
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="h-full bg-slate-100 dark:bg-slate-950">
  <main class="flex min-h-full items-center justify-center px-4 py-10">
    <div class="w-full max-w-sm">
      <div class="mb-6 text-center">
        <img src="/assets/img/favicon.svg" alt="" class="mx-auto h-10 w-10">
        <h1 class="mt-3 text-lg font-semibold text-slate-900 dark:text-slate-50">Two-Factor Verification</h1>
        <p class="mt-1 text-sm text-slate-500">
          Hello <span class="font-medium text-slate-700 dark:text-slate-300"><?= $e($username) ?></span>,
          enter the 6-digit code from your authenticator app.
        </p>
      </div>

      <div class="card p-5">
        <?php if (!empty($error)): ?>
          <div class="mb-4 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
            <i data-lucide="circle-alert" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= $e((string)$error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" action="/login/2fa" class="space-y-3.5">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Authenticator code</span>
            <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required
                   maxlength="20" placeholder="000000"
                   class="input h-10 w-full text-center font-mono text-lg tracking-[0.35em]">
          </label>
          <p class="text-xs text-slate-500">
            No phone? You can also use one of your
            <strong>recovery codes</strong> (format <code class="font-mono">ABC123-DEF456</code>).
          </p>
          <button class="btn-primary h-10 w-full justify-center"><i data-lucide="shield-check" class="h-4 w-4"></i><span>Verify</span></button>
        </form>
      </div>

      <p class="mt-4 text-center text-xs">
        <a href="/login" class="text-slate-500 hover:underline dark:text-slate-400">Back to the login page</a>
      </p>
    </div>
  </main>
  <script src="/assets/lucide.min.js"></script>
</body>
</html>
