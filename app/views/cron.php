<?php
/**
 * Cron Jobs — list of the user's cron jobs.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $jobs       the user's jobs
 * @var array $presets    ready-made schedules
 * @var int $logJobId     job whose log is shown, 0 = none
 * @var string $logText
 * @var array|null $edit  job currently being edited
 * @var string|null $error, string|null $ok
 */
use Fpanel\Service\Cron;

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$jobs      = (array)($jobs ?? []);
$presets   = (array)($presets ?? []);
$logJobId  = (int)($logJobId ?? 0);
$logText   = (string)($logText ?? '');
$edit      = (array)($edit ?? []);
$editId    = (int)($edit['id'] ?? 0);
$isEdit    = $editId > 0;

$form = [
    'name'     => $e((string)($edit['name'] ?? '')),
    'command'  => $e((string)($edit['command'] ?? '')),
    'schedule' => $e((string)($edit['schedule'] ?? '*/5 * * * *')),
];
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-5xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="timer" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">Cron Jobs</h1>
        <p class="mt-0.5 text-sm text-slate-500">Schedule commands to run automatically. Every command runs as the web user.</p>
      </div>
      <span class="rounded bg-slate-100 px-2 py-1 text-xs font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300">
        <?= $e(count($jobs)) ?>/<?= $e(Cron::MAX_JOBS) ?> job
      </span>
    </div>

    <?php foreach ([['error', 'rose', 'alert-circle'], ['ok', 'emerald', 'check-circle-2']] as [$k, $tone, $icon]): ?>
      <?php if ((${$k} ?? '') !== ''): ?>
        <div class="flex items-start gap-2 rounded-xl border border-<?= $tone ?>-200 bg-<?= $tone ?>-50 px-4 py-3 text-sm text-<?= $tone ?>-800 dark:border-<?= $tone ?>-500/30 dark:bg-<?= $tone ?>-500/10 dark:text-<?= $tone ?>-200">
          <i data-lucide="<?= $icon ?>" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= $e((string)${$k}) ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <!-- ===== add / edit form ===== -->
    <section class="card p-4">
      <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-slate-100">
        <?= $isEdit ? 'Edit Cron Job' : 'Add Cron Job' ?>
      </h2>
      <form method="post" action="<?= $isEdit ? '/cron/update' : '/cron/create' ?>" class="space-y-3">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= $e($editId) ?>">
        <?php endif; ?>

        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Job name</span>
          <input name="name" value="<?= $form['name'] ?>" required maxlength="100" placeholder="Example: clear cache"
                 class="input h-9 w-full text-sm">
        </label>

        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Command</span>
          <input name="command" value="<?= $form['command'] ?>" required maxlength="1000"
                 placeholder="Example: php /var/www/user/domain/public/artisan schedule:run"
                 class="input h-9 w-full font-mono text-xs">
          <span class="mt-1 block text-[11px] text-slate-500">
            Runs as <code class="font-mono">www-data</code>. Output and the exit code go to each job's log.
          </span>
        </label>

        <label class="block">
          <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Jadwal</span>
          <div class="flex flex-wrap items-center gap-2">
            <input name="schedule" value="<?= $form['schedule'] ?>" required
                   class="input h-9 w-48 font-mono text-xs">
            <?php if ($presets !== []): ?>
              <select class="input h-9 w-48 text-xs" onchange="if(this.value){this.form.schedule.value=this.value;this.value='';}">
                <option value="">Preset…</option>
                <?php foreach ($presets as $label => $expr): ?>
                  <option value="<?= $e((string)$expr) ?>"><?= $e((string)$label) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <span class="mt-1 block text-[11px] text-slate-500">Format: minute hour day-of-month month day-of-week (UTC).</span>
        </label>

        <div class="flex flex-wrap items-center gap-2">
          <button class="btn-primary h-9 px-4 text-sm">
            <i data-lucide="<?= $isEdit ? 'save' : 'plus' ?>" class="h-4 w-4"></i>
            <span><?= $isEdit ? 'Save Changes' : 'Add Cron Job' ?></span>
          </button>
          <?php if ($isEdit): ?>
            <a href="/cron" class="btn-icon" title="Cancel"><i data-lucide="x" class="h-4 w-4"></i></a>
          <?php endif; ?>
        </div>
      </form>
    </section>

    <!-- ===== job list ===== -->
    <section class="card overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800">
              <th class="px-4 py-2.5 font-semibold">Job</th>
              <th class="px-4 py-2.5 font-semibold">Schedule</th>
              <th class="px-4 py-2.5 font-semibold">Last run</th>
              <th class="px-4 py-2.5 font-semibold">Status</th>
              <th class="px-4 py-2.5 text-right font-semibold">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($jobs as $j):
              $id   = (int)$j['id'];
              $on   = (int)$j['enabled'] === 1;
              $next = Cron::nextRun((string)$j['schedule'], (string)($j['last_run'] ?? null) ?: null);
              $last = trim((string)($j['last_run'] ?? ''));
              $exit = $j['last_exit'] === null ? '' : (int)$j['last_exit'];
            ?>
            <tr class="align-top hover:bg-slate-50 dark:hover:bg-slate-800/50">
              <td class="px-4 py-3">
                <div class="font-medium text-slate-900 dark:text-slate-100"><?= $e((string)$j['name']) ?></div>
                <code class="mt-0.5 block max-w-[26rem] truncate font-mono text-[11px] text-slate-500"><?= $e((string)$j['command']) ?></code>
              </td>
              <td class="px-4 py-3">
                <code class="font-mono text-xs"><?= $e((string)$j['schedule']) ?></code>
                <?php if ($on && $next !== null): ?>
                  <div class="mt-0.5 text-[11px] text-slate-500">next <?= $e($next) ?> UTC</div>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3 text-xs">
                <?= $last === '' ? '<span class="text-slate-400">never</span>' : $e($last) . ' UTC' ?>
              </td>
              <td class="px-4 py-3">
                <?php if (!$on): ?>
                  <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300">inactive</span>
                <?php elseif ($exit !== ''): ?>
                  <span class="rounded <?= $exit === 0 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' ?> px-1.5 py-0.5 text-[11px] font-medium">
                    exit <?= $e($exit) ?>
                  </span>
                <?php else: ?>
                  <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">active</span>
                <?php endif; ?>
              </td>
              <td class="px-4 py-3">
                <div class="flex items-center justify-end gap-1">
                  <form method="post" action="/cron/run" class="inline">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= $e($id) ?>">
                    <button class="btn-icon" title="Run now"><i data-lucide="play" class="h-4 w-4"></i></button>
                  </form>
                  <a href="/cron?log=<?= $e($id) ?>#log" class="btn-icon" title="View log"><i data-lucide="scroll-text" class="h-4 w-4"></i></a>
                  <a href="/cron?edit=<?= $e($id) ?>" class="btn-icon" title="Edit"><i data-lucide="pencil" class="h-4 w-4"></i></a>
                  <form method="post" action="/cron/toggle" class="inline">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= $e($id) ?>">
                    <button class="btn-icon" title="<?= $on ? 'Disable' : 'Enable' ?>">
                      <i data-lucide="<?= $on ? 'pause' : 'play-circle' ?>" class="h-4 w-4"></i>
                    </button>
                  </form>
                  <form method="post" action="/cron/delete" class="inline"
                        onsubmit="return confirm('Delete this cron job?')">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= $e($id) ?>">
                    <button class="btn-icon text-rose-600 hover:text-rose-700 dark:text-rose-400" title="Delete">
                      <i data-lucide="trash-2" class="h-4 w-4"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php if ($jobs === []): ?>
              <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500">No cron jobs yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ===== log ===== -->
    <?php if ($logJobId > 0): ?>
      <section class="card overflow-hidden" id="log">
        <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Job log #<?= $e($logJobId) ?></h2>
          <a href="/cron" class="btn-icon ml-auto" title="Close"><i data-lucide="x" class="h-4 w-4"></i></a>
        </div>
        <?php if ($logText === ''): ?>
          <p class="p-8 text-center text-sm text-slate-500">No output for this job yet.</p>
        <?php else: ?>
          <pre class="max-h-96 overflow-auto p-4 text-[11px] leading-relaxed text-slate-700 dark:text-slate-300"><?= $e($logText) ?></pre>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <p class="text-xs text-slate-500">
      The command runs as <code class="font-mono">www-data</code> with a 5-minute timeout.
      Each job is limited to 50 lines; logs are truncated to 256 KB per job.
    </p>
  </div>
</main>
</body>
</html>