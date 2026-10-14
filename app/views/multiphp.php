<?php
/**
 * MultiPHP Manager — cPanel "MultiPHP Manager": versi PHP per domain.
 *
 * Terpisah dari Select PHP Version (/php-extensions). Di sini yang diatur
 * adalah versi yang dipakai tiap domain, bukan extension/php.ini akun.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $rows     domain milik user + versi saat ini
 * @var array $versions versi PHP yang terpasang
 * @var string|null $error, string|null $ok
 */
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$rows     = (array)($rows ?? []);
$versions = (array)($versions ?? []);
$PHP_HOST = (string)(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);

// Domain static tidak pernah menjalankan PHP, jadi tidak punya versi.
$phpOnly = array_values(array_filter($rows, static fn (array $r): bool => (string)$r['type'] !== 'static'));
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-5xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="layers" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">MultiPHP Manager</h1>
        <p class="mt-0.5 text-sm text-slate-500">
          Pilih versi PHP untuk tiap domain. Extension dan php.ini diatur di
          <a href="/php-extensions" class="font-medium text-brand-600 hover:underline dark:text-brand-300">Select PHP Version</a>.
        </p>
      </div>
    </div>

    <?php foreach ([['error', 'rose', 'alert-circle'], ['ok', 'emerald', 'check-circle-2']] as [$k, $tone, $icon]): ?>
      <?php if ((${$k} ?? '') !== ''): ?>
        <div class="flex items-start gap-2 rounded-xl border border-<?= $tone ?>-200 bg-<?= $tone ?>-50 px-4 py-3 text-sm text-<?= $tone ?>-800 dark:border-<?= $tone ?>-500/30 dark:bg-<?= $tone ?>-500/10 dark:text-<?= $tone ?>-200">
          <i data-lucide="<?= $icon ?>" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= $e((string)${$k}) ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <section class="card overflow-hidden">
      <form method="post" action="/multiphp/apply">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Select a PHP Version</h2>
          <span class="text-xs text-slate-500">centang domain, pilih versi, lalu Apply</span>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800">
                <th class="w-10 px-4 py-2.5"><span class="sr-only">Pilih</span></th>
                <th class="px-4 py-2.5 font-semibold">Domain</th>
                <th class="px-4 py-2.5 font-semibold">Tipe</th>
                <th class="px-4 py-2.5 font-semibold">Versi PHP</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
              <?php foreach ($rows as $r):
                $id    = (int)$r['id'];
                $name  = (string)$r['name'];
                $type  = (string)$r['type'];
                $cur   = trim((string)($r['php_version'] ?? ''));
                $isPhp = $type !== 'static';
              ?>
              <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                <td class="px-4 py-2">
                  <input type="checkbox" name="dom[<?= $id ?>]" value="1"
                         class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-2 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800"
                         aria-label="Pilih <?= $e($name) ?>">
                </td>
                <td class="px-4 py-2">
                  <span class="font-medium text-slate-900 dark:text-slate-100"><?= $e($name) ?></span>
                  <?php if ($isPhp && $cur === $PHP_HOST): ?>
                    <span class="ml-1 rounded bg-amber-100 px-1 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">dipakai panel</span>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-2">
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300"><?= $e($type) ?></span>
                </td>
                <td class="px-4 py-2">
                  <?php if ($isPhp): ?>
                    <select name="ver[<?= $id ?>]" class="input h-8 w-32 text-xs">
                      <?php foreach ($versions as $v): ?>
                        <option value="<?= $e($v) ?>" <?= ($cur !== '' && $cur === $v) ? 'selected' : '' ?>>PHP <?= $e($v) ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php else: ?>
                    <span class="text-xs text-slate-400">tidak menjalankan PHP</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if ($rows === []): ?>
                <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">Belum ada domain.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 px-4 py-3 dark:border-slate-800">
          <span class="text-xs text-slate-500"><strong id="n-terpilih">0</strong> domain dipilih</span>
          <button class="btn-primary h-9 px-4 text-sm"><i data-lucide="check" class="h-4 w-4"></i><span>Apply</span></button>
          <span class="text-xs text-slate-500">Vhost dibangun ulang otomatis.</span>
        </div>
      </form>
    </section>

    <?php if ($phpOnly === []): ?>
      <p class="text-xs text-slate-500">
        Belum ada domain yang menjalankan PHP. Buat domain tipe <strong>PHP</strong> dulu di halaman Domains.
      </p>
    <?php endif; ?>
  </div>
</main>

<script>
  // Hitung jumlah domain yang dicentang.
  (function () {
    var boxes = document.querySelectorAll('input[type=checkbox][name^="dom["]');
    var out = document.getElementById('n-terpilih');
    if (!boxes.length || !out) return;
    function hitung() {
      var n = 0;
      boxes.forEach(function (b) { if (b.checked) { n++; } });
      out.textContent = String(n);
    }
    boxes.forEach(function (b) { b.addEventListener('change', hitung); });
    hitung();
  })();
</script>
</body>
</html>