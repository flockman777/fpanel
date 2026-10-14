<?php
$area = 'user'; $title = 'Create Domain'; $bodyAttrs = 'x-data';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/topbar-user.php';
$crumbs = [['label' => 'Domains', 'href' => 'domains.php'], ['label' => 'Create Domain']];
?>
<main class="mx-auto max-w-5xl px-4 py-6 sm:px-6"
      x-data="{
        domain: 'tokoku.com',
        docroot: '',
        autoSsl: true,
        notify: true,
        get clean() { return this.domain.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '') },
        get valid() { return /^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/.test(this.clean) },
        get root() { return this.docroot.trim() || 'public_html' },
        get usedSlots() { return 2 }
      }">
  <?php require __DIR__ . '/partials/breadcrumb.php'; ?>

  <div class="grid gap-6 lg:grid-cols-[1fr_300px]">
    <div class="min-w-0">
      <h1 class="text-xl font-bold text-slate-900 dark:text-white" data-i18n="domainCreate.title">Create Domain</h1>
      <p class="mt-0.5 text-sm text-slate-500" data-i18n="domainCreate.desc">Domain harus sudah diarahkan (A record) ke IP server sebelum diaktifkan.</p>

      <form class="card card-body mt-4 space-y-5" onsubmit="return false">
        <div>
          <label class="label" for="domain"><span data-i18n="domainCreate.field.domain">Domain</span> <span class="text-rose-500">*</span></label>
          <div class="relative">
            <i data-lucide="globe" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
            <input id="domain" x-model="domain" class="input pl-9" placeholder="tokoku.com"
                   :class="domain.length && !valid ? '!border-rose-400 focus:!ring-rose-500/30' : ''">
          </div>
          <p class="help" data-i18n="domainCreate.hint.domain">Contoh: toko.com atau app.toko.com</p>
          <p x-cloak x-show="domain.length && !valid" class="mt-1 flex items-center gap-1 text-xs font-medium text-rose-600">
            <i data-lucide="circle-alert" class="h-3.5 w-3.5"></i><span>Format domain tidak valid.</span>
          </p>
        </div>

        <div>
          <label class="label" for="root"><span data-i18n="domainCreate.field.root">Document Root</span></label>
          <div class="relative">
            <i data-lucide="folder-open" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
            <input id="root" x-model="docroot" class="input pl-9" placeholder="public_html">
          </div>
          <p class="help" data-i18n="domainCreate.hint.root">Kosongkan untuk memakai Document Root default.</p>
        </div>

        <div class="space-y-3 rounded-lg border border-slate-200 p-4 dark:border-slate-800">
          <label class="flex items-start gap-3">
            <input type="checkbox" x-model="autoSsl" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <span>
              <span class="block text-sm font-medium text-slate-800 dark:text-slate-200" data-i18n="domainCreate.field.autoSsl">Aktifkan Auto SSL otomatis (Let's Encrypt)</span>
              <span class="block text-xs text-slate-500">Sertifikat-issue dan renew otomatis setelah A record aktif.</span>
            </span>
          </label>
          <label class="flex items-start gap-3">
            <input type="checkbox" x-model="notify" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
            <span class="text-sm font-medium text-slate-800 dark:text-slate-200" data-i18n="domainCreate.field.email">Kirim notifikasi ke email</span>
          </label>
        </div>

        <div class="flex items-center gap-2 border-t border-slate-100 pt-4 dark:border-slate-800">
          <button class="btn-primary" :disabled="!valid" :class="!valid && 'opacity-50'">
            <i data-lucide="check" class="h-4 w-4"></i><span data-i18n="domainCreate.submit">Buat Domain</span>
          </button>
          <a href="domains.php" class="btn-ghost" data-i18n="domainCreate.cancel">Batal</a>
        </div>
      </form>
    </div>

    <aside class="space-y-4">
      <div class="card">
        <h2 class="border-b border-slate-100 px-4 py-3 text-sm font-semibold dark:border-slate-800" data-i18n="domainCreate.preview">Pratinjau</h2>
        <div class="card-body space-y-3 text-sm">
          <div class="flex items-center justify-between gap-2">
            <span class="text-slate-500" data-i18n="side.primaryDomain">Domain Utama</span>
            <span class="truncate font-medium text-slate-800 dark:text-slate-200" x-text="clean || '—'">tokoku.com</span>
          </div>
          <div class="flex items-center justify-between gap-2">
            <span class="text-slate-500" data-i18n="domainCreate.field.root">Document Root</span>
            <span class="font-mono text-xs text-slate-700 dark:text-slate-300" x-text="root">public_html</span>
          </div>
          <div class="flex items-center justify-between gap-2">
            <span class="text-slate-500">URL</span>
            <span class="truncate font-mono text-xs" :class="autoSsl ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-600 dark:text-slate-300'"
                  x-text="clean ? (autoSsl ? 'https://' : 'http://') + clean : '—'">https://tokoku.com</span>
          </div>
          <div class="flex items-center justify-between gap-2">
            <span class="text-slate-500" data-i18n="domains.autoSsl">Auto SSL</span>
            <span x-show="autoSsl" class="badge-ok"><i data-lucide="lock" class="h-3 w-3"></i><span data-i18n="domainCreate.secure">Akan aktif SSL</span></span>
            <span x-show="!autoSsl" x-cloak class="badge-warn"><i data-lucide="lock-open" class="h-3 w-3"></i><span data-i18n="domainCreate.httpOnly">HTTP saja dulu</span></span>
          </div>
        </div>
      </div>

      <div class="card card-body">
        <div class="mb-1 flex items-baseline justify-between">
          <span class="text-xs font-medium text-slate-600 dark:text-slate-300" data-i18n="side.domainsCount">Domain</span>
          <span class="text-xs text-slate-500"><span x-text="usedSlots"></span> / 10</span>
        </div>
        <div class="progress"><span class="bar-info" :style="'width:' + (usedSlots / 10 * 100) + '%'"></span></div>
        <p class="mt-3 text-xs text-slate-500">Kuota domain tersisa <span class="font-semibold text-slate-700 dark:text-slate-300" x-text="10 - usedSlots">8</span> slot.</p>
      </div>
    </aside>
  </div>
</main>
<?php require __DIR__ . '/partials/foot.php'; ?>
