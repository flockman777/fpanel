<?php
/**
 * Result of exchanging a one-time login link.
 *
 * This page is deliberately rendered directly (not redirected) so the URL
 * carrying the token is never stored in the history: navigating away means
 * the token moves to the address bar.
 *
 * @var array $me
 * @var string $csrf
 */
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Signed In &middot; FPanel</title>
  <link rel="icon" href="/assets/img/favicon.svg">
  <link rel="stylesheet" href="/assets/app.css">
  <script>
    // Replace the URL right away so the token disappears from the address bar and
    // the history before this page finishes loading.
    if (location.replace) { location.replace('/dashboard'); }
  </script>
</head>
<body class="h-full bg-slate-100 dark:bg-slate-950">
  <main class="flex min-h-full items-center justify-center px-4">
    <div class="card w-full max-w-sm p-5 text-center">
      <span class="mx-auto grid h-10 w-10 place-items-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300">
        <i data-lucide="check" class="h-5 w-5"></i>
      </span>
      <h1 class="mt-3 text-lg font-semibold text-slate-900 dark:text-slate-50">Signed in</h1>
      <p class="mt-1 text-sm text-slate-500">Hello <?= $e((string)$me['username']) ?>, redirecting to the dashboard&hellip;</p>
      <noscript>
        <a href="/dashboard" class="btn-primary mt-4 inline-flex justify-center">Open dashboard</a>
      </noscript>
      <form method="post" action="/logout" class="mt-3">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <button class="btn-secondary h-9 w-full text-sm">Sign out</button>
      </form>
    </div>
  </main>
  <script src="/assets/lucide.min.js"></script>
</body>
</html>
