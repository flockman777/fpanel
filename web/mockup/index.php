<?php
$area  = 'user';
$title = 'Login';
$bodyAttrs = 'x-data';
require __DIR__ . '/partials/head.php';
?>
<main class="grid min-h-full place-items-center bg-canvas px-4 py-10 dark:bg-slate-950">
  <div class="w-full max-w-sm">
    <div class="mb-6 flex flex-col items-center gap-2 text-center">
      <img src="assets/img/logo.svg" alt="fpanel" width="248" height="64" class="h-12 w-auto dark:[filter:invert(1)_hue-rotate(180deg)]">
      <p class="-mt-1 text-sm text-muted" data-i18n="app.tagline">Panel Hosting</p>
    </div>

    <div class="card card-body">
      <h1 class="text-lg font-semibold text-slate-900 dark:text-white" data-i18n="login.title">Sign in to fpanel</h1>
      <p class="mt-0.5 text-sm text-slate-500" data-i18n="login.subtitle">Panel kontrol hosting Anda.</p>

      <form class="mt-5 space-y-4" onsubmit="return false">
        <div>
          <label class="label" for="u" data-i18n="login.username">Username</label>
          <div class="relative">
            <i data-lucide="user" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
            <input id="u" class="input pl-9" value="demo" autocomplete="username">
          </div>
        </div>
        <div>
          <label class="label" for="p" data-i18n="login.password">Password</label>
          <div class="relative" x-data="{ show: false }">
            <i data-lucide="lock" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
            <input id="p" :type="show ? 'text' : 'password'" class="input pl-9 pr-10" value="demo1234" autocomplete="current-password">
            <button type="button" @click="show = !show" class="absolute right-1 top-1/2 -translate-y-1/2 rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 dark:hover:bg-slate-800"
                    :aria-label="$store.i18n.t('login.password')">
              <i data-lucide="eye" class="h-4 w-4" x-show="!show"></i>
              <i data-lucide="eye-off" class="h-4 w-4" x-show="show" x-cloak></i>
            </button>
          </div>
        </div>

        <div class="flex items-center justify-between">
          <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
            <input type="checkbox" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" checked>
            <span data-i18n="login.remember">Remember me</span>
          </label>
          <a href="#" class="text-sm font-medium text-brand-600 hover:underline dark:text-brand-400" data-i18n="login.forgot">Forgot password?</a>
        </div>

        <a href="home.php" class="btn-primary w-full" data-i18n="login.submit">Sign in</a>
      </form>

      <p class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-200"
         data-i18n-html="login.hintDemo">Demo account.</p>

      <div class="mt-4 flex items-center justify-between border-t border-slate-100 pt-4 dark:border-slate-800">
        <div class="flex gap-1 text-sm">
          <button @click="$store.i18n.set('id')" :class="$store.i18n.lang === 'id' ? 'font-semibold text-brand-600' : 'text-slate-500 hover:text-slate-800'"
                  class="hover:underline">ID</button>
          <span class="text-slate-300">|</span>
          <button @click="$store.i18n.set('en')" :class="$store.i18n.lang === 'en' ? 'font-semibold text-brand-600' : 'text-slate-500 hover:text-slate-800'"
                  class="hover:underline">EN</button>
        </div>
        <button class="btn-icon" @click="$store.theme.toggle()" :title="$store.i18n.t('common.theme')">
          <i data-lucide="sun" class="h-5 w-5 dark:hidden"></i>
          <i data-lucide="moon" class="hidden h-5 w-5 dark:block"></i>
        </button>
      </div>
    </div>

    <p class="mt-6 text-center text-xs text-slate-400">
      <a href="admin-dashboard.php" class="hover:underline" data-i18n="admin.dashboard">Dashboard</a>
    </p>
  </div>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
