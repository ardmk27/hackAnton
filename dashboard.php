<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/trends.php';

$currentPosyandu = $p = require_login();
$pdo = db();
$pid = (int)$p['id'];

$q = function (string $sql, array $params) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return (int)$st->fetchColumn();
};

$sesiBulanIni = $q("SELECT COUNT(*) FROM sesi_foto WHERE posyandu_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')", [$pid]);
$catatanConfirmed = $q("SELECT COUNT(*) FROM pengukuran WHERE posyandu_id = ? AND verification_status = 'confirmed'", [$pid]);
$perluDiperiksa = $q("SELECT COUNT(*) FROM sesi_foto WHERE posyandu_id = ? AND status IN ('uploaded','processing','review','failed')", [$pid]);
$totalSesi = $q('SELECT COUNT(*) FROM sesi_foto WHERE posyandu_id = ?', [$pid]);

// Seberapa sering kader harus mengoreksi hasil baca AI (hanya baris yang berasal dari pembacaan AI/OCR).
$st = $pdo->prepare("SELECT COUNT(*) AS total, SUM(corrected_fields IS NOT NULL) AS corrected FROM pengukuran WHERE posyandu_id = ? AND verification_status = 'confirmed' AND ocr_snapshot IS NOT NULL");
$st->execute([$pid]);
$corr = $st->fetch() ?: ['total' => 0, 'corrected' => 0];

$roster = load_roster($pdo, $pid);
$dupSet = duplicate_name_set($roster);
$period = latest_confirmed_period($pdo, $pid);
$att = attention_for_period($roster, $period);

$st = $pdo->prepare(
    'SELECT s.id, s.activity_date, s.created_at, s.status, s.ocr_engine,
            (SELECT COUNT(*) FROM pengukuran m WHERE m.sesi_foto_id = s.id) AS row_count
     FROM sesi_foto s WHERE s.posyandu_id = ? ORDER BY s.created_at DESC, s.id DESC LIMIT 5'
);
$st->execute([$pid]);
$recent = $st->fetchAll();

$canSeed = env('DEMO_SEED_ENABLED', 'false') === 'true' && $totalSesi === 0;

$pageTitle = 'Beranda';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h1 class="page-title h4 mb-1"><?= e($p['name']) ?></h1>
    <span class="muted small">ID Posyandu: <code><?= e($p['posyandu_code']) ?></code></span>
  </div>
  <?php if ($totalSesi > 0): ?><a href="upload.php" class="btn btn-brand btn-lg"><?= icon('camera', 22) ?>Foto halaman register</a><?php endif; ?>
</div>

<?php if ($totalSesi === 0): ?>
  <section class="kcf-card mb-3" aria-labelledby="h-start">
    <div class="kcf-card-body">
      <h2 id="h-start" class="h5 mb-1">Mulai dari satu foto</h2>
      <p class="muted mb-3">Tiga langkah dari buku register ke daftar anak yang perlu dikunjungi.</p>
      <ol class="kcf-start">
        <li><span>1</span><div><strong>Foto halaman register</strong><br><span class="muted">Buka kamera atau pilih foto dari galeri.</span></div></li>
        <li><span>2</span><div><strong>Periksa sel yang ditandai</strong><br><span class="muted">Yang yakin tidak perlu dibuka satu per satu.</span></div></li>
        <li><span>3</span><div><strong>Lihat Perhatian dan Rekap</strong><br><span class="muted">Muncul setelah dua bulan data terkonfirmasi.</span></div></li>
      </ol>
      <a href="upload.php" class="btn btn-brand mt-2"><?= icon('camera', 18) ?>Foto halaman register pertama</a>
    </div>
  </section>
<?php endif; ?>

<?php if ($canSeed): ?>
  <div class="kcf-banner is-demo">
    <?= icon('flask', 20) ?>
    <div class="flex-grow-1">
      <strong>Belum ada data.</strong> Muat data demo (sintetis, 3 bulan) untuk mencoba semua fitur: tren, daftar perhatian, rekap, dan ekspor.
      <form method="post" action="demo_seed.php" class="mt-2 d-flex flex-wrap gap-2">
        <?= csrf_field() ?>
        <button class="btn btn-brand btn-sm" name="mode" value="story">Muat data demo (Oktober siap diperiksa)</button>
        <button class="btn btn-quiet btn-sm" name="mode" value="full">Muat data demo lengkap</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3 mb-2">
  <div class="col-6 col-md-4"><div class="kcf-card kcf-stat h-100"><div class="label">Sesi bulan ini</div><div class="value"><?= $sesiBulanIni ?></div></div></div>
  <div class="col-6 col-md-4"><div class="kcf-card kcf-stat h-100"><div class="label">Catatan terkonfirmasi</div><div class="value"><?= $catatanConfirmed ?></div></div></div>
  <div class="col-12 col-md-4"><div class="kcf-card kcf-stat h-100">
    <div class="label">Sesi perlu diperiksa</div><div class="value"><?= $perluDiperiksa ?></div>
    <?php if ($perluDiperiksa > 0): ?><a class="small fw-bold" href="history.php?status=review">Lihat sesi</a><?php endif; ?>
  </div></div>
