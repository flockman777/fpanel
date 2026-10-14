<?php
/**
 * Select PHP Version — halaman ala cPanel/phpSelector.
 *
 * Sengaja TIDAK sama dengan MultiPHP Manager. Di cPanel:
 *   - Select PHP Version  -> versi AKUN + Extensions + PHP Settings
 *   - MultiPHP Manager    -> versi PHP per DOMAIN
 * Halaman ini memakai versi akun: extension dan php.ini berlaku untuk semua
 * domain milik user. Pengaturan versi per domain ada di /multiphp.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $data   hasil PhpExtensions::listing()
 * @var array $domains daftar domain milik user
 * @var int $nDomains jumlah domain user
 * @var array $iniDefault PhpSettings::defaults()
 * @var array $iniCurrent gabungan override semua domain
 * @var bool $iniCustom
 * @var string|null $error, string|null $ok
 */
use Fpanel\Service\PhpExtensions;

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$d       = (array)($data ?? []);
$vers    = (array)($d['versions'] ?? []);
$exts    = (array)($d['extensions'] ?? []);
$ver     = (string)($d['version'] ?? '');
$dOk     = !empty($d['ok']);
$err     = ($dOk ? '' : (string)($d['error'] ?? 'Gagal memuat daftar extension.'));
$domains = (array)($domains ?? []);
$nDom    = (int)($nDomains ?? count($domains));

$iniCur = (array)($iniCurrent ?? []);
$iniDef = (array)($iniDefault ?? []);

$nAktif = 0;
foreach ($exts as $x) {
    if (!empty($x['enabled'])) {
        $nAktif++;
    }
}

// Dipisah Basic/Advanced seperti tab di phpSelector.
$BASIC = ['memory_limit', 'max_execution_time', 'max_input_time', 'max_input_vars',
          'display_errors', 'upload_max_filesize', 'post_max_size'];
$ADVANCED = ['display_startup_errors', 'error_reporting', 'opcache.enable',
             'opcache.memory_consumption', 'opcache.max_accelerated_files',
             'opcache.validate_timestamps', 'opcache.revalidate_freq'];

