<?php
/**
 * The login link was already used, expired, or is unknown.
 *
 * Rendered as a 410 so this page is not cached and not
 * retried automatically by the browser.
 */
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Link Not Valid &middot; FPanel</title>
  <link rel="icon" href="/assets/img/favicon.svg">
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="h-full bg-slate-100 dark:bg-slate-950">
  <main class="flex min-h-full items-center justify-center px-4">
    <div class="card w-full max-w-sm p-5 text-center">
      <span class="mx-auto grid h-10 w-10 place-items-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-500/10 dark:text-amber-300">
        <i data-lucide="link-2-off" class="h-5 w-5"></i>
      </span>
      <h1 class="mt-3 text-lg font-semibold text-slate-900 dark:text-slate-50">Link not valid</h1>
      <p class="mt-1 text-sm text-slate-500">
        This link has already been used, has expired, or is unknown. Each link works only once.
      </p>
      <a href="/login" class="btn-primary mt-4 inline-flex h-9 w-full justify-center text-sm">
        <i data-lucide="log-in" class="h-4 w-4"></i><span>Sign in with a password</span>
      </a>
    </div>
  </main>
  <script src="/assets/lucide.min.js"></script>
</body>
</html>
