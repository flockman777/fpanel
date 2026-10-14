<?php /* Sidebar kanan panel user (navy #0B1B34): info akun, statistik, versi PHP, info server (dummy) */ ?>
<aside class="space-y-4 md:sticky md:top-20 md:self-start">
  <section class="overflow-hidden rounded-xl bg-navy text-slate-300 shadow-card">
    <h2 class="flex items-center gap-2 border-b border-navy-line px-4 py-3 text-[15px] font-semibold text-white">
      <i data-lucide="circle-user" class="h-4 w-4 text-brand-400"></i>
      <span data-i18n="side.accountInfo">Informasi Akun</span>
    </h2>
    <dl class="divide-y divide-navy-line text-sm">
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-300" data-i18n="side.username">Username</dt>
        <dd class="font-semibold text-white">demo</dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="shrink-0 text-slate-300" data-i18n="side.primaryDomain">Domain Utama</dt>
        <dd class="truncate font-semibold text-white">tokoku.com</dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-300" data-i18n="side.ip">IP Server</dt>
        <dd class="font-mono text-[13px] text-brand-300">185.205.222.170</dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-300" data-i18n="side.phpVersion">Versi PHP</dt>
        <dd class="font-semibold text-white">8.3.6</dd>
      </div>
    </dl>
  </section>

  <section class="overflow-hidden rounded-xl bg-navy text-slate-300 shadow-card">
    <h2 class="flex items-center gap-2 border-b border-navy-line px-4 py-3 text-[15px] font-semibold text-white">
      <i data-lucide="chart-pie" class="h-4 w-4 text-brand-400"></i>
      <span data-i18n="side.statistics">Statistik</span>
    </h2>
    <div class="space-y-4 px-4 py-4">
      <?php
      $stats = [
        ['key' => 'side.disk',          'used' => 68, 'text' => '6.8 GB dari 10 GB'],
        ['key' => 'side.bandwidth',     'used' => 24, 'text' => '24 GB dari 100 GB'],
        ['key' => 'side.domainsCount',  'used' => 20, 'text' => '2 dari 10'],
        ['key' => 'side.databasesCount','used' => 33, 'text' => '2 dari 6'],
        ['key' => 'side.emailsCount',   'used' => 40, 'text' => '4 dari 10'],
      ];
      foreach ($stats as $s): ?>
        <div>
          <div class="mb-1 flex items-baseline justify-between gap-2">
            <span class="text-[13px] font-medium text-slate-300" data-i18n="<?= $s['key'] ?>">-</span>
            <span class="text-xs text-slate-400"><?= $s['text'] ?></span>
          </div>
          <div class="h-2 w-full overflow-hidden rounded-full bg-white/10">
            <span class="<?= $s['used'] >= 80 ? 'bar-high' : ($s['used'] >= 60 ? 'bar-warn' : 'bg-brand-400') ?> block h-full rounded-full transition-all duration-500"
                  style="width: <?= $s['used'] ?>%"></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="overflow-hidden rounded-xl bg-navy text-slate-300 shadow-card">
    <h2 class="flex items-center gap-2 border-b border-navy-line px-4 py-3 text-[15px] font-semibold text-white">
      <i data-lucide="server" class="h-4 w-4 text-brand-400"></i>
      <span data-i18n="side.serverInfo">Info Server</span>
    </h2>
    <dl class="divide-y divide-navy-line text-sm">
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-300" data-i18n="side.hostname">Hostname</dt>
        <dd class="font-mono text-[13px] text-slate-200">fpanel-759</dd>
      </div>
      <div class="flex items-center justify-between gap-3 px-4 py-2.5">
        <dt class="text-slate-300" data-i18n="side.os">Sistem Operasi</dt>
        <dd class="text-slate-200">Ubuntu 24.04 LTS</dd>
      </div>
    </dl>
  </section>
</aside>