$PHP_HOST = (string)(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-6xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="settings-2" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">Select PHP Version</h1>
        <p class="mt-0.5 text-sm text-slate-500">
          Versi, extension, dan PHP Settings untuk semua domain Anda.
          Versi per domain diatur di <a href="/multiphp" class="font-medium text-brand-600 hover:underline dark:text-brand-300">MultiPHP Manager</a>.
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

    <?php if ($ver !== '' && $ver === $PHP_HOST): ?>
      <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
        <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0"></i>
        <span><strong>PHP <?= $e($ver) ?> juga dipakai panel ini.</strong> Setiap Apply me-restart pool itu, jadi panel tidak tersedia selama sekitar sedetik.</span>
      </div>
    <?php endif; ?>

    <?php if (!$dOk): ?>
      <section class="card p-10 text-center">
        <i data-lucide="server-off" class="mx-auto h-8 w-8 text-slate-300"></i>
        <p class="mt-3 text-sm text-slate-500"><?= $e($err) ?></p>
      </section>
    <?php else: ?>

    <!-- ===== PHP Version (akun) ===== -->
    <section class="card p-4">
      <form method="post" action="/php-extensions/version" class="flex flex-wrap items-end gap-3">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">PHP Version</span>
          <select name="version" class="input h-9 w-44 text-sm">
            <?php foreach ($vers as $v): ?>
              <option value="<?= $e($v) ?>" <?= $v === $ver ? 'selected' : '' ?>>PHP <?= $e($v) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn-primary h-9 px-4 text-sm"><i data-lucide="check" class="h-4 w-4"></i><span>Select</span></button>
        <p class="text-xs text-slate-500">
          Berlaku untuk <strong><?= $e($nDom) ?></strong> domain Anda yang mengikuti versi akun.
        </p>
      </form>
    </section>

    <div class="grid gap-4 lg:grid-cols-2">

      <!-- ===== Extensions ===== -->
      <section class="card flex flex-col overflow-hidden">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Extensions</h2>
          <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300">
            <?= $e($nAktif) ?>/<?= $e(count($exts)) ?> aktif
          </span>
          <span class="ml-auto text-[11px] text-slate-400">PHP <?= $e($ver) ?></span>
        </div>

        <form method="post" action="/php-extensions/apply" id="form-ext" class="flex min-h-0 flex-1 flex-col">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="version" value="<?= $e($ver) ?>">
          <div class="max-h-96 flex-1 overflow-y-auto p-3">
            <div class="grid gap-x-4 gap-y-1 sm:grid-cols-2">
              <?php foreach ($exts as $x):
                $name = (string)$x['name'];
                $on   = (bool)$x['enabled'];
                $sens = (bool)$x['sensitive'];
              ?>
              <label class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 hover:bg-slate-50 dark:hover:bg-slate-800/60"
                     data-ext="<?= $e($name) ?>" data-sens="<?= $sens ? '1' : '0' ?>">
                <!-- Status saat ini selalu ikut terkirim, jadi centang yang
                    dilepas berarti "matikan" (bukan sekadar tidak terkirim). -->
                <input type="hidden" name="cur[<?= $e($name) ?>]" value="<?= $on ? '1' : '0' ?>">
                <input type="checkbox" name="on[<?= $e($name) ?>]" value="1" <?= $on ? 'checked' : '' ?>
                       class="h-4 w-4 shrink-0 rounded border-slate-300 text-brand-600 focus:ring-2 focus:ring-brand-500 dark:border-slate-600 dark:bg-slate-800">
                <span class="truncate font-mono text-[13px] text-slate-800 dark:text-slate-200"><?= $e($name) ?></span>
                <?php if ($sens): ?>
                  <span class="rounded bg-amber-100 px-1 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">sensitif</span>
                <?php endif; ?>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="flex items-center gap-2 border-t border-slate-100 px-4 py-3 dark:border-slate-800">
            <button class="btn-primary h-9 px-4 text-sm"><i data-lucide="check" class="h-4 w-4"></i><span>Apply</span></button>
            <span class="text-xs text-slate-500">Berlaku untuk semua domain yang memakai PHP <?= $e($ver) ?>.</span>
          </div>
        </form>
      </section>

      <!-- ===== PHP Settings ===== -->
      <section class="card flex flex-col overflow-hidden">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">PHP Settings</h2>
          <?php if ($iniCustom): ?>
            <span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-medium text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">diubah</span>
          <?php else: ?>
            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-slate-700 dark:text-slate-400">default</span>
          <?php endif; ?>
        </div>

        <!-- tab Basic / Advanced, sama seperti phpSelector -->
        <div class="flex gap-1 border-b border-slate-100 px-3 pt-2 dark:border-slate-800">
          <button type="button" data-tab="basic"
                  class="tab-btn rounded-t px-3 py-2 text-xs font-medium">Basic</button>
          <button type="button" data-tab="advanced"
                  class="tab-btn rounded-t px-3 py-2 text-xs font-medium">Advanced</button>
        </div>

        <form method="post" action="/domains/php-settings" class="flex min-h-0 flex-1 flex-col">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="act" value="save">
          <input type="hidden" name="back" value="/php-extensions">
          <input type="hidden" name="ver" value="<?= $e($ver) ?>">
          <input type="hidden" name="scope" value="all">
          <div class="max-h-96 flex-1 space-y-3 overflow-y-auto p-4">
            <?php foreach ([['basic', $BASIC], ['advanced', $ADVANCED]] as [$tab, $keys]): ?>
            <div data-panel="<?= $e($tab) ?>" class="<?= $tab === 'advanced' ? 'hidden' : '' ?> space-y-3">
              <?php foreach ($keys as $key):
                $cur = (string)($iniCur[$key] ?? ($iniDef[$key] ?? ''));
                $boolish = in_array($key, ['display_errors', 'display_startup_errors', 'opcache.enable',
                                           'opcache.validate_timestamps'], true);
              ?>
              <label class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,15rem)_1fr] sm:items-center sm:gap-3">
                <span class="font-mono text-[11px] text-slate-600 dark:text-slate-400"><?= $e($key) ?></span>
                <?php if ($boolish): ?>
                  <select name="ini_<?= $e($key) ?>" class="input h-8 w-full text-xs sm:w-28">
                    <option value="1" <?= $cur === '1' ? 'selected' : '' ?>>On</option>
                    <option value="0" <?= $cur !== '1' ? 'selected' : '' ?>>Off</option>
                  </select>
                <?php else: ?>
                  <input name="ini_<?= $e($key) ?>" value="<?= $e($cur) ?>"
                         placeholder="<?= $e((string)($iniDef[$key] ?? '')) ?>"
                         class="input h-8 w-full font-mono text-xs">
                <?php endif; ?>
              </label>
              <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 px-4 py-3 dark:border-slate-800">
            <button class="btn-primary h-9 px-4 text-sm"><i data-lucide="save" class="h-4 w-4"></i><span>Save</span></button>
            <?php if ($iniCustom): ?>
              <button form="reset-ini" class="btn-icon" title="Kembalikan ke default server"
                      onclick="return confirm('Kembalikan PHP Settings ke default server untuk semua domain?')">
                <i data-lucide="undo-2" class="h-4 w-4"></i>
              </button>
              <form id="reset-ini" method="post" action="/domains/php-settings" class="hidden">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="act" value="reset">
                <input type="hidden" name="scope" value="all">
                <input type="hidden" name="back" value="/php-extensions">
                <input type="hidden" name="ver" value="<?= $e($ver) ?>">
              </form>
            <?php endif; ?>
            <span class="text-xs text-slate-500">Berlaku untuk <strong><?= $e($nDom) ?></strong> domain Anda.</span>
          </div>
        </form>
      </section>
    </div>
    <?php endif; ?>
  </div>
