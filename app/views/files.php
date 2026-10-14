<?php
/**
 * File Manager: browse, upload, edit teks, rename, hapus.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $list  hasil FileManager::listing()
 * @var array|null $edit  hasil FileManager::readText() atau null
 * @var string $used
 * @var string|null $error, string|null $ok
 */
use Fpanel\Service\FileManager;

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$q = static fn (string $s): string => rawurlencode($s);
$dirs  = $list['dirs'] ?? [];
$files = $list['files'] ?? [];
$cur   = (string)($list['path'] ?? '.');
$okOk  = !empty($list['ok']);
$sz    = static fn (int $b): string => FileManager::humanSize($b);

// Hanya ekstensi teks/bebas-yang-aman boleh dibuka di editor. File .php
// ditolak FileManager::readText(), jadi jangan tawarkan edit untuknya.
$TEXT_EXT = ['txt','md','markdown','json','xml','yml','yaml','csv','log','ini','conf','cfg',
             'htaccess','html','htm','css','js','svg','map','sql','sh','env','gitignore'];
$isText = static function (string $name) use ($TEXT_EXT): bool {
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '') {
        return in_array(strtolower($name), ['readme','license','htaccess'], true);
    }
    return in_array($ext, $TEXT_EXT, true);
};
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-6xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="folder-open" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">File Manager</h1>
        <p class="mt-0.5 text-sm text-slate-500">Kelola berkas situs Anda. Dipakai <strong><?= $e($used) ?></strong>.</p>
      </div>
      <a href="/files" class="btn-icon" title="Muat ulang"><i data-lucide="refresh-cw" class="h-4 w-4"></i></a>
    </div>

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

    <?php if (!$okOk): ?>
      <section class="card p-10 text-center">
        <i data-lucide="folder-x" class="mx-auto h-8 w-8 text-slate-300"></i>
        <p class="mt-3 text-sm text-slate-500"><?= $e((string)($list['error'] ?? 'Folder tidak dapat dibuka.')) ?></p>
        <a href="/files" class="btn-primary mt-4 inline-flex">Kembali ke Home</a>
      </section>
    <?php else: ?>

    <!-- Breadcrumb -->
    <nav class="flex flex-wrap items-center gap-1 text-sm" aria-label="Breadcrumb">
      <a href="/files" class="rounded px-1.5 py-0.5 font-medium text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-500/10">Home</a>
      <?php foreach (array_slice((array)$list['crumbs'], 1) as $i => $c): ?>
        <i data-lucide="chevron-right" class="h-3.5 w-3.5 text-slate-300"></i>
        <?php $last = $i === count((array)$list['crumbs']) - 2; ?>
        <?php if ($last): ?>
          <span class="rounded px-1.5 py-0.5 font-semibold text-slate-700 dark:text-slate-200"><?= $e($c['label']) ?></span>
        <?php else: ?>
          <a href="/files?path=<?= $q((string)$c['path']) ?>" class="rounded px-1.5 py-0.5 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800"><?= $e($c['label']) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <!-- Toolbar -->
    <section class="card p-3">
      <div class="flex flex-wrap items-center gap-2">
        <?php if (!empty($list['parent']) || $cur !== '.'): ?>
          <a href="/files?path=<?= $q((string)$list['parent']) ?>" class="btn-icon" title="Naik satu level"><i data-lucide="corner-left-up" class="h-4 w-4"></i></a>
        <?php endif; ?>

        <form method="post" action="/files/mkdir" class="flex items-center gap-1.5">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="path" value="<?= $e($cur === '.' ? '' : $cur) ?>">
          <input name="name" placeholder="nama-folder" class="input h-9 w-36 text-sm" required>
          <button class="btn-primary h-9 px-3 text-sm" title="Buat folder"><i data-lucide="folder-plus" class="h-4 w-4"></i><span class="hidden sm:inline">Folder</span></button>
        </form>

        <form method="post" action="/files/newfile" class="flex items-center gap-1.5">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="path" value="<?= $e($cur === '.' ? '' : $cur) ?>">
          <input name="name" placeholder="nama.txt" class="input h-9 w-36 text-sm" required>
          <button class="btn-primary h-9 px-3 text-sm" title="Buat file teks"><i data-lucide="file-plus" class="h-4 w-4"></i><span class="hidden sm:inline">File</span></button>
        </form>

        <form method="post" action="/files/upload" enctype="multipart/form-data" class="flex items-center gap-1.5">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="path" value="<?= $e($cur === '.' ? '' : $cur) ?>">
          <input type="file" name="files[]" multiple class="input h-9 max-w-[13rem] text-xs file:mr-2 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-xs dark:file:bg-slate-700">
          <button class="btn-primary h-9 px-3 text-sm" title="Upload"><i data-lucide="upload" class="h-4 w-4"></i><span class="hidden sm:inline">Upload</span></button>
        </form>

        <form method="post" action="/files/zip" class="flex items-center gap-1.5">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="path" value="<?= $e($cur === '.' ? '' : $cur) ?>">
          <input name="name" placeholder="nama-item" class="input h-9 w-36 text-sm" required>
          <button class="btn-primary h-9 px-3 text-sm" title="Kompres jadi .zip (asli tetap ada)"><i data-lucide="file-archive" class="h-4 w-4"></i><span class="hidden sm:inline">Kompres</span></button>
        </form>

        <form method="post" action="/files/unzip" class="flex items-center gap-1.5">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <input type="hidden" name="path" value="<?= $e($cur === '.' ? '' : $cur) ?>">
          <input name="name" placeholder="arsip.zip" class="input h-9 w-36 text-sm" required>
          <input name="to" placeholder="folder-tujuan" class="input h-9 w-36 text-sm">
          <button class="btn-primary h-9 px-3 text-sm" title="Ekstrak .zip ke folder tujuan"><i data-lucide="folder-input" class="h-4 w-4"></i><span class="hidden sm:inline">Ekstrak</span></button>
        </form>
      </div>
    </section>

    <?php if (is_array($edit)): ?>
    <!-- Editor teks -->
    <section class="card overflow-hidden">
      <header class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <i data-lucide="file-code-2" class="h-4 w-4 text-brand-600"></i>
        <h2 class="text-[15px] font-semibold text-slate-800 dark:text-slate-100">Edit: <?= $e($edit['name']) ?></h2>
        <a href="/files?path=<?= $q($cur === '.' ? '' : $cur) ?>" class="btn-icon ml-auto" title="Tutup editor"><i data-lucide="x" class="h-4 w-4"></i></a>
      </header>
      <form method="post" action="/files/save" class="p-4">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="path" value="<?= $e($cur === '.' ? '' : $cur) ?>">
        <input type="hidden" name="name" value="<?= $e($edit['name']) ?>">
        <textarea name="body" rows="20" spellcheck="false" class="input w-full font-mono text-[13px] leading-relaxed"><?= $e($edit['body']) ?></textarea>
        <div class="mt-3 flex justify-end">
          <button class="btn-primary"><i data-lucide="save" class="h-4 w-4"></i> Simpan</button>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <!-- Daftar isi -->
    <section class="card overflow-hidden">
      <form method="post" action="/files/delete" id="delForm">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="path" value="<?= $e($cur === '.' ? '' : $cur) ?>">

        <table class="w-full text-sm">
          <thead class="border-b border-slate-100 bg-slate-50/70 text-left text-[13px] text-slate-500 dark:border-slate-800 dark:bg-slate-800/40">
            <tr>
              <th class="w-10 px-3 py-2.5"></th>
              <th class="px-3 py-2.5 font-medium">Nama</th>
              <th class="hidden w-24 px-3 py-2.5 font-medium sm:table-cell">Ukuran</th>
              <th class="hidden w-20 px-3 py-2.5 font-medium lg:table-cell">Izin</th>
              <th class="hidden w-40 px-3 py-2.5 font-medium md:table-cell">Diubah</th>
              <th class="w-40 px-3 py-2.5 text-right font-medium">Aksi</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php if ($dirs === [] && $files === []): ?>
              <tr><td colspan="6" class="px-3 py-10 text-center text-slate-500">Folder ini kosong.</td></tr>
            <?php endif; ?>

            <?php
            $render = static function (array $row, bool $isDir) use ($e, $q, $cur, $csrf, $sz, $isText): void { ?>
              <tr class="hover:bg-slate-50/60 dark:hover:bg-slate-800/40">
                <td class="px-3 py-2">
                  <input type="checkbox" name="names[]" value="<?= $e($row['name']) ?>"
                         class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                </td>
                <td class="px-3 py-2">
                  <div class="flex items-center gap-2 min-w-0">
                    <?php if ($isDir): ?>
                      <i data-lucide="folder" class="h-4 w-4 shrink-0 text-amber-500"></i>
                      <a href="/files?path=<?= $q($cur === '.' || $cur === '' ? $row['name'] : $cur . '/' . $row['name']) ?>"
                         class="truncate font-medium text-slate-800 hover:text-brand-600 hover:underline dark:text-slate-100"><?= $e($row['name']) ?></a>
                    <?php else: ?>
                      <i data-lucide="<?= $isText($row['name'] ?? '') ? 'file-text' : 'file' ?>" class="h-4 w-4 shrink-0 text-slate-400"></i>
                      <a href="/files?path=<?= $q($cur === '.' ? '' : $cur) ?>&edit=<?= $q($row['name']) ?>"
                         class="truncate text-slate-700 hover:text-brand-600 hover:underline dark:text-slate-200"><?= $e($row['name']) ?></a>
                      <?php if (!empty($row['is_link'])): ?>
                        <i data-lucide="link" class="h-3.5 w-3.5 shrink-0 text-sky-500" title="Symlink"></i>
                      <?php endif; ?>
                      <?php if (!empty($row['writable'])): ?>
                        <i data-lucide="lock-open" class="h-3.5 w-3.5 shrink-0 text-emerald-500" title="Bisa ditulis"></i>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="hidden px-3 py-2 text-slate-500 sm:table-cell"><?= $isDir ? '&mdash;' : $e($sz((int)$row['size'])) ?></td>
                <td class="hidden px-3 py-2 font-mono text-[12px] text-slate-500 lg:table-cell"><?= $e((string)($row['mode'] ?? '----')) ?></td>
                <td class="hidden px-3 py-2 text-slate-500 md:table-cell"><?= $e($row['mtime'] ? date('Y-m-d H:i', (int)$row['mtime']) : '-') ?></td>
                <td class="px-3 py-2">
                  <div class="flex items-center justify-end gap-1">
                    <?php if (!$isDir && $isText((string)$row['name'])): ?>
                      <a href="/files?path=<?= $q($cur === '.' ? '' : $cur) ?>&edit=<?= $q($row['name']) ?>"
                         class="btn-icon" title="Edit"><i data-lucide="pencil" class="h-3.5 w-3.5"></i></a>
                    <?php endif; ?>
                    <?php if (!$isDir): ?>
                      <a href="/files/download?path=<?= $q($cur === '.' ? '' : $cur) ?>&name=<?= $q($row['name']) ?>"
                         class="btn-icon" title="Unduh"><i data-lucide="download" class="h-3.5 w-3.5"></i></a>
                    <?php endif; ?>
                    <button type="submit" formaction="/files/chmod" name="name" value="<?= $e($row['name']) ?>"
                            formmethod="post" formnovalidate
                            onclick="this.form.mode.value=prompt('Permission oktal untuk <?= $e(addslashes($row['name'])) ?> (mis. 644)','<?= $e((string)($row['mode'] ?? '0644')) ?>');return !!this.form.mode.value"
                            class="btn-icon" title="Ubah permission">
                      <input type="hidden" name="mode"><i data-lucide="shield-half" class="h-3.5 w-3.5"></i>
                    </button>
                    <button type="submit" formaction="/files/copy" name="name" value="<?= $e($row['name']) ?>"
                            formmethod="post" formnovalidate
                            onclick="this.form.to.value=prompt('Salin ke folder mana? (Kosongkan untuk folder ini)','');return true"
                            class="btn-icon" title="Salin ke folder lain">
                      <input type="hidden" name="to"><i data-lucide="copy" class="h-3.5 w-3.5"></i>
                    </button>
                    <button type="submit" formaction="/files/move" name="name" value="<?= $e($row['name']) ?>"
                            formmethod="post" formnovalidate
                            onclick="this.form.to.value=prompt('Pindahkan ke folder mana? (Kosongkan untuk folder ini)','');return true"
                            class="btn-icon" title="Pindahkan ke folder lain">
                      <input type="hidden" name="to"><i data-lucide="folder-symlink" class="h-3.5 w-3.5"></i>
                    </button>
                    <button type="submit" formaction="/files/rename" name="from" value="<?= $e($row['name']) ?>"
                            formmethod="post" formnovalidate
                            onclick="this.form.to.value=prompt('Nama baru untuk <?= $e(addslashes($row['name'])) ?>');return !!this.form.to.value"
                            class="btn-icon" title="Ubah nama">
                      <input type="hidden" name="to"><i data-lucide="file-cog" class="h-3.5 w-3.5"></i>
                    </button>
                    <button type="submit" name="name" value="<?= $e($row['name']) ?>" formmethod="post"
                            onclick="return confirm('Hapus <?= $e(addslashes($row['name'])) ?>?')"
                            class="btn-icon text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-500/10" title="Hapus">
                      <i data-lucide="trash-2" class="h-3.5 w-3.5"></i>
                    </button>
                  </div>
                </td>
              </tr>
            <?php };

            foreach ($dirs as $d) {
                $render($d, true);
            }
            foreach ($files as $f) {
                $render($f, false);
            }
            ?>
          </tbody>
        </table>

        <?php if ($dirs !== [] || $files !== []): ?>
          <div class="flex items-center justify-between gap-2 border-t border-slate-100 px-3 py-2.5 dark:border-slate-800">
            <p class="text-xs text-slate-500"><?= count($dirs) ?> folder, <?= count($files) ?> file</p>
            <button type="submit" class="btn-primary h-8 px-3 text-xs"
                    onclick="return confirm('Hapus semua item terpilih?')">
              <i data-lucide="trash-2" class="h-3.5 w-3.5"></i> Hapus terpilih
            </button>
          </div>
        <?php endif; ?>
      </form>
    </section>

    <p class="text-xs text-slate-400">
      Upload .php diblokir demi keamanan. Berkas &gt; 64 MB ditolak.
      Kompres membuat <code>.zip</code> baru dan <strong>tidak</strong> menghapus aslinya.
    </p>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
