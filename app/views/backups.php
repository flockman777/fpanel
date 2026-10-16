<?php
/**
 * Panel backup & restore.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $data   result of Backup::listing()
 * @var bool $ready   is the backup helper installed?
 * @var array $offsite offsite status (bucket, archive count, error)
 * @var string|null $error, string|null $ok, string|null $report
 */
$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$data  = (array)($data ?? []);
$items = (array)($data['items'] ?? []);
$dOk   = !empty($data['ok']);
$err   = ($dOk ? '' : (string)($data['error'] ?? 'Failed to load the backup list.'));
$last  = (string)($data['last_log'] ?? '');
$oData = (array)($offsite ?? []);
$oCfg  = (array)($offsiteConfig ?? []);
$oOk   = !empty($oData['configured']) && !empty($oData['reachable']);
$oErr  = (string)($oData['error'] ?? $oCfg['error'] ?? 'Offsite is not configured yet.');
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-6xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="archive" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">Backup</h1>
        <p class="mt-0.5 text-sm text-slate-500">
          Stores panel metadata, website files, databases, nginx config, and certificates.
          Scheduled automatically every day at <strong>03:17 UTC</strong>.
        </p>
      </div>
      <a href="/backups" class="btn-icon" title="Reload"><i data-lucide="refresh-cw" class="h-4 w-4"></i></a>
    </div>

    <?php foreach ([['error', 'rose', 'alert-circle'], ['ok', 'emerald', 'check-circle-2']] as [$key, $tone, $icon]): ?>
      <?php if ((${$key} ?? '') !== ''): ?>
        <div class="flex items-start gap-2 rounded-xl border border-<?= $tone ?>-200 bg-<?= $tone ?>-50 px-4 py-3 text-sm text-<?= $tone ?>-800 dark:border-<?= $tone ?>-500/30 dark:bg-<?= $tone ?>-500/10 dark:text-<?= $tone ?>-200">
          <i data-lucide="<?= $icon ?>" class="mt-0.5 h-4 w-4 shrink-0"></i>
          <span class="min-w-0"><?= $e((string)${$key}) ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if (!$ready): ?>
      <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
        <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0"></i>
        <span>The backup helper is not installed on this server, so backups cannot be run from the panel.</span>
      </div>
    <?php endif; ?>

    <?php if (($report ?? '') !== ''): ?>
      <section class="card overflow-hidden">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5 dark:border-slate-800">
          <i data-lucide="shield-check" class="h-4 w-4 text-slate-500"></i>
          <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">Verification report</span>
          <a href="/backups" class="btn-icon ml-auto" title="Close"><i data-lucide="x" class="h-4 w-4"></i></a>
        </div>
        <pre class="max-h-72 overflow-auto px-4 py-3 text-[12px] leading-relaxed text-slate-700 dark:text-slate-300"><?= $e((string)$report) ?></pre>
      </section>
    <?php endif; ?>

    <div class="flex flex-wrap items-end gap-2">
      <!-- Offsite: a copy outside this server. The only thing that saves
           the data if the server or its hosting provider disappears. -->
      <section class="card p-4">
        <div class="flex flex-wrap items-start gap-3">
          <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg <?= $oOk ? 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-700' ?>">
            <i data-lucide="cloud-upload" class="h-[18px] w-[18px]"></i>
          </span>
          <div class="min-w-0 flex-1">
            <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">
              Backup Offsite
              <?php if ($oOk): ?>
                <span class="ml-1 rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">active</span>
              <?php else: ?>
                <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300">inactive</span>
              <?php endif; ?>
            </h2>
            <?php if ($oOk): ?>
              <p class="mt-0.5 text-xs text-slate-500">
                Bucket <code class="rounded bg-slate-100 px-1 dark:bg-slate-800"><?= $e((string)$oCfg['bucket']) ?></code>
                at <?= $e((string)$oCfg['endpoint']) ?> &middot;
                <?= (int)($oData['remote'] ?? 0) ?> offsite archives
                <?php if (!empty($oData['latest_remote'])): ?>
                  &middot; latest <code class="rounded bg-slate-100 px-1 dark:bg-slate-800"><?= $e(basename((string)$oData['latest_remote'])) ?></code>
                <?php endif; ?>
              </p>
            <?php else: ?>
              <p class="mt-0.5 text-xs text-slate-500"><?= $e($oErr) ?></p>
              <p class="mt-1 text-xs text-slate-500">
                Create <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">/etc/fpanel/offsite.conf</code>
                with <code class="font-mono">endpoint</code>, <code class="font-mono">bucket</code>,
                <code class="font-mono">access_key</code>, <code class="font-mono">secret_key</code>.
                Works with Backblaze B2, Cloudflare R2, Wasabi, MinIO, and AWS S3.
              </p>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($oOk): ?>
          <form method="post" action="/backups/offsite" class="mt-3 flex flex-wrap items-end gap-2">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="btn-primary h-9 px-3 text-sm">
              <i data-lucide="cloud-upload" class="h-4 w-4"></i><span>Upload the latest archive</span>
            </button>
            <button class="btn-secondary h-9 px-3 text-sm" name="action" value="prune">
              <i data-lucide="scissors" class="h-4 w-4"></i><span>Prune offsite</span>
            </button>
            <p class="text-xs text-slate-500">
              Archives are encrypted before upload, so files in the bucket cannot be read
              by anyone who only has storage access.
            </p>
          </form>
        <?php endif; ?>
      </section>

      <form method="post" action="/backups/create" class="flex items-end gap-2">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">How many archives to keep</span>
          <input type="number" name="keep" value="<?= $e((string)($data['keep'] ?? 7)) ?>" min="1" max="100"
                 class="input h-9 w-24 text-sm">
        </label>
        <button class="btn-primary h-9 px-3 text-sm" <?= $ready ? '' : 'disabled' ?>>
          <i data-lucide="archive" class="h-4 w-4"></i><span>Back up now</span>
        </button>
      </form>
      <form method="post" action="/backups/prune" class="flex items-end gap-2">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="keep" value="<?= $e((string)($data['keep'] ?? 7)) ?>">
        <button class="btn-icon" title="Run retention: drop old archives according to policy" <?= $ready ? '' : 'disabled' ?>>
          <i data-lucide="scissors" class="h-4 w-4"></i>
        </button>
      </form>
    </div>

    <?php if (!$dOk): ?>
      <section class="card p-10 text-center">
        <i data-lucide="server-off" class="mx-auto h-8 w-8 text-slate-300"></i>
        <p class="mt-3 text-sm text-slate-500"><?= $e($err) ?></p>
      </section>
    <?php elseif ($items === []): ?>
      <section class="card p-10 text-center">
        <i data-lucide="archive" class="mx-auto h-8 w-8 text-slate-300"></i>
        <p class="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">No backups yet</p>
        <p class="mt-1 text-sm text-slate-500">Click &ldquo;Back up now&rdquo;, or wait for the automatic schedule at 03:17 UTC.</p>
      </section>
    <?php else: ?>
      <section class="card overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800">
                <th class="px-4 py-2.5 font-semibold">Archive</th>
                <th class="px-4 py-2.5 font-semibold">Created (UTC)</th>
                <th class="px-4 py-2.5 font-semibold">Size</th>
                <th class="px-4 py-2.5 text-right font-semibold">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
              <?php foreach ($items as $b): ?>
              <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                <td class="px-4 py-2.5 font-mono text-slate-900 dark:text-slate-100"><?= $e($b['name']) ?></td>
                <td class="px-4 py-2.5 text-slate-500"><?= $e($b['created_utc']) ?></td>
                <td class="px-4 py-2.5 text-slate-700 dark:text-slate-300"><?= $e($b['size_human']) ?></td>
                <td class="px-4 py-2.5">
                  <div class="flex items-center justify-end gap-1">
                    <form method="post" action="/backups/verify" class="shrink-0">
                      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="name" value="<?= $e($b['name']) ?>">
                      <button class="btn-icon" title="Verify checksum & integrity"><i data-lucide="shield-check" class="h-4 w-4"></i></button>
                    </form>
                    <form method="post" action="/backups/delete" class="shrink-0"
                          onsubmit="return confirm('Delete archive <?= $e(addslashes($b['name'])) ?>?')">
                      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="name" value="<?= $e($b['name']) ?>">
                      <button class="btn-icon hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10" title="Delete archive">
                        <i data-lucide="trash-2" class="h-4 w-4"></i>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <section class="card overflow-hidden">
      <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5 dark:border-slate-800">
        <i data-lucide="scroll-text" class="h-4 w-4 text-slate-500"></i>
        <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">Last run log</span>
      </div>
      <pre class="max-h-56 overflow-auto px-4 py-3 text-[12px] leading-relaxed text-slate-700 dark:text-slate-300"><?= $e($last) ?></pre>
    </section>

    <div class="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">
      <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0"></i>
      <span>
        <strong>Restore is not available on this page.</strong>
        Restore overwrites a live database, so it can only be run from the shell:
        <code class="rounded bg-white/60 px-1 dark:bg-slate-900/40">php /srv/fpanel/bin/fpanel</code>
        does not provide it &mdash; use the helper directly:
        <code class="rounded bg-white/60 px-1 dark:bg-slate-900/40">sudo /usr/local/sbin/fpanel-backup restore &lt;name&gt; &lt;name&gt;</code>.
        A safety snapshot is created automatically before anything is overwritten.
      </span>
    </div>
  </div>
</main>
</body>
</html>