</main>

<style>
  /* Tab aktif mengikuti tema panel (light/dark). */
  .tab-btn[data-active="1"] { @apply bg-slate-100 font-semibold text-slate-900 dark:bg-slate-800 dark:text-slate-50; }
</style>
<script>
  // Tab Basic / Advanced
  (function () {
    var btns = document.querySelectorAll('.tab-btn');
    var panels = document.querySelectorAll('[data-panel]');
    function show(name) {
      btns.forEach(function (b) { b.setAttribute('data-active', b.dataset.tab === name ? '1' : '0'); });
      panels.forEach(function (p) { p.classList.toggle('hidden', p.dataset.panel !== name); });
    }
    btns.forEach(function (b) { b.addEventListener('click', function () { show(b.dataset.tab); }); });
    if (btns.length) { show(btns[0].dataset.tab); }
  })();

  // Konfirmasi sebelum Apply kalau ada extension sensitif yang dimatikan.
  (function () {
    var form = document.getElementById('form-ext');
    if (!form) return;
    form.addEventListener('submit', function (ev) {
      var dimatikan = [];
      form.querySelectorAll('[data-sens="1"]').forEach(function (el) {
        var box = el.querySelector('input[type=checkbox]');
        var before = el.querySelector('input[type=hidden]');
        if (box && !box.checked && before && before.value === '1') {
          dimatikan.push(el.dataset.ext);
        }
      });
      if (!dimatikan.length) return;
      if (!confirm('Matikan extension sensitif berikut?\n\n' + dimatikan.join(', ') +
                   '\n\nAplikasi yang memakainya bisa error.')) {
        ev.preventDefault();
      }
    });
  })();
</script>
</body>
</html>