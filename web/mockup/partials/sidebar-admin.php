<?php /* Sidebar kiri ala WHM (navy #0B1B34): menu bertingkat + kotak pencarian (dummy) */
$q = $q ?? '';
$adminMenu = [
  ['key' => 'admin.accountFunctions', 'icon' => 'users',      'items' => ['Create Account','List Accounts','Suspend Account','Change Package']],
  ['key' => 'admin.packages',         'icon' => 'package',    'items' => ['Add Package','List Packages','Edit Package']],
  ['key' => 'admin.dns',              'icon' => 'globe',      'items' => ['Nameservers','Zone Manager','Add Record']],
  ['key' => 'admin.services',         'icon' => 'settings-2', 'items' => ['Apache Config','PHP Config','Firewall Config','Service Manager']],
  ['key' => 'admin.securityCenter',   'icon' => 'shield',     'items' => ['IP Blocker','Firewall','Contact Manager']],
  ['key' => 'admin.serverStatus',     'icon' => 'activity',   'items' => ['Load','Network','PHP-FPM','Disk Usage']],
  ['key' => 'admin.logs',             'icon' => 'scroll-text','items' => ['Login History','Error Logs','System Logs']],
];
?>
<aside class="flex h-full flex-col bg-navy text-slate-300">
  <div class="flex items-center gap-2.5 px-4 py-3.5">
    <img src="assets/img/logo.svg" alt="fpanel" width="124" height="32" class="h-8 w-auto [filter:invert(1)_hue-rotate(180deg)]">
    <span class="rounded bg-brand-500/20 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-300">admin</span>
  </div>

  <div class="px-3 pb-2.5">
    <div class="relative">
      <i data-lucide="search" class="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500"></i>
      <input x-model="q" type="search" placeholder="Search" aria-label="Search menu"
             class="w-full rounded-lg border border-navy-line bg-white/5 py-1.5 pl-8 pr-3 text-sm text-slate-100 placeholder:text-slate-500 focus:border-brand-500 focus:ring-1 focus:ring-brand-500/40">
    </div>
  </div>

  <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 pb-4" aria-label="Admin menu">
    <a href="admin-dashboard.php"
       class="flex items-center gap-2.5 rounded-lg bg-brand-400/15 px-2.5 py-2 text-sm font-semibold text-brand-400 ring-1 ring-inset ring-brand-400/30">
      <i data-lucide="layout-dashboard" class="h-4 w-4"></i>
      <span data-i18n="admin.dashboard">Dashboard</span>
    </a>
    <?php foreach ($adminMenu as $m):
      $hidden = trim((string) $q) !== '' && stripos($m['key'] . ' ' . implode(' ', $m['items']), (string) $q) === false; ?>
      <div x-show="!<?= $hidden ? 'true' : 'false' ?>">
        <details class="group">
          <summary class="flex cursor-pointer list-none items-center gap-2.5 rounded-lg px-2.5 py-2 text-sm text-slate-300 hover:bg-white/5 hover:text-white">
            <i data-lucide="<?= htmlspecialchars($m['icon']) ?>" class="h-4 w-4 text-slate-500 group-hover:text-brand-400"></i>
            <span data-i18n="<?= htmlspecialchars($m['key']) ?>"><?= htmlspecialchars($m['key']) ?></span>
            <i data-lucide="chevron-right" class="ml-auto h-4 w-4 text-slate-500 transition group-open:rotate-90"></i>
          </summary>
          <ul class="ml-6 mt-0.5 space-y-0.5 border-l border-navy-line pl-3">
            <?php foreach ($m['items'] as $it): ?>
              <li><a href="#" class="block rounded-md px-2.5 py-1.5 text-[13px] text-slate-400 hover:bg-white/5 hover:text-brand-300"><?= htmlspecialchars($it) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </details>
      </div>
    <?php endforeach; ?>
  </nav>

  <div class="flex items-center gap-1.5 border-t border-navy-line px-3 py-3">
    <a href="home.php" class="btn-ghost flex-1 !border-navy-line !bg-white/5 !py-1.5 !text-xs !text-slate-300 hover:!bg-white/10">
      <i data-lucide="arrow-left" class="h-3.5 w-3.5"></i>Panel User
    </a>
    <button class="btn-icon !text-slate-400 hover:!bg-white/10 hover:!text-white" @click="$store.theme.toggle()" :title="$store.i18n.t('common.theme')">
      <i data-lucide="sun" class="h-5 w-5 dark:hidden"></i><i data-lucide="moon" class="hidden h-5 w-5 dark:block"></i>
    </button>
    <div class="flex gap-0.5 text-xs font-semibold">
      <button @click="$store.i18n.set('id')" :class="$store.i18n.lang === 'id' ? 'text-brand-400' : 'text-slate-500'" class="hover:underline">ID</button>
      <span class="text-slate-600">|</span>
      <button @click="$store.i18n.set('en')" :class="$store.i18n.lang === 'en' ? 'text-brand-400' : 'text-slate-500'" class="hover:underline">EN</button>
    </div>
  </div>
</aside>
