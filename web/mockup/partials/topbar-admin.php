<header class="sticky top-0 z-30 flex items-center gap-3 border-b border-slate-200 bg-white/95 px-4 py-2.5 backdrop-blur dark:border-navy-line dark:bg-navy sm:px-6">
  <button class="btn-icon lg:hidden" @click="$store.admin.open = !$store.admin.open" aria-label="Toggle menu">
    <i data-lucide="menu" class="h-5 w-5"></i>
  </button>
  <h1 img src="assets/img/logo.svg" alt="fpanel" width="108" height="28" class="h-7 w-auto [filter:invert(1)_hue-rotate(180deg)] dark:hidden">
  <img src="assets/img/logo.svg" alt="" aria-hidden="true" width="108" height="28" class="hidden h-7 w-auto [filter:invert(1)_hue-rotate(180deg)] dark:block">
  <h1 class="text-sm font-semibold text-slate-700 dark:text-slate-200" data-i18n="admin.title">Server Dashboard</h1>
  <span class="badge-ok hidden sm:inline-flex"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>All systems normal</span>
  <div class="ml-auto flex items-center gap-2">
    <span class="hidden font-mono text-xs text-slate-500 sm:inline">fpanel-759 · 185.205.222.170</span>
    <button class="btn-ghost !py-1.5 text-xs"><i data-lucide="rotate-cw" class="h-3.5 w-3.5"></i><span data-i18n="admin.refresh">Refresh</span></button>
    <span class="grid h-7 w-7 place-items-center rounded-full bg-brand-600 text-[11px] font-semibold text-white">AD</span>
  </div>
</header>
