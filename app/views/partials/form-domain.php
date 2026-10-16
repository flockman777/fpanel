<?php
/** @var array $me, string $csrf, ?string $error, ?string $ok, array $phpInstalled, array $phpDefault, array $rows */
$phpInstalled = $phpInstalled ?? [];
$phpDefault   = $phpDefault   ?? '8.3';
$error = $error ?? null;
$ok    = $ok    ?? null;
?>
<section class="card">
  <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
    <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
      <i data-lucide="globe" class="h-4 w-4"></i></span>
    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Add Domain</h2>
    <span class="ml-auto text-xs text-slate-400">creates the docroot + nginx vhost automatically</span>
  </div>

  <form method="post" action="/domains" class="space-y-3 px-4 py-4">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">

    <?php if ($error): ?>
    <p class="flex items-start gap-2 rounded-lg bg-rose-50 px-3 py-2.5 text-sm text-rose-700
              dark:bg-rose-500/10 dark:text-rose-300">
      <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= htmlspecialchars($error) ?></span>
    </p>
    <?php elseif ($ok): ?>
    <p class="flex items-start gap-2 rounded-lg bg-emerald-50 px-3 py-2.5 text-sm text-emerald-700
              dark:bg-emerald-500/10 dark:text-emerald-300">
      <i data-lucide="circle-check-big" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= htmlspecialchars($ok) ?></span>
    </p>
    <?php endif; ?>

    <div class="grid gap-3 sm:grid-cols-4">
      <label class="block sm:col-span-2">
        <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Domain name</span>
        <input type="text" name="name" required placeholder="example.com" autocomplete="off"
               pattern="[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+"
               class="input font-mono">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Type</span>
        <select name="type" class="input" data-type-select>
          <?php foreach (\Fpanel\Service\DomainManager::TYPES as $t): ?>
          <option value="<?= htmlspecialchars($t) ?>"><?= $t === 'php' ? 'PHP (default)' : 'Static (HTML/CSS only)' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="block" data-php-row>
        <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">PHP version</span>
        <select name="php_version" class="input">
          <?php foreach ($phpInstalled as $v): ?>
          <option value="<?= htmlspecialchars($v) ?>" <?= $v === $phpDefault ? 'selected' : '' ?>>
            <?= htmlspecialchars($v) ?><?= $v === $phpDefault ? ' (default)' : '' ?>
          </option>
          <?php endforeach; ?>
          <?php if ($phpInstalled === []): ?>
          <option value="">-</option>
          <?php endif; ?>
        </select>
      </label>
    </div>

    <p class="text-xs leading-relaxed text-slate-500">
      PHP by default. Choose <strong>Static</strong> only if this domain really does not
      need PHP. Need Node.js or Python? Create the domain as usual, then deploy it with
      <a href="/apps" class="font-medium text-brand-600 hover:underline dark:text-brand-300">Node.js / Python App</a>.
      <br>
      The A record must point to <span class="font-mono"><?= htmlspecialchars(\Fpanel\Service\Stats::publicIp()) ?></span> for the site to be publicly reachable.
      Docroot: <span class="font-mono">/var/www/<?= htmlspecialchars($me['username']) ?>/&lt;domain&gt;/public</span>
    </p>

    <label class="flex items-start gap-2 rounded-lg border border-slate-200 px-3 py-2.5 text-sm
                  dark:border-slate-700">
      <input type="checkbox" name="issue_tls" value="1" checked
             class="mt-0.5 h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
      <span>
        <span class="block font-medium text-slate-700 dark:text-slate-200">Issue SSL automatically</span>
        <span class="block text-xs text-slate-500 dark:text-slate-400">
          Requires the A record to already point to this server. If it fails, the domain still works over HTTP
          and can be retried later.
        </span>
      </span>
    </label>

    <button type="submit" class="btn-primary">
      <i data-lucide="plus" class="h-4 w-4"></i>Create Domain
    </button>
  </form>

  <?php if (!empty($rows)): ?>
  <div class="border-t border-slate-100 dark:border-slate-800">
    <table class="w-full text-sm">
      <thead>
        <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800">
          <th class="px-4 py-2 font-semibold">Domain</th>
          <th class="px-4 py-2 font-semibold">Docroot</th>
          <th class="px-4 py-2 font-semibold">Runtime</th>
          <th class="px-4 py-2 font-semibold">Status</th>
          <th class="px-4 py-2 font-semibold">SSL</th>
          <th class="px-4 py-2"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
        <?php foreach ($rows as $r): ?>
        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
          <td class="px-4 py-2 font-mono font-medium text-slate-800 dark:text-slate-100"><?= htmlspecialchars($r['name']) ?></td>
          <td class="px-4 py-2 font-mono text-xs text-slate-500"><?= htmlspecialchars($r['docroot']) ?></td>
          <td class="px-4 py-2 text-slate-700 dark:text-slate-300">
            <?= htmlspecialchars($r['type']) ?><?= $r['rt'] !== '' ? ' · ' . htmlspecialchars($r['rt']) : '' ?>
          </td>
          <td class="px-4 py-2">
            <span class="inline-block rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700
                         dark:bg-emerald-500/10 dark:text-emerald-300"><?= htmlspecialchars($r['status']) ?></span>
          </td>
          <td class="px-4 py-2">
            <?php
            $sslLive = (bool)($r['ssl_live'] ?? false);
            $sslDays = $r['ssl_days'] ?? null;
            $sslErr  = (string)($r['ssl_error'] ?? '');
            if ($sslLive) { ?>
              <a href="https://<?= htmlspecialchars($r['name']) ?>/" target="_blank" rel="noopener"
                 class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium
                        text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300">
                <i data-lucide="lock" class="h-3 w-3"></i>Active<?= $sslDays !== null ? ' · ' . (int)$sslDays . 'h' : '' ?>
              </a>
            <?php } elseif ($sslErr !== '') { ?>
              <span class="inline-block max-w-[16rem] truncate rounded-full bg-rose-50 px-2 py-0.5 text-[11px]
                           text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"
                    title="<?= htmlspecialchars($sslErr) ?>">Failed</span>
            <?php } else { ?>
              <span class="inline-block rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500
                           dark:bg-slate-800 dark:text-slate-400">HTTP only</span>
            <?php } ?>
          </td>
          <td class="px-4 py-2 text-right">
            <form method="post" action="/domains/ssl" class="inline">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <?php if ($sslLive) { ?>
                <input type="hidden" name="action" value="revoke">
                <button type="submit" class="rounded-lg px-2 py-1 text-slate-500 hover:bg-slate-100
                                        dark:hover:bg-slate-800"
                        title="Revoke certificate" aria-label="Revoke the certificate for <?= htmlspecialchars($r['name']) ?>"
                        data-confirm="Cabut sertifikat &lt;?= htmlspecialchars($r['name']) ?&gt;? The site will fall back to HTTP.">
                  <i data-lucide="shield-off" class="h-4 w-4"></i>
                </button>
              <?php } else { ?>
                <input type="hidden" name="action" value="issue">
                <button type="submit" class="rounded-lg px-2 py-1 text-emerald-600 hover:bg-emerald-50
                                        dark:hover:bg-slate-800"
                        title="Issue certificate" aria-label="Issue a certificate for <?= htmlspecialchars($r['name']) ?>">
                  <i data-lucide="shield-check" class="h-4 w-4"></i>
                </button>
              <?php } ?>
            </form>
            <form method="post" action="/domains/delete" class="inline"
                  data-confirm="Delete domain &lt;?= htmlspecialchars($r['name']) ?&gt;? The nginx vhost and docroot files are deleted too.">
              <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="rounded-lg px-2 py-1 text-rose-600 hover:bg-rose-50 dark:hover:bg-slate-800"
                      title="Delete domain" aria-label="Delete domain">
                <i data-lucide="trash" class="h-4 w-4"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<?php // ---- Per-domain PHP settings (applied via fastcgi_param PHP_VALUE) ---- ?>
