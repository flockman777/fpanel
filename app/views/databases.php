<?php
/**
 * MariaDB Databases: buat/hapus database + user MySQL-nya.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $data   hasil MariaDb::listing()
 * @var array $health hasil MariaDb::health()
 * @var array|null $newCred  kredensial baru yang perlu disimpan user
 * @var string|null $error, string|null $ok
 * @var array $dbs, array $quota
 * @var array $privs  label => privilege string
 */
use Fpanel\Service\MariaDb;
use Fpanel\Service\PhpMyAdmin;

$e   = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$dbs = (array)($data['databases'] ?? []);
$q   = (array)($data['quota'] ?? []);
$privs = MariaDb::PRIV_CHOICES;
// Balik peta label => privilege string, supaya yang tampil di tabel adalah
// nama level yang dipahami user ("READ WRITE"), bukan string SQL panjang.
$pmaReady  = PhpMyAdmin::available();
$privLabel = static function (string $granted): string {
    $flipped = array_flip(MariaDb::PRIV_CHOICES);
    return $flipped[$granted] ?? $granted;
};
$maxDb = (int)($q['max_db'] ?? 0);
$maxUs = (int)($q['max_users'] ?? 0);
$dbFull   = $maxDb > 0 && (int)($q['used_db'] ?? 0) >= $maxDb;
$userFull = $maxUs > 0 && (int)($q['used_users'] ?? 0) >= $maxUs;
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-6xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="database" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">MariaDB Databases</h1>
        <p class="mt-0.5 text-sm text-slate-500">
          Database <strong><?= $e((int)($q['used_db'] ?? 0)) ?><?= $maxDb > 0 ? ' / ' . $e($maxDb) : '' ?></strong>
          &middot; user DB <strong><?= $e((int)($q['used_users'] ?? 0)) ?><?= $maxUs > 0 ? ' / ' . $e($maxUs) : '' ?></strong>
        </p>
      </div>
      <a href="/databases" class="btn-icon" title="Muat ulang"><i data-lucide="refresh-cw" class="h-4 w-4"></i></a>
    </div>

    <?php if (($health['ok'] ?? true) === false): ?>
      <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0"></i>
        <span><?= $e((string)($health['error'] ?? 'MariaDB tidak bisa dijangkau.')) ?></span>
      </div>
    <?php endif; ?>

    <?php if (($error ?? '') !== ''): ?>
      <div class="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
        <i data-lucide="alert-circle" class="mt-0.5 h-4 w-4 shrink-0"></i>
        <span><?= $e($error) ?></span>
      </div>
    <?php endif; ?>
    <?php if (($ok ?? '') !== ''): ?>
      <div class="flex items-start gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-200">
        <i data-lucide="check-circle-2" class="mt-0.5 h-4 w-4 shrink-0"></i>
        <span><?= $e($ok) ?></span>
      </div>
    <?php endif; ?>

    <?php if (!empty($newCred)): ?>
      <?php
      // Password hanya ditampilkan sekali, di halaman balasan create.
      // Setelah ini hanya bisa diambil ulang lewat tombol "Tampilkan".
      ?>
      <section class="card overflow-hidden border-emerald-300 dark:border-emerald-500/40">
        <div class="flex items-start gap-2 border-b border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">
          <i data-lucide="key-round" class="mt-0.5 h-4 w-4 shrink-0"></i>
          <span>Simpan kredensial ini sekarang &mdash; password tidak ditampilkan lagi otomatis.</span>
        </div>
        <dl class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
          <div class="flex flex-wrap items-center gap-x-3 px-4 py-2.5">
            <dt class="w-28 shrink-0 text-slate-500">Database</dt>
            <dd class="font-mono font-semibold text-slate-900 dark:text-slate-100"><?= $e($newCred['db'] ?? '') ?></dd>
          </div>
          <div class="flex flex-wrap items-center gap-x-3 px-4 py-2.5">
            <dt class="w-28 shrink-0 text-slate-500">Host</dt>
            <dd class="font-mono text-slate-700 dark:text-slate-300">localhost</dd>
          </div>
          <div class="flex flex-wrap items-center gap-x-3 px-4 py-2.5">
            <dt class="w-28 shrink-0 text-slate-500">User</dt>
            <dd class="font-mono text-slate-700 dark:text-slate-300"><?= $e($newCred['user'] ?? '') ?></dd>
          </div>
          <div class="flex flex-wrap items-center gap-x-3 bg-slate-50 px-4 py-2.5 dark:bg-slate-800/40">
            <dt class="w-28 shrink-0 text-slate-500">Password</dt>
            <dd class="flex min-w-0 flex-1 items-center gap-2">
              <code class="select-all break-all font-mono font-semibold text-slate-900 dark:text-slate-100"><?= $e($newCred['password'] ?? '') ?></code>
              <button type="button" class="btn-icon shrink-0" data-copy="<?= $e($newCred['password'] ?? '') ?>" title="Salin password"><i data-lucide="copy" class="h-4 w-4"></i></button>
            </dd>
          </div>
        </dl>
      </section>
    <?php endif; ?>

    <!-- Form create -->
    <section class="card p-4">
      <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-slate-100">Buat database baru</h2>
      <?php if ($dbFull || $userFull): ?>
        <p class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
          <i data-lucide="alert-triangle" class="mr-1 inline h-3.5 w-3.5"></i>
          Kuota paket Anda sudah penuh. Hapus database yang tidak terpakai atau upgrade paket.
        </p>
      <?php endif; ?>
      <form method="post" action="/databases/create" class="flex flex-wrap items-end gap-2">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Nama database</span>
          <input name="db" placeholder="toko_online" pattern="[a-z0-9_]{1,32}" maxlength="32"
                 class="input h-9 w-40 text-sm" required <?= $dbFull ? 'disabled' : '' ?>>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">User database</span>
          <input name="user" placeholder="toko_app" pattern="[a-z0-9_]{1,16}" maxlength="16"
                 class="input h-9 w-40 text-sm" required <?= $userFull ? 'disabled' : '' ?>>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Hak akses</span>
          <select name="priv" class="input h-9 w-40 text-sm" <?= $userFull ? 'disabled' : '' ?>>
            <?php foreach ($privs as $label => $_): ?>
              <option value="<?= $e($label) ?>"><?= $e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button class="btn-primary h-9 px-3 text-sm" <?= ($dbFull || $userFull) ? 'disabled' : '' ?>>
          <i data-lucide="plus" class="h-4 w-4"></i><span>Buat</span>
        </button>
      </form>
      <p class="mt-2 text-xs text-slate-500">
        Nama fisik di server diawali username Anda, jadi tidak akan bentrok dengan database user lain.
        Password dibuat acak oleh sistem.
      </p>
    </section>

    <?php if ($dbs === []): ?>
      <section class="card p-10 text-center">
        <i data-lucide="database" class="mx-auto h-8 w-8 text-slate-300"></i>
        <p class="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">Belum ada database</p>
        <p class="mt-1 text-sm text-slate-500">Isi form di atas untuk membuat database pertama Anda.</p>
      </section>
    <?php else: ?>
      <?php foreach ($dbs as $d):
        $dbId = (int)$d['id'];
        $users = (array)($d['users'] ?? []);
      ?>
      <section class="card overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          <i data-lucide="database" class="h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400"></i>
          <div class="min-w-0 flex-1">
            <p class="truncate font-mono text-sm font-semibold text-slate-900 dark:text-slate-100"><?= $e($d['name']) ?></p>
            <p class="text-xs text-slate-500">
              <?= $e($d['charset']) ?> &middot; <?= $e((string)$d['server']) ?>
              &middot; <?= $e((int)$d['used_mb']) ?> MB terpakai
            </p>
          </div>
          <form method="post" action="/databases/delete" class="shrink-0"
                onsubmit="return confirm('Hapus database <?= $e(addslashes((string)$d['name'])) ?>? Semua isinya hilang permanen.')">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="db" value="<?= $e($dbId) ?>">
            <button class="btn-icon hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10" title="Hapus database">
              <i data-lucide="trash-2" class="h-4 w-4"></i>
            </button>
          </form>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800">
                <th class="px-4 py-2.5 font-semibold">User</th>
                <th class="px-4 py-2.5 font-semibold">Host</th>
                <th class="px-4 py-2.5 font-semibold">Hak akses</th>
                <th class="px-4 py-2.5 text-right font-semibold">Aksi</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
              <?php foreach ($users as $du):
                $duId = (int)$du['id'];
                $short = (string)$du['name'];
              ?>
              <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                <td class="px-4 py-2.5 font-mono text-slate-900 dark:text-slate-100"><?= $e($short) ?></td>
                <td class="px-4 py-2.5 text-slate-500"><?= $e((string)$du['host']) ?></td>
                <td class="px-4 py-2.5">
                  <span class="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                    <?= $e($privLabel((string)$du['privileges'])) ?>
                  </span>
                </td>
                <td class="px-4 py-2.5">
                  <div class="flex items-center justify-end gap-1">
                    <?php if ($pmaReady): ?>
                    <form method="post" action="/databases/phpmyadmin" class="shrink-0">
                      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="db" value="<?= $e($dbId) ?>">
                      <input type="hidden" name="dbuser" value="<?= $e($duId) ?>">
                      <button class="btn-icon" title="Buka phpMyAdmin untuk database ini"><i data-lucide="table-2" class="h-4 w-4"></i></button>
                    </form>
                    <?php endif; ?>
                    <form method="post" action="/databases/reveal" class="shrink-0">
                      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="db" value="<?= $e($dbId) ?>">
                      <input type="hidden" name="dbuser" value="<?= $e($duId) ?>">
                      <button class="btn-icon" title="Tampilkan password"><i data-lucide="eye" class="h-4 w-4"></i></button>
                    </form>
                    <?php if (count($users) > 1): ?>
                    <form method="post" action="/databases/user-delete" class="shrink-0"
                          onsubmit="return confirm('Hapus user <?= $e(addslashes($short)) ?>?')">
                      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="db" value="<?= $e($dbId) ?>">
                      <input type="hidden" name="dbuser" value="<?= $e($duId) ?>">
                      <button class="btn-icon hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10" title="Hapus user">
                        <i data-lucide="user-x" class="h-4 w-4"></i>
                      </button>
                    </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <?php if (!$userFull): ?>
        <form method="post" action="/databases/user-add" class="flex flex-wrap items-end gap-2 border-t border-slate-100 p-4 dark:border-slate-800">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="db" value="<?= $e($dbId) ?>">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Tambah user</span>
            <input name="user" placeholder="user_baru" pattern="[a-z0-9_]{1,16}" maxlength="16" class="input h-9 w-36 text-sm" required>
          </label>
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Hak akses</span>
            <select name="priv" class="input h-9 w-36 text-sm">
              <?php foreach ($privs as $label => $_): ?>
                <option value="<?= $e($label) ?>"><?= $e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="btn-primary h-9 px-3 text-sm"><i data-lucide="user-plus" class="h-4 w-4"></i><span>Tambah</span></button>
        </form>
        <?php endif; ?>
      </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>
<script>
  // Tombol salin untuk password database baru.
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', async function () {
      try {
        await navigator.clipboard.writeText(b.getAttribute('data-copy'));
        b.classList.add('text-emerald-600');
        setTimeout(function () { b.classList.remove('text-emerald-600'); }, 1200);
      } catch (e) { /* clipboard butuh HTTPS; diamkan saja */ }
    });
  });
</script>
</body>
</html>