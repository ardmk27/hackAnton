<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/trends.php';
require_once __DIR__ . '/includes/charts.php';

$currentPosyandu = $p = require_login();
$pdo = db();
$pid = (int)$p['id'];

$periods = confirmed_periods($pdo, $pid);
$period = (string)($_GET['periode'] ?? '');
if (!in_array($period, $periods, true)) {
    $period = $periods[0] ?? '';
}
$level = (string)($_GET['level'] ?? '');
if (!in_array($level, ['prioritas', 'cek'], true)) {
    $level = '';
}

$roster = load_roster($pdo, $pid);
$dupSet = duplicate_name_set($roster);
$att = attention_for_period($roster, $period !== '' ? $period : null);
$items = array_values(array_filter($att['items'], fn($it) => $level === '' || $it['signal']['level'] === $level));
$noRecord = max(0, count($roster) - $att['measured']);

$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['periode' => $period, 'level' => $level], $over), fn($v) => $v !== ''));

$pageTitle = 'Perlu perhatian';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
  <div>
    <h1 class="page-title h4 mb-1">Perlu perhatian</h1>
    <p class="muted mb-0">Anak yang berat badannya tidak naik, untuk ditinjau Bidan atau petugas gizi.</p>
  </div>
  <?php if ($periods): ?>
  <form method="get" class="d-flex align-items-end gap-2">
    <input type="hidden" name="level" value="<?= e($level) ?>">
    <div>
      <label class="form-label mb-1" for="periode">Periode</label>
      <select class="form-select" id="periode" name="periode" onchange="this.form.submit()">
        <?php foreach ($periods as $per): ?><option value="<?= e($per) ?>" <?= $per === $period ? 'selected' : '' ?>><?= e(format_period($per)) ?></option><?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-quiet">Terapkan</button></noscript>
    <button type="button" class="btn btn-quiet" onclick="window.print()"><?= icon('printer', 18) ?>Cetak</button>
  </form>
  <?php endif; ?>
</div>

<?php if (!$periods): ?>
  <div class="kcf-card"><div class="kcf-empty">Belum ada data terkonfirmasi. Konfirmasi satu sesi lalu kembali ke sini.</div></div>
<?php else: ?>
  <div class="kcf-seg mb-3" role="group" aria-label="Saring menurut tingkat">
    <a class="<?= $level === '' ? 'is-on' : '' ?>" href="<?= e($qs(['level' => ''])) ?>">Semua (<?= (int)$att['not_rising'] ?>)</a>
    <a class="<?= $level === 'prioritas' ? 'is-on' : '' ?>" href="<?= e($qs(['level' => 'prioritas'])) ?>">Prioritas (<?= (int)$att['counts']['prioritas'] ?>)</a>
    <a class="<?= $level === 'cek' ? 'is-on' : '' ?>" href="<?= e($qs(['level' => 'cek'])) ?>">Cek (<?= (int)$att['counts']['cek'] ?>)</a>
  </div>

  <div class="kcf-card">
  <?php if (!$items): ?>
    <div class="kcf-empty">
      <?= icon('check', 22) ?>
      <div class="fw-bold mt-1">Tidak ada anak pada daftar ini.</div>
      <div class="small">Pada <?= e(format_period($period)) ?>: <?= (int)$att['measured'] ?> anak ditimbang, <?= (int)$att['rising'] ?> naik, <?= (int)$att['no_prev'] ?> belum punya catatan pembanding.</div>
    </div>
  <?php else: foreach ($items as $it): $sg = $it['signal']; $pe = $it['peserta']; ?>
    <article class="kcf-attn">
      <div class="kcf-attn-head">
        <div>
          <a class="kcf-attn-name" href="anak_detail.php?id=<?= (int)$pe['id'] ?>&amp;periode=<?= e($period) ?>"><?= e($pe['name']) ?></a>
          <div class="muted small"><?= $it['age'] !== null ? (int)$it['age'] . ' bulan' : 'umur belum diketahui' ?><?= $pe['ref'] ? ', no. daftar ' . e($pe['ref']) : '' ?><?= isset($dupSet[normalize_name($pe['name'])]) ? ', nama sama dengan anak lain (lahir ' . e($pe['dob'] ? format_date($pe['dob']) : '?') . ')' : '' ?></div>
        </div>
        <?= $sg['level'] === 'prioritas' ? pill('Prioritas', 'strong', 'alert') : pill('Cek', 'warn', 'info') ?>
      </div>
      <div class="kcf-reason is-<?= e($sg['level']) ?>">
        <div class="title"><?= e($sg['reason']) ?></div>
        <div class="series">
          <?php foreach ($sg['chain'] as $c): ?><span><?= e(short_period($c['period'])) ?> <strong><?= e(fmt_kg($c['w'])) ?></strong></span><?php endforeach; ?>
          <span class="muted">kg</span>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <a class="btn btn-brand btn-sm" href="anak_detail.php?id=<?= (int)$pe['id'] ?>&amp;periode=<?= e($period) ?>">Lihat tren</a>
        <a class="btn btn-quiet btn-sm" href="anak_detail.php?id=<?= (int)$pe['id'] ?>&amp;periode=<?= e($period) ?>#pesan">Draf pesan</a>
      </div>
    </article>
  <?php endforeach; endif; ?>
  </div>

  <div class="kcf-card mt-3"><div class="kcf-card-body">
    <h2 class="h6 mb-2">Cara penanda dihitung</h2>
    <p class="mb-2 small"><?= e(ATTENTION_RULE_TEXT) ?></p>
    <ul class="small muted mb-0 ps-3">
      <li>Dihitung hanya dari data <strong>terkonfirmasi</strong>.</li>
      <li><?= (int)$noRecord ?> anak di daftar tidak punya catatan pada <?= e(format_period($period)) ?>. Itu <strong>tidak</strong> berarti mereka tidak hadir.</li>
      <li>Penanda membantu menentukan urutan kunjungan. Ini bukan diagnosis; keputusan tetap di tenaga kesehatan. Aturan perlu divalidasi bersama Puskesmas.</li>
    </ul>
  </div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