<?php
$e        = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$defaults = (array)($phpDefaults ?? []);
$domRows  = (array)($rows ?? []);
?>
<?php if ($domRows !== []): ?>
<section class="card mt-4 overflow-hidden">
  <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
    <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Per-domain PHP settings</h2>
    <p class="mt-0.5 text-xs text-slate-500">
      The values here apply only to that domain. Extensions are managed separately on the
      <strong>PHP Extensions</strong> page.
    </p>
  </div>
  <div class="divide-y divide-slate-100 dark:divide-slate-800">
    <?php foreach ($domRows as $dr):
      if (($dr['type'] ?? '') !== 'php') { continue; }
      $did   = (int)$dr['id'];
      $uname = (string)$me['username'];
      $own   = \Fpanel\Service\PhpSettings::forDomain($uname, $did);
      $cur   = array_merge((array)$defaults, $own);
      $isDef = $own === [];
      $ph    = static fn (string $k): string => (string)($defaults[$k] ?? '');
    ?>
    <details class="group">
      <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/50">
        <i data-lucide="chevron-right" class="h-4 w-4 shrink-0 text-slate-400 transition group-open:rotate-90"></i>
        <span class="font-mono text-sm text-slate-900 dark:text-slate-100"><?= $e((string)$dr['name']) ?></span>
        <?php if ($isDef): ?>
          <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] font-medium text-slate-500 dark:bg-slate-700 dark:text-slate-400">default</span>
        <?php else: ?>
          <span class="rounded bg-brand-50 px-1.5 py-0.5 text-[10px] font-medium text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">modified</span>
        <?php endif; ?>
        <span class="ml-auto text-xs text-slate-500">PHP <?= $e((string)($dr['rt'] ?? '-')) ?></span>
      </summary>
      <form method="post" action="/domains/php-settings" class="space-y-3 bg-slate-50/60 px-4 pb-4 pt-1 dark:bg-slate-800/30">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="domain" value="<?= $e($did) ?>">
        <input type="hidden" name="act" value="save">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
          <?php foreach ([
              'memory_limit'                => 'Memory limit',
              'upload_max_filesize'         => 'Upload maks',
              'post_max_size'               => 'POST maks',
              'max_execution_time'          => 'Exec time',
              'max_input_vars'              => 'Input vars',
              'opcache.memory_consumption'   => 'OPcache RAM',
              'opcache.max_accelerated_files'=> 'OPcache files',
          ] as $key => $label): ?>
            <label class="block">
              <span class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400"><?= $e($label) ?></span>
              <input name="ini_<?= $e($key) ?>" value="<?= $e((string)($cur[$key] ?? '')) ?>"
                     class="input h-9 text-xs" placeholder="<?= $e($ph($key)) ?>">
            </label>
          <?php endforeach; ?>
          <label class="block">
            <span class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">Display errors</span>
            <select name="ini_display_errors" class="input h-9 text-xs">
              <option value="0" <?= (($cur['display_errors'] ?? '0') === '0') ? 'selected' : '' ?>>off</option>
              <option value="1" <?= (($cur['display_errors'] ?? '0') === '1') ? 'selected' : '' ?>>on</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-1 block text-[11px] font-medium text-slate-600 dark:text-slate-400">OPcache</span>
            <select name="ini_opcache.enable" class="input h-9 text-xs">
              <option value="1" <?= (($cur['opcache.enable'] ?? '1') === '1') ? 'selected' : '' ?>>enabled</option>
              <option value="0" <?= (($cur['opcache.enable'] ?? '1') === '0') ? 'selected' : '' ?>>disabled</option>
            </select>
          </label>
        </div>
        <button class="btn-primary h-9 px-3 text-sm"><i data-lucide="save" class="h-4 w-4"></i><span>Save</span></button>
      </form>
      <form method="post" action="/domains/php-settings" class="px-4 pb-4">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="domain" value="<?= $e($did) ?>">
        <input type="hidden" name="act" value="reset">
        <button class="btn-icon" title="Revert to the server default"
                onclick="return confirm('Restore PHP settings for <?= $e(addslashes((string)$dr['name'])) ?> to defaults?')">
          <i data-lucide="undo-2" class="h-4 w-4"></i>
        </button>
      </form>
    </details>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
