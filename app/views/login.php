<?php
/** @var string $csrf, ?string $error, ?string $notice, ?string $username */
$__v = trim((string)@file_get_contents(FPANEL_ROOT . '/app/public/assets/VERSION') ?: '') ?: '1';
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Masuk &middot; FPanel</title>
<link rel="icon" href="/assets/img/favicon.svg?v=<?= $__v ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="/assets/img/favicon-192.png?v=<?= $__v ?>" sizes="192x192">
<link rel="shortcut icon" href="/assets/img/favicon.ico?v=<?= $__v ?>">
<link rel="stylesheet" href="/assets/css/app.css?v=<?= $__v ?>">
<script>
(function(){var m=localStorage.getItem('fpanel.theme')||'light';
if(m==='dark'){document.documentElement.classList.add('dark');}
document.documentElement.style.colorScheme=m;})();
</script>
<script defer src="/assets/js/icons.js?v=<?= $__v ?>"></script>
<script defer src="/assets/js/panel.js?v=<?= $__v ?>"></script>
</head>

<body class="min-h-screen bg-navy">
  <main class="mx-auto grid min-h-screen w-full max-w-5xl grid-cols-1 items-center gap-10 px-6 py-12 lg:grid-cols-2">

    <!-- kolom kiri: logo saja -->
    <div class="flex items-center justify-center">
      <img src="/assets/img/logo.svg" alt="FPanel" width="248" height="64"
           class="logo-on-navy h-12 w-auto">
    </div>

    <!-- kolom kanan: form -->
    <div class="flex w-full justify-center">
      <div class="w-full max-w-sm">
        <div class="card p-6">
          <?php if (!empty($error)): ?>
          <p class="mb-4 flex items-start gap-2 rounded-lg bg-rose-50 px-3 py-2.5 text-sm text-rose-700
                    dark:bg-rose-500/10 dark:text-rose-300">
            <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= htmlspecialchars($error) ?></span>
          </p>
          <?php elseif (!empty($notice)): ?>
          <p class="mb-4 flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-700
                    dark:bg-emerald-500/10 dark:text-emerald-300">
            <i data-lucide="circle-check-big" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= htmlspecialchars($notice) ?></span>
          </p>
          <?php endif; ?>

          <form method="post" action="/login" class="space-y-3.5">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Username</span>
              <input type="text" name="username" required autofocus autocomplete="username"
                     value="<?= htmlspecialchars((string)($username ?? '')) ?>" class="input">
            </label>
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Password</span>
              <span class="relative block">
                <input data-pw type="password" name="password" required autocomplete="current-password" class="input pr-9">
                <button type="button" data-pw-toggle
                        class="absolute right-1 top-1/2 -translate-y-1/2 rounded p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
                        aria-label="Tampilkan password">
                  <i data-lucide="eye" class="h-4 w-4 dark:hidden"></i>
                  <i data-lucide="eye-off" class="hidden h-4 w-4 dark:block"></i>
                </button>
              </span>
            </label>
            <label class="flex cursor-pointer items-start gap-2 pt-0.5 text-xs text-slate-500">
              <input type="checkbox" name="remember" value="1"
                     class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
              <span>Ingat perangkat ini selama 90 hari.
                <span class="block text-[11px] text-slate-400">
                  Cookie terpisah dari sesi login; bisa dicabut kapan saja dari halaman Security.
                </span>
              </span>
            </label>
            <button type="submit" class="btn-primary w-full">
              <i data-lucide="log-in" class="h-4 w-4"></i>Masuk
            </button>
          </form>
        </div>

        <p class="mt-5 text-center text-[11px] leading-relaxed text-slate-400">
          Dilindungi session database, CSRF, dan rate limit.
        </p>
      </div>
    </div>
  </main>
</body>
</html>
