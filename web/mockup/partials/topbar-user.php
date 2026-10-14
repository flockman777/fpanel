<?php /* topbar panel user: search besar di tengah + menu user */ ?>
<header class="sticky top-0 z-30 border-b border-slate-200 bg-white/90 backdrop-blur dark:border-slate-800 dark:bg-slate-900/90">
  <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-2.5 sm:px-6">
    <a href="home.php" class="flex shrink-0 items-center" aria-label="fpanel">
      <img src="assets/img/logo.svg" alt="fpanel" width="132" height="34" class="h-8 w-auto dark:[filter:invert(1)_hue-rotate(180deg)]">
    </a>

    <form class="relative mx-auto w-full max-w-xl" role="search" onsubmit="return false">
      <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
      <input x-model="$store.search.q" type="search"
             :placeholder="$store.i18n.t('search.placeholder')"
             aria-label="Search features" autocomplete="off"
             class="input pl-9 pr-9"
             x-on:focus="$store.search.open = true"
             x-on:blur="setTimeout(() => $store.search.open = false, 150)">
      <kbd class="pointer-events-none absolute right-2.5 top-1/2 hidden -translate-y-1/2 rounded border border-slate-300 px-1.5 text-[10px] font-medium text-slate-400 dark:border-slate-700 sm:block">/</kbd>

      <div x-show="$store.search.open && $store.search.q.length" x-cloak
           x-transition.opacity.duration.120ms
           class="absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-pop dark:border-slate-800 dark:bg-slate-900">
        <ul class="max-h-80 overflow-y-auto py-1">
          <template x-for="f in $store.search.results()" :key="f.href">
            <li>
              <a :href="f.href" class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50 dark:hover:bg-slate-800">
                <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                  <i :data-lucide="f.icon" class="h-4 w-4"></i>
                </span>
                <span class="min-w-0">
                  <span class="block truncate text-sm font-medium" x-text="$store.i18n.t(f.label)"></span>
                  <span class="block truncate text-xs text-slate-500" x-text="$store.i18n.t(f.category)"></span>
                </span>
              </a>
            </li>
          </template>
          <li x-show="!$store.search.results().length" class="px-3 py-6 text-center text-sm text-slate-500" x-text="$store.i18n.t('search.empty')"></li>
        </ul>
      </div>
    </form>

    <div class="flex shrink-0 items-center gap-1">
      <div class="relative" x-data="{ open: false }">
        <button class="btn-icon" @click="open = !open" :aria-expanded="open" data-i18n-title="common.language" :title="$store.i18n.t('common.language')">
          <i data-lucide="languages" class="h-5 w-5"></i>
        </button>
        <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.right
             class="absolute right-0 z-40 mt-2 w-40 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-pop dark:border-slate-800 dark:bg-slate-900">
          <button class="flex w-full items-center justify-between px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800"
                  @click="$store.i18n.set('id'); open = false">
            <span>Bahasa Indonesia</span><i x-show="$store.i18n.lang === 'id'" data-lucide="check" class="h-4 w-4 text-brand-600"></i>
          </button>
          <button class="flex w-full items-center justify-between px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800"
                  @click="$store.i18n.set('en'); open = false">
            <span>English</span><i x-show="$store.i18n.lang === 'en'" data-lucide="check" class="h-4 w-4 text-brand-600"></i>
          </button>
        </div>
      </div>

      <button class="btn-icon" @click="$store.theme.toggle()" :title="$store.i18n.t('common.theme')">
        <i data-lucide="sun" class="h-5 w-5 dark:hidden"></i>
        <i data-lucide="moon" class="hidden h-5 w-5 dark:block"></i>
      </button>

      <div class="relative" x-data="{ open: false }">
        <button @click="open = !open" :aria-expanded="open"
                class="flex items-center gap-2 rounded-lg px-1.5 py-1.5 hover:bg-slate-100 dark:hover:bg-slate-800">
          <span class="grid h-7 w-7 place-items-center rounded-full bg-brand-100 text-xs font-semibold text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">DU</span>
          <span class="hidden text-sm font-medium text-slate-700 dark:text-slate-200 md:block">demo</span>
          <i data-lucide="chevron-down" class="hidden h-4 w-4 text-slate-400 md:block"></i>
        </button>
        <div x-show="open" x-cloak @click.outside="open = false" x-transition.origin.top.right
             class="absolute right-0 z-40 mt-2 w-56 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-pop dark:border-slate-800 dark:bg-slate-900">
          <div class="border-b border-slate-100 px-3 py-2.5 dark:border-slate-800">
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">demo</p>
            <p class="text-xs text-slate-500">demo@example.com</p>
          </div>
          <a href="#" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800" x-text="$store.i18n.t('common.profile')"><i data-lucide="user" class="h-4 w-4"></i></a>
          <a href="#" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-slate-50 dark:hover:bg-slate-800" x-text="$store.i18n.t('common.changePassword')"><i data-lucide="key-round" class="h-4 w-4"></i></a>
          <div class="my-1 border-t border-slate-100 dark:border-slate-800"></div>
          <a href="index.php" class="flex items-center gap-2 px-3 py-2 text-sm text-rose-600 hover:bg-rose-50 dark:hover:bg-slate-800" x-text="$store.i18n.t('common.logout')"><i data-lucide="log-out" class="h-4 w-4"></i></a>
        </div>
      </div>
    </div>
  </div>
</header>
