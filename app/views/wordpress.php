<?php
/**
 * WordPress Manager — memasang dan mengelola instalasi WordPress.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $sites   instalasi milik user
 * @var array $versions versi WP yang bisa dipilih
 * @var array $domains  domain PHP milik user
 * @var string $latest   versi terbaru
 * @var array|null $job  status job instalasi terakhir
 * @var bool $running    masih ada instalasi berjalan
 * @var string|null $error, string|null $ok
 */
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$sites    = (array)($sites ?? []);
$versions = (array)($versions ?? []);
$domains  = (array)($domains ?? []);
$latest   = (string)($latest ?? ($versions[0] ?? ''));
$job      = (array)($job ?? []);
$running  = (bool)($running ?? false);

// Hitung berapa instalasi yang punya core lebih lama dari versi terbaru, supaya
// badge di tabcomputed sekali di server, bukan di loop.
$outdated = 0;
foreach ($sites as $w) {
    if ($latest !== '' && version_compare((string)$w['core_version'], $latest, '<')) {
        $outdated++;
    }
}
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-6xl space-y-4">

    <!-- ===== kepala halaman =====
         Tombol "Pasang WordPress" selalu terlihat di kanan atas. Versi
         sebelumnya memakai tab, dan panel installmentya disembunyikan sampai
         JS aktif -- kalau JS lambat atau gagal, user tidak pernah menemukan
         tombol install sama sekali. -->
    <div class="flex flex-wrap items-start gap-3">
      <div class="min-w-0 flex-1">
        <img src="/assets/img/wordpress-standard.png" alt="WordPress"
             width="176" height="60" class="h-11 w-auto">
        <p class="mt-1 text-sm text-slate-500">
          Pasang WordPress ke domain PHP. Database dan user dibuat otomatis, siap dalam 1-2 menit.
        </p>
      </div>
      <div class="flex shrink-0 items-center gap-2">
        <?php if ($running): ?>
          <span class="inline-flex items-center gap-1.5 rounded-lg bg-brand-50 px-2.5 py-1.5 text-xs font-medium text-brand-700 dark:bg-brand-500/10 dark:text-brand-300">
            <i data-lucide="loader" class="h-3.5 w-3.5 animate-spin"></i>Instalasi berjalan
          </span>
        <?php endif; ?>
        <a href="#pasang" id="wp-install-link"
           class="btn-primary h-9 px-3 text-sm <?= $domains === [] ? 'pointer-events-none opacity-50' : '' ?>">
          <i data-lucide="download" class="h-4 w-4"></i><span>Pasang WordPress</span>
        </a>
      </div>
    </div>

    <?php foreach ([['error', 'rose', 'alert-circle'], ['ok', 'emerald', 'check-circle-2']] as [$k, $tone, $icon]): ?>
      <?php if ((${$k} ?? '') !== ''): ?>
        <div class="flex items-start gap-2 rounded-xl border border-<?= $tone ?>-200 bg-<?= $tone ?>-50 px-4 py-3 text-sm text-<?= $tone ?>-800 dark:border-<?= $tone ?>-500/30 dark:bg-<?= $tone ?>-500/10 dark:text-<?= $tone ?>-200">
          <i data-lucide="<?= $icon ?>" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= $e((string)${$k}) ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <!-- ===== progres instalasi ===== -->
    <?php if ($job !== []): ?>
      <?php
        $state = (string)($job['state'] ?? '');
        $done  = $state === 'done';
        $fail  = $state === 'error';
      ?>
      <section class="card p-4" id="wp-job" <?= $running ? 'data-refresh="6"' : '' ?>>
        <div class="flex items-start gap-3">
          <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg <?= $fail
              ? 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300'
              : ($done ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300'
                       : 'bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300') ?>">
            <i data-lucide="<?= $fail ? 'alert-circle' : ($done ? 'check' : 'loader') ?>"
               class="h-4 w-4 <?= $running ? 'animate-spin' : '' ?>"></i>
          </span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-slate-900 dark:text-slate-100">
              <?= $running ? 'Memasang WordPress' : ($done ? 'Instalasi selesai' : 'Instalasi gagal') ?>
              <span class="font-normal text-slate-500">di <?= $e((string)($job['domain'] ?? '')) ?></span>
            </p>
            <p class="mt-0.5 text-xs text-slate-500">
              <?= $e((string)($job['step'] ?? '')) ?>
              <?php if (!empty($job['started'])): ?>
                &middot; mulai <?= $e((string)$job['started']) ?> UTC
              <?php endif; ?>
              <?php if (!empty($job['finished'])): ?>
                &middot; selesai <?= $e((string)$job['finished']) ?> UTC
              <?php endif; ?>
            </p>
          </div>
          <?php if (!$running): ?>
            <a href="/wordpress" class="btn-icon" title="Tutup"><i data-lucide="x" class="h-4 w-4"></i></a>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- ===== daftar instalasi ===== -->
    <section class="space-y-3">
      <?php if ($sites === []): ?>
        <div class="card p-8 text-center">
          <span class="mx-auto grid h-12 w-12 place-items-center overflow-hidden rounded-xl ring-1 ring-slate-900/5 dark:ring-white/10">
            <img src="/assets/img/wordpress-wmark.png" alt="" width="48" height="48" class="h-12 w-12 opacity-70">
          </span>
          <p class="mt-3 text-sm font-medium text-slate-800 dark:text-slate-100">Belum ada WordPress</p>
          <p class="mx-auto mt-1 max-w-md text-xs text-slate-500">
            Buat domain bertipe <strong>PHP</strong> dulu, lalu pasang WordPress di sini. Database
            dan user database dibuat otomatis.
          </p>
          <div class="mt-4 flex justify-center gap-2">
            <a href="/domains" class="btn-secondary h-9 px-3 text-sm">
              <i data-lucide="globe" class="h-4 w-4"></i><span>Buat domain</span>
            </a>
            <a href="#pasang" class="btn-primary h-9 px-3 text-sm">
              <i data-lucide="download" class="h-4 w-4"></i><span>Pasang WordPress</span>
            </a>
          </div>
        </div>
      <?php else: ?>
        <?php if (count($sites) > 1): ?>
          <div class="relative">
            <i data-lucide="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400"></i>
            <input type="search" id="wp-filter" placeholder="Cari instalasi berdasarkan domain..."
                   autocomplete="off" class="input h-9 w-full pl-9 text-sm">
          </div>
        <?php endif; ?>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3" id="wp-list">
          <?php foreach ($sites as $w):
            $dname = (string)$w['domain_name'];
            $cur   = (string)$w['core_version'];
            $newer = $latest !== '' && version_compare($cur, $latest, '<');
            $stage = (int)$w['is_staging'] === 1;
          ?>
          <article class="card flex flex-col p-4" data-search="<?= $e(strtolower($dname)) ?>">
            <div class="flex items-start gap-2.5">
              <span class="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-lg ring-1 ring-slate-900/5 dark:ring-white/10">
                <img src="/assets/img/wordpress-wmark.png" alt="" width="36" height="36" class="h-9 w-9">
              </span>
              <div class="min-w-0 flex-1">
                <a href="https://<?= $e($dname) ?>/" target="_blank" rel="noopener"
                   class="block truncate text-sm font-semibold text-slate-900 hover:underline dark:text-slate-100">
                  <?= $e($dname) ?>
                </a>
                <p class="truncate text-[11px] text-slate-500">
                  <?= $e((string)$w['domain_type']) ?> &middot; dibuat <?= $e(substr((string)$w['created_at'], 0, 10)) ?>
                </p>
              </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-1.5">
              <span class="rounded px-1.5 py-0.5 font-mono text-[11px] font-medium <?= $newer
                  ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200'
                  : 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300' ?>">
                WP <?= $e($cur) ?>
              </span>
              <?php if ($newer): ?>
                <span class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                  ada <?= $e($latest) ?>
                </span>
              <?php endif; ?>
              <span class="rounded px-1.5 py-0.5 text-[11px] font-medium <?= $stage
                  ? 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300'
                  : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' ?>">
                <?= $stage ? 'staging' : 'produksi' ?>
              </span>
            </div>

            <div class="mt-3 flex items-center gap-1 border-t border-slate-100 pt-3 dark:border-slate-800">
              <a href="https://<?= $e($dname) ?>/" target="_blank" rel="noopener"
                 class="btn-icon" title="Buka situs"><i data-lucide="external-link" class="h-4 w-4"></i></a>
              <a href="/wordpress/<?= (int)$w['id'] ?>/manage"
                 class="btn-icon" title="Kelola instalasi ini"><i data-lucide="sliders-horizontal" class="h-4 w-4"></i></a>
              <a href="https://<?= $e($dname) ?>/wp-admin/" target="_blank" rel="noopener"
                 class="btn-icon" title="Buka wp-admin"><i data-lucide="layout-dashboard" class="h-4 w-4"></i></a>
              <span class="flex-1"></span>
              <form method="post" action="/wordpress/forget" class="inline"
                    onsubmit="return confirm('Hapus dari daftar? File dan database tidak akan dihapus.')">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                <button class="btn-icon text-rose-600 hover:text-rose-700 dark:text-rose-400"
                        title="Hapus dari daftar"><i data-lucide="trash-2" class="h-4 w-4"></i></button>
              </form>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <p class="hidden text-center text-sm text-slate-500" id="wp-empty">Tidak ada instalasi yang cocok.</p>
      <?php endif; ?>
    </section>

    <!-- ===== pasang baru =====
         Sengaja tidak memakai tab: panel ini terbuka selalu supaya tombol
         install di header tidak pernah mengarah ke tempat yang tersembunyi. -->
    <section id="pasang" class="card overflow-hidden scroll-mt-4">
      <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Pasang WordPress baru</h2>
        <p class="mt-0.5 text-xs text-slate-500">Database dan user database dibuat otomatis oleh panel.</p>
      </div>
      <?php if ($domains === []): ?>
        <div class="p-8 text-center">
          <span class="mx-auto grid h-11 w-11 place-items-center rounded-xl bg-slate-100 text-slate-400 dark:bg-slate-800">
            <i data-lucide="globe" class="h-5 w-5"></i>
          </span>
          <p class="mt-3 text-sm font-medium text-slate-800 dark:text-slate-100">Belum ada domain PHP</p>
          <p class="mx-auto mt-1 max-w-md text-xs text-slate-500">
            WordPress butuh domain bertipe <strong>PHP</strong>. Buat dulu di halaman Domains.
          </p>
          <a href="/domains" class="btn-primary mx-auto mt-4 inline-flex h-9 items-center gap-2 px-3 text-sm">
            <i data-lucide="plus" class="h-4 w-4"></i><span>Buat domain</span>
          </a>
        </div>
      <?php else: ?>
        <form method="post" action="/wordpress/install" class="divide-y divide-slate-100 dark:divide-slate-800" id="wp-install">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

          <div class="p-4">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">1. Target</h2>
            <p class="mt-0.5 text-xs text-slate-500">Ke domain mana WordPress akan dipasang.</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Domain</span>
                <select name="domain" required class="input h-9 w-full text-sm">
                  <?php foreach ($domains as $d): ?>
                    <option value="<?= $e((string)$d['name']) ?>"><?= $e((string)$d['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Versi WordPress</span>
                <select name="version" class="input h-9 w-full text-sm">
                  <?php foreach ($versions as $v): ?>
                    <option value="<?= $e((string)$v) ?>" <?= (string)$v === $latest ? 'selected' : '' ?>>
                      <?= $e((string)$v) ?><?= (string)$v === $latest ? ' (terbaru)' : '' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
          </div>

          <div class="p-4">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">2. Akun admin</h2>
            <p class="mt-0.5 text-xs text-slate-500">Dipakai untuk masuk ke wp-admin.</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Judul situs</span>
                <input name="title" maxlength="120" placeholder="Contoh: Toko Saya" class="input h-9 w-full text-sm">
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Username admin</span>
                <input name="admin_user" required maxlength="60" placeholder="admin" autocomplete="off"
                       class="input h-9 w-full text-sm">
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Password admin</span>
                <input type="password" name="admin_password" required minlength="8" maxlength="72"
                       autocomplete="new-password" class="input h-9 w-full text-sm">
                <span class="mt-1 block text-[11px] text-slate-500">Minimal 8 karakter. Disimpan hanya di hash WordPress.</span>
              </label>
              <label class="block">
                <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Email admin</span>
                <input type="email" name="admin_email" required maxlength="120" class="input h-9 w-full text-sm">
              </label>
              <label class="flex items-center gap-2 sm:col-span-2">
                <input type="checkbox" name="is_staging" value="1"
                       class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-2 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                <span class="text-xs text-slate-600 dark:text-slate-400">
                  Tandai sebagai staging, bukan situs produksi
                </span>
              </label>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-3 p-4">
            <button class="btn-primary h-9 px-4 text-sm" <?= $running ? 'disabled' : '' ?>>
              <i data-lucide="download" class="h-4 w-4"></i><span>Pasang WordPress</span>
            </button>
            <span class="text-xs text-slate-500">Unduhan core sekitar 30 MB, bisa memakan waktu 1-2 menit.</span>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <p class="text-xs text-slate-500">
      Kredensial database disimpan di <code class="font-mono">wp-config.php</code> di docroot domain,
      sama seperti panel hosting lain. Hapus file itu dari backup publik.
    </p>
  </div>
</main>
<script>
  // Filter Installs: disembunyikan kartu yang tidak cocok, bukan reload halaman.
  (function () {
    var input = document.getElementById('wp-filter');
    var list = document.getElementById('wp-list');
    if (!input || !list) { return; }
    var empty = document.getElementById('wp-empty');
    var cards = Array.prototype.slice.call(list.querySelectorAll('[data-search]'));

    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      var shown = 0;
      cards.forEach(function (c) {
        var hit = q === '' || c.getAttribute('data-search').indexOf(q) >= 0;
        c.classList.toggle('hidden', !hit);
        if (hit) { shown++; }
      });
      if (empty) { empty.classList.toggle('hidden', shown > 0); }
    });
  })();

  // Installer jalan di luar request HTTP, jadi halaman disegarkan sendiri
  // selama masih ada job berjalan (setiap 6 detik).
  (function () {
    var el = document.getElementById('wp-job');
    if (!el) { return; }
    var wait = parseInt(el.getAttribute('data-refresh') || '0', 10);
    if (wait > 0) { setTimeout(function () { location.reload(); }, wait * 1000); }
  })();
</script>
</body>
</html>
