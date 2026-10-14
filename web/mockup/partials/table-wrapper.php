<?php /* Wrapper tabel responsif: $empty pesan kalau data kosong */ ?>
<div class="card overflow-hidden">
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><?php foreach (($cols ?? []) as $c): ?>
        <th class="<?= htmlspecialchars($c['cls'] ?? '') ?>"><?= htmlspecialchars($c['label']) ?></th>
      <?php endforeach; ?>
      <th class="w-px text-right"><?= htmlspecialchars($actionsLabel ?? 'Aksi') ?></th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="<?= count($cols ?? []) + 1 ?>" class="py-10 text-center text-sm text-slate-500" data-i18n="<?= htmlspecialchars($emptyKey ?? 'common.noData') ?>"><?= htmlspecialchars($emptyText ?? 'Belum ada data') ?></td></tr>
      <?php endif; ?>
      <?php foreach (($rows ?? []) as $r): ?><?php $row = $r; ?>
        <tr><?php foreach (($cols ?? []) as $c): ?>
          <td class="<?= htmlspecialchars($c['cls'] ?? '') ?>"><?= $c['render']($row) ?></td>
        <?php endforeach; ?>
        <td class="text-right whitespace-nowrap">
          <div class="inline-flex items-center gap-1">
            <button class="btn-icon" :title="$store.i18n.t('common.edit')"><i data-lucide="pencil" class="h-4 w-4"></i></button>
            <button class="btn-icon hover:!bg-rose-50 hover:!text-rose-600 dark:hover:!bg-rose-500/10" :title="$store.i18n.t('common.delete')"><i data-lucide="trash" class="h-4 w-4"></i></button>
          </div>
        </td>
      </tr><?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