</div>

<section class="kcf-section" aria-labelledby="h-attn">
  <div class="d-flex flex-wrap align-items-baseline justify-content-between gap-2 mb-2">
    <h2 id="h-attn" class="kcf-section-title mb-0">Perlu perhatian<?= $period ? ' bulan ini' : '' ?></h2>
    <?php if ($period): ?><span class="muted small"><?= e(format_period($period)) ?>, <?= (int)$att['measured'] ?> anak ditimbang</span><?php endif; ?>
  </div>
  <div class="kcf-card">
    <?php if (!$period): ?>
      <div class="kcf-empty">Belum ada data terkonfirmasi. Setelah sesi pertama dikonfirmasi, anak yang berat badannya tidak naik akan muncul di sini.</div>
    <?php elseif (!$att['items']): ?>
      <div class="kcf-empty">
        <?= icon('check', 22) ?>
        <div class="fw-bold mt-1">Tidak ada penanda pada <?= e(format_period($period)) ?>.</div>
        <div class="small">Dari <?= (int)$att['measured'] ?> anak ditimbang, <?= (int)$att['rising'] ?> berat badannya naik<?= $att['no_prev'] ? ', ' . (int)$att['no_prev'] . ' belum punya catatan pembanding' : '' ?>.</div>
      </div>
    <?php else: foreach (array_slice($att['items'], 0, 3) as $it): $sg = $it['signal']; $pe = $it['peserta']; ?>
      <div class="kcf-attn">
        <div class="kcf-attn-head">
          <div>
            <a class="kcf-attn-name" href="anak_detail.php?id=<?= (int)$pe['id'] ?>"><?= e($pe['name']) ?></a>
            <div class="muted small"><?= $it['age'] !== null ? (int)$it['age'] . ' bulan' : 'umur belum diketahui' ?><?= isset($dupSet[normalize_name($pe['name'])]) ? ', ' . e(person_hint($pe)) : '' ?></div>
          </div>
          <?= $sg['level'] === 'prioritas' ? pill('Prioritas', 'strong', 'alert') : pill('Cek', 'warn', 'info') ?>
        </div>
        <div class="kcf-reason is-<?= e($sg['level']) ?>">
          <div class="title"><?= e($sg['reason']) ?></div>
          <div class="series">
            <?php foreach ($sg['chain'] as $c): ?><span><?= e(substr(format_period($c['period']), 0, 3)) ?> <strong><?= e(fmt_kg($c['w'])) ?></strong></span><?php endforeach; ?>
            <span class="muted">kg</span>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
      <div class="p-3 text-center border-top"><a class="fw-bold" href="attention.php">Lihat semua (<?= (int)$att['not_rising'] ?> anak)</a></div>
    <?php endif; ?>
  </div>
</section>

<?php if ((int)$corr['total'] > 0): ?>
  <p class="muted small mt-3 mb-0"><?= icon('info', 16) ?> Dari <?= (int)$corr['total'] ?> baris hasil baca AI yang sudah dikonfirmasi, <?= (int)$corr['corrected'] ?> perlu dikoreksi kader. Ini gambaran kasar, bukan ukuran akurasi resmi.</p>
<?php endif; ?>

<section class="kcf-section" aria-labelledby="h-act">
  <div class="d-flex justify-content-between align-items-baseline mb-2">
    <h2 id="h-act" class="kcf-section-title mb-0">Aktivitas terbaru</h2>
    <a class="small fw-bold" href="history.php">Semua riwayat</a>
  </div>
  <div class="kcf-card">
  <?php if (!$recent): ?>
    <div class="kcf-empty">Belum ada sesi. Mulai dengan menekan <strong>Foto halaman register</strong>.</div>
  <?php else: ?>
    <div class="kcf-scroll"><table class="kcf-ledger">
      <thead><tr><th>Tanggal kegiatan</th><th class="col-hide-sm">Diunggah</th><th class="num">Baris</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $s): ?>
        <tr>
          <td><?= format_date($s['activity_date']) ?></td>
          <td class="col-hide-sm"><?= format_datetime($s['created_at']) ?></td>
          <td class="num"><?= (int)$s['row_count'] ?></td>
          <td><?= status_pill($s['status']) ?><?php if (in_array($s['ocr_engine'], ['mock', 'demo'], true)): ?> <?= pill('demo', 'info') ?><?php endif; ?></td>
          <td class="text-end"><a class="row-link" href="session_detail.php?id=<?= (int)$s['id'] ?>">Detail</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
  </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
