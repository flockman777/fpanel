<?php
/** Ganti password - action nyata, belum butuh backend hosting */
$err = $err ?? null; $ok = $ok ?? null;
?>
<section class="card">
  <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
    <span class="grid h-7 w-7 place-items-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-300">
      <i data-lucide="key-round" class="h-4 w-4"></i></span>
    <h2 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Ganti Password</h2>
  </div>
  <form method="post" action="/account/password" class="space-y-3 px-4 py-4">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <?php if ($err): ?>
    <p class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300"><?= htmlspecialchars($err) ?></p>
    <?php elseif ($ok): ?>
    <p class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300"><?= htmlspecialchars($ok) ?></p>
    <?php endif; ?>
    <div class="grid gap-3 sm:grid-cols-3">
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Password saat ini</span>
        <input type="password" name="current_password" required autocomplete="current-password" class="input">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Password baru</span>
        <input type="password" name="new_password" required minlength="8" autocomplete="new-password" class="input">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-slate-600 dark:text-slate-400">Ulangi password baru</span>
        <input type="password" name="confirm_password" required minlength="8" autocomplete="new-password" class="input">
      </label>
    </div>
    <button type="submit" class="btn-primary">
      <i data-lucide="check" class="h-4 w-4"></i>Simpan Password
    </button>
  </form>
</section>
