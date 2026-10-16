<?php
/**
 * Security — SSL certificates and two-factor authentication.
 *
 * @var string $csrf
 * @var array $me, array $s
 * @var array $certs
 * @var bool $totpEnabled
 * @var string|null $totpSecret secret awaiting confirmation
 * @var string|null $totpUri
 * @var array $recovery
 * @var int $recoveryLeft
 * @var string|null $totpNotice
 * @var string|null $error, string|null $ok
 * @var array $devices
 * @var int $currentDevice
 * @var string|null $loginLink, $loginLinkPath
 */
use Fpanel\Service\Totp;

$e = static fn (mixed $v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$certs    = (array)($certs ?? []);
$enabled  = (bool)($totpEnabled ?? false);
$secret   = (string)($totpSecret ?? '');
$uri      = (string)($totpUri ?? '');
$recovery = (array)($recovery ?? []);
$left     = (int)($recoveryLeft ?? 0);
$devices  = (array)($devices ?? []);
$current  = (int)($currentDevice ?? 0);
$link     = (string)($loginLink ?? '');
$linkPath = (string)($loginLinkPath ?? '');
?>
<?php require __DIR__ . '/layout/head.php'; ?>
<?php require __DIR__ . '/layout/topbar.php'; ?>
<?php require __DIR__ . '/layout/nav.php'; ?>

<main class="px-4 py-5 sm:px-6 lg:pl-[264px]">
  <div class="mx-auto max-w-4xl space-y-4">

    <div class="flex flex-wrap items-start gap-3">
      <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
        <i data-lucide="shield" class="h-5 w-5"></i>
      </span>
      <div class="min-w-0 flex-1">
        <h1 class="text-xl font-bold tracking-tight text-slate-900 dark:text-slate-50 sm:text-2xl">Security</h1>
        <p class="mt-0.5 text-sm text-slate-500">SSL certificates and two-factor authentication for your account.</p>
      </div>
    </div>

    <?php if (!empty($totpNotice)): ?>
      <div class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
        <i data-lucide="triangle-alert" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= $e((string)$totpNotice) ?></span>
      </div>
    <?php endif; ?>

    <?php foreach ([['error', 'rose', 'alert-circle'], ['ok', 'emerald', 'check-circle-2']] as [$k, $tone, $icon]): ?>
      <?php if ((${$k} ?? '') !== ''): ?>
        <div class="flex items-start gap-2 rounded-xl border border-<?= $tone ?>-200 bg-<?= $tone ?>-50 px-4 py-3 text-sm text-<?= $tone ?>-800 dark:border-<?= $tone ?>-500/30 dark:bg-<?= $tone ?>-500/10 dark:text-<?= $tone ?>-200">
          <i data-lucide="<?= $icon ?>" class="mt-0.5 h-4 w-4 shrink-0"></i><span><?= $e((string)${$k}) ?></span>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>

    <!-- ====== 2FA ====== -->
    <section class="card overflow-hidden">
      <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Two-Factor Authentication (2FA)</h2>
        <?php if ($enabled): ?>
          <span class="rounded bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">active</span>
        <?php else: ?>
          <span class="rounded bg-slate-100 px-2 py-0.5 text-[11px] font-medium text-slate-600 dark:bg-slate-700 dark:text-slate-300">inactive</span>
        <?php endif; ?>
        <?php if ($enabled && $left > 0): ?>
          <span class="ml-auto text-[11px] text-slate-500"><?= $e($left) ?> recovery codes left</span>
        <?php endif; ?>
      </div>

      <div class="p-4">
        <?php if ($enabled): ?>
          <p class="text-sm text-slate-600 dark:text-slate-300">
            Login requires a 6-digit code from your authenticator app after the password.
          </p>

          <?php if ($recovery !== []): ?>
            <div class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
              <p class="flex items-center gap-2 text-sm font-medium text-amber-900 dark:text-amber-100">
                <i data-lucide="triangle-alert" class="h-4 w-4"></i>
                Save these recovery codes now
              </p>
              <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">
                Shown only once. Keep them in case you lose your phone. Each code works only once.
              </p>
              <ul class="mt-3 grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                <?php foreach ($recovery as $c): ?>
                  <li class="rounded bg-white/70 px-2 py-1 text-center font-mono text-xs text-amber-900 dark:bg-black/20 dark:text-amber-100"><?= $e($c) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <div class="mt-4 flex flex-wrap gap-2">
            <form method="post" action="/security/2fa/recovery" class="inline">
              <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
              <button class="btn-secondary h-9 px-3 text-sm"><i data-lucide="refresh-cw" class="h-4 w-4"></i><span>New recovery codes</span></button>
            </form>
            <form method="post" action="/security/2fa/disable" class="inline" id="form-disable">
              <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
              <input type="hidden" name="password" id="pw-disable">
              <button type="button" class="btn-icon text-rose-600 hover:text-rose-700 dark:text-rose-400"
                      title="Disable 2FA" id="btn-disable">
                <i data-lucide="shield-off" class="h-4 w-4"></i>
              </button>
            </form>
          </div>
          <p class="mt-2 text-xs text-slate-500">
            To disable 2FA, enter your account password and click the icon above.
          </p>

        <?php elseif ($secret !== ''): ?>
          <!-- ====== Setup not finished ====== -->
          <p class="text-sm text-slate-600 dark:text-slate-300">
            Scan this QR code with your authenticator app, then enter the code it shows to activate.
          </p>
          <div class="mt-4 flex flex-wrap items-start gap-5">
            <div class="rounded-xl border border-slate-200 bg-white p-3 dark:border-slate-700 dark:bg-slate-900">
              <img src="/security/2fa/qr.svg" alt="QR code 2FA" width="200" height="200" class="h-[200px] w-[200px]">
            </div>
            <div class="min-w-0 flex-1 space-y-3">
              <div>
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400">Or enter it manually</p>
                <div class="mt-1 flex items-center gap-2">
                  <code class="min-w-0 flex-1 break-all rounded bg-slate-100 px-3 py-2 font-mono text-sm dark:bg-slate-800"><?= $e($secret) ?></code>
                  <button type="button" class="btn-icon" title="Copy" data-copy="<?= $e($secret) ?>">
                    <i data-lucide="copy" class="h-4 w-4"></i>
                  </button>
                </div>
                <p class="mt-1 text-[11px] text-slate-500">Issuer: <?= $e(Totp::ISSUER) ?></p>
              </div>
              <form method="post" action="/security/2fa/activate" class="flex flex-wrap items-end gap-2">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <label class="block">
                  <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">6-digit code</span>
                  <input name="code" required inputmode="numeric" maxlength="6" placeholder="000000"
                         class="input h-9 w-40 text-center font-mono tracking-widest">
                </label>
                <button class="btn-primary h-9 px-4 text-sm"><i data-lucide="check" class="h-4 w-4"></i><span>Activate</span></button>
              </form>
            </div>
          </div>

        <?php else: ?>
          <p class="text-sm text-slate-600 dark:text-slate-300">
            Add a second layer: if your password leaks, the account stays safe even without your phone.
          </p>
          <form method="post" action="/security/2fa/setup" class="mt-3">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="btn-primary h-9 px-4 text-sm"><i data-lucide="smartphone" class="h-4 w-4"></i><span>Set up 2FA</span></button>
          </form>
        <?php endif; ?>
      </div>
    </section>

    <!-- ====== Remembered devices ====== -->
    <section class="card overflow-hidden">
      <div class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">Remembered Devices</h2>
        <?php if ($devices !== []): ?>
          <span class="ml-auto text-[11px] text-slate-500"><?= count($devices) ?> devices</span>
        <?php endif; ?>
      </div>

      <div class="p-4">
        <?php if ($link !== '' && $linkPath !== ''): ?>
          <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <p class="flex items-center gap-2 text-sm font-medium text-emerald-900 dark:text-emerald-100">
              <i data-lucide="link" class="h-4 w-4"></i>
              One-time login link
            </p>
            <p class="mt-1 text-xs text-emerald-800 dark:text-emerald-200">
              Valid for 15 minutes and expires once used. Close this page after copying;
              the link will not be shown again.
            </p>
            <div class="mt-3 flex items-center gap-2">
              <code class="min-w-0 flex-1 break-all rounded bg-white/70 px-3 py-2 font-mono text-xs text-emerald-900 dark:bg-black/20 dark:text-emerald-100"><?= $e($linkPath) ?></code>
              <button type="button" class="btn-icon" title="Copy" data-copy="<?= $e($linkPath) ?>">
                <i data-lucide="copy" class="h-4 w-4"></i>
              </button>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($devices === []): ?>
          <p class="text-sm text-slate-600 dark:text-slate-300">
            No remembered devices yet. Tick <strong>Remember this device</strong> on the
            login page to skip logging in again on that device.
          </p>
        <?php else: ?>
          <p class="mb-3 text-xs text-slate-500">
            Device cookies are separate from login sessions and can be revoked per device here.
          </p>
          <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
            <?php foreach ($devices as $d): ?>
              <li class="flex flex-wrap items-center gap-3 px-3 py-2.5">
                <i data-lucide="monitor-smartphone" class="h-4 w-4 shrink-0 text-slate-400"></i>
                <div class="min-w-0 flex-1">
                  <p class="text-sm font-medium text-slate-800 dark:text-slate-100">
                    <?= $e((string)$d['label']) ?>
                    <?php if ((int)$d['id'] === $current): ?>
                      <span class="ml-1 rounded bg-brand-50 px-1.5 py-0.5 text-[11px] font-medium text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">this device</span>
                    <?php endif; ?>
                  </p>
                  <p class="text-[11px] text-slate-500">
                    IP <?= $e((string)$d['ip']) ?> &middot; last used
                    <?= $e(substr((string)$d['last_used_at'], 0, 16)) ?> UTC
                  </p>
                </div>
                <form method="post" action="/security/devices/revoke" class="shrink-0">
                  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button class="btn-icon text-rose-600 hover:text-rose-700 dark:text-rose-400" title="Revoke this device">
                    <i data-lucide="trash-2" class="h-4 w-4"></i>
                  </button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
          <form method="post" action="/security/devices/revoke-all" class="mt-3"
                onsubmit="return confirm('Revoke all devices, including this one?')">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <button class="btn-secondary h-9 px-3 text-sm">
              <i data-lucide="log-out" class="h-4 w-4"></i><span>Revoke all devices</span>
            </button>
          </form>
        <?php endif; ?>

        <form method="post" action="/security/login-link" class="mt-4 border-t border-slate-100 pt-4 dark:border-slate-800">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <button class="btn-secondary h-9 px-3 text-sm">
            <i data-lucide="link-2" class="h-4 w-4"></i><span>Create a one-time login link</span>
          </button>
          <p class="mt-1.5 text-xs text-slate-500">
            To sign in from another browser or device without typing a password.
          </p>
        </form>
      </div>
    </section>

    <!-- ====== SSL ====== -->
    <section class="card overflow-hidden">
      <div class="border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <h2 class="text-sm font-semibold text-slate-900 dark:text-slate-100">SSL/TLS Certificates</h2>
      </div>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800">
              <th class="px-4 py-2.5 font-semibold">Domain</th>
              <th class="px-4 py-2.5 font-semibold">Provider</th>
              <th class="px-4 py-2.5 font-semibold">Status</th>
              <th class="px-4 py-2.5 font-semibold">Expires</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($certs as $c): ?>
              <tr>
                <td class="px-4 py-2.5 font-medium"><?= $e((string)($c['domain'] ?? '')) ?></td>
                <td class="px-4 py-2.5 text-xs text-slate-500"><?= $e((string)($c['provider'] ?? '')) ?></td>
                <td class="px-4 py-2.5">
                  <span class="rounded px-1.5 py-0.5 text-[11px] font-medium <?= ($c['status'] ?? '') === 'active'
                      ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'
                      : 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' ?>">
                    <?= $e((string)($c['status'] ?? '')) ?>
                  </span>
                </td>
                <td class="px-4 py-2.5 text-xs text-slate-500"><?= $e((string)($c['expires_at'] ?? '-')) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if ($certs === []): ?>
              <tr><td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">No certificates yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</main>
<script>
  // Turning off 2FA asks for the account password, then confirms.
  var pwField = document.getElementById('pw-disable');
  var frm = document.getElementById('form-disable');
  var btn = document.getElementById('btn-disable');
  if (pwField && frm && btn) {
    btn.addEventListener('click', function () {
      var v = window.prompt('Account password to disable 2FA:');
      if (v === null) { return; }
      if (!v) { window.alert('Password is required.'); return; }
      if (!window.confirm('Disable 2FA? After this the account is protected by password only.')) { return; }
      pwField.value = v;
      frm.submit();
    });
  }
  // Copy the base32 secret with a single click.
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = b.getAttribute('data-copy') || '';
      navigator.clipboard.writeText(t).then(function () {
        var i = b.querySelector('svg');
        if (i) i.classList.add('opacity-50');
        setTimeout(function () { if (i) i.classList.remove('opacity-50'); }, 900);
      });
    });
  });
</script>
</body>
</html>
