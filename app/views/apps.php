<?php
/**
 * Per-domain runtime apps: Node.js and Python.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $data   result of AppRuntime::listing()
 * @var array $ready  result of AppRuntime::managersReady()
 * @var array $quota
 * @var string|null $error, string|null $ok, string|null $log, string|null $logFor
 */
use Fpanel\Service\AppRuntime;

$e     = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$data  = (array)($data ?? []);
$apps  = (array)($data['apps'] ?? []);
$ready = (array)($ready ?? []);
$dOk   = !empty($data['ok']);
$err   = ($dOk ? '' : (string)($data['error'] ?? 'Failed to load the app list.'));
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-6xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="box" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">App Runtime</h1>
        <p class="mt-0.5 text-sm text-slate-500">
          Node.js <strong><?= $e((string)($data['node'] ?? '-')) ?></strong> &middot;
          Python <strong><?= $e((string)($data['python'] ?? '-')) ?></strong>
          &middot; <strong><?= $e(count($apps)) ?></strong> app(s) running under systemd
        </p>
      </div>
      <a href="/apps" class="btn-icon" title="Reload"><i data-lucide="refresh-cw" class="h-4 w-4"></i></a>
    </div>

    <?php foreach ([['error', 'rose', 'alert-circle'], ['ok', 'emerald', 'check-circle-2']] as [$key, $tone, $icon]): ?>
      <?php if ((${$key} ?? '') !== ''): ?>
        <div class="flex items-start gap-2 rounded-xl border border-<?= $tone ?>-200 bg-<?= $tone ?>-50 px-4 py-3 text-sm text-<?= $tone ?>-800 dark:border-<?= $tone ?>-500/30 dark:bg-<?= $tone ?>-500/10 dark:text-<?= $tone ?>-200">
          <i data-lucide="<?= $icon ?>" class="mt-0.5 h-4 w-4 shrink-0"></i>
          <span class="min-w-0 whitespace-pre-wrap"><?= $e((string)${$key}) ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <?php if (empty($ready['helper'])): ?>
      <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
        <i data-lucide="alert-triangle" class="mt-0.5 h-4 w-4 shrink-0"></i>
        <span>The app runtime helper is not installed, so apps cannot be created or started.</span>
      </div>
    <?php endif; ?>

    <?php if (($log ?? '') !== ''): ?>
      <section class="card overflow-hidden">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2.5 dark:border-slate-800">
          <i data-lucide="scroll-text" class="h-4 w-4 text-slate-500"></i>
          <span class="text-sm font-semibold text-slate-900 dark:text-slate-100">Log &mdash; <?= $e((string)($logFor ?? '')) ?></span>
          <a href="/apps" class="btn-icon ml-auto" title="Close"><i data-lucide="x" class="h-4 w-4"></i></a>
        </div>
        <pre class="max-h-80 overflow-auto px-4 py-3 text-[12px] leading-relaxed text-slate-700 dark:text-slate-300"><?= $e((string)$log) ?></pre>
      </section>
    <?php endif; ?>

    <?php if (!$dOk): ?>
      <section class="card p-10 text-center">
        <i data-lucide="server-off" class="mx-auto h-8 w-8 text-slate-300"></i>
        <p class="mt-3 text-sm text-slate-500"><?= $e($err) ?></p>
      </section>
    <?php else: ?>

    <!-- App list -->
    <?php if ($apps === []): ?>
      <section class="card p-10 text-center">
        <i data-lucide="box" class="mx-auto h-8 w-8 text-slate-300"></i>
        <p class="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">No apps yet</p>
        <p class="mt-1 text-sm text-slate-500">Create a Node.js or Python domain first, then register its app below.</p>
      </section>
    <?php else: ?>
      <div class="space-y-3">
        <?php foreach ($apps as $a):
          $aid   = (int)$a['id'];
          $on    = ($a['status'] ?? '') === 'running';
          $kind  = (string)$a['kind'];
        ?>
        <section class="card overflow-hidden">
          <div class="flex flex-wrap items-center gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
            <i data-lucide="<?= $kind === 'node' ? 'hexagon' : 'file-code-2' ?>" class="h-4 w-4 shrink-0 text-brand-600 dark:text-brand-400"></i>
            <div class="min-w-0 flex-1">
              <p class="truncate font-mono text-sm font-semibold text-slate-900 dark:text-slate-100">
                <?= $e((string)$a['name']) ?>
                <span class="ml-1 font-sans text-[11px] font-normal text-slate-500"><?= $kind === 'node' ? 'Node.js' : 'Python' ?></span>
              </p>
              <p class="truncate text-xs text-slate-500">
                <?= $e((string)$a['root']) ?>/<?= $e((string)$a['entry']) ?>
                &middot; port <?= $e((string)$a['port']) ?>
                <?php if (($a['domain_name'] ?? '') !== ''): ?>
                  &middot; <span class="font-mono"><?= $e((string)$a['domain_name']) ?></span>
                <?php endif; ?>
              </p>
            </div>
            <?php if ($on): ?>
              <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                <i data-lucide="check" class="h-3 w-3"></i>running
              </span>
            <?php else: ?>
              <span class="inline-flex items-center gap-1 rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                <i data-lucide="minus" class="h-3 w-3"></i>stopped
              </span>
            <?php endif; ?>

            <div class="flex items-center gap-1">
              <form method="post" action="/apps/action" class="shrink-0">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="app" value="<?= $e($aid) ?>">
                <input type="hidden" name="act" value="<?= $on ? 'stop' : 'start' ?>">
                <button class="btn-icon" title="<?= $on ? 'Stop app' : 'Start app' ?>">
                  <i data-lucide="<?= $on ? 'square' : 'play' ?>" class="h-4 w-4"></i>
                </button>
              </form>
              <form method="post" action="/apps/action" class="shrink-0">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="app" value="<?= $e($aid) ?>">
                <input type="hidden" name="act" value="restart">
                <button class="btn-icon" title="Restart app"><i data-lucide="rotate-cw" class="h-4 w-4"></i></button>
              </form>
              <form method="post" action="/apps/logs" class="shrink-0">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="app" value="<?= $e($aid) ?>">
                <button class="btn-icon" title="View log"><i data-lucide="scroll-text" class="h-4 w-4"></i></button>
              </form>
              <form method="post" action="/apps/delete" class="shrink-0"
                    onsubmit="return confirm('Remove app <?= $e(addslashes((string)$a['name'])) ?>? Files in the app folder are not deleted.')">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="app" value="<?= $e($aid) ?>">
                <button class="btn-icon hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-500/10" title="Remove app from the panel">
                  <i data-lucide="trash-2" class="h-4 w-4"></i>
                </button>
              </form>
            </div>
          </div>
        </section>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Add app form -->
    <?php
    // Domains no longer have a 'node'/'python' type. Every usable domain can
    // host a runtime app; what makes it Node or Python is the runtime
    // column in the form below, not the domain type.
    $usable = array_values(array_filter((array)($domains ?? []),
        static fn (array $d): bool => trim((string)($d['name'] ?? '')) !== ''));
    ?>
    <?php if ($usable !== []): ?>
    <section class="card p-4">
      <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Register app</h2>
        <p class="mt-0.5 mb-3 text-xs text-slate-500">
          The app runtime attaches to the selected domain; it stays a normal domain on
          the Domains page. Nginx routes requests to the app while it runs.
        </p>
      <form method="post" action="/apps/create" class="flex flex-wrap items-end gap-2">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Domain</span>
          <select name="domain" class="input h-9 w-44 text-sm" required>
            <?php foreach ($usable as $d): ?>
              <option value="<?= $e((string)$d['name']) ?>"><?= $e((string)$d['name']) ?> (<?= $e((string)$d['type']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">App name</span>
          <input name="name" placeholder="shop_api" pattern="[a-z0-9][a-z0-9_-]{1,30}" maxlength="31"
                 class="input h-9 w-36 text-sm" required>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Folder (relative to the docroot)</span>
          <input name="root" placeholder="app" value="app" class="input h-9 w-40 text-sm" required>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Entry</span>
          <input name="entry" placeholder="server.js" class="input h-9 w-36 text-sm" required>
        </label>
        <button class="btn-primary h-9 px-3 text-sm"><i data-lucide="plus" class="h-4 w-4"></i><span>Register</span></button>
      </form>
      <p class="mt-2 text-xs text-slate-500">
        The app folder must already exist in the domain docroot. For Node.js, add a
        <code>package.json</code> and run <code>npm install</code> before registering.
        The port is allocated automatically by the panel.
      </p>
    </section>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</main>
</body>
</html>