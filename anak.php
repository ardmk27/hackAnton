<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/trends.php';

$currentPosyandu = $p = require_login();
$pdo = db();
$pid = (int)$p['id'];

$q = trim((string)($_GET['q'] ?? ''));
$roster = load_roster($pdo, $pid);
$dupSet = duplicate_name_set($roster);
$rows = [];
foreach ($roster as $r) {
    if ($q !== '' && stripos($r['name'], $q) === false && stripos((string)$r['ref'], $q) === false) {
        continue;
    }
    $last = $r['points'] ? end($r['points']) : null;
    $sig = weight_signal($r['points']);
    $rows[] = ['r' => $r, 'last' => $last, 'sig' => $sig, 'age' => $last ? age_months($r['dob'], $last['period']) : null];
}

$pageTitle = 'Daftar anak';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
  <div>
    <h1 class="page-title h4 mb-1">Daftar anak</h1>
    <p class="muted mb-0">Anak yang sudah punya catatan terkonfirmasi di <?= e($p['name']) ?>.</p>
  </div>
  <form method="get" class="d-flex gap-2" role="search">
    <label class="visually-hidden" for="q">Cari nama atau nomor daftar</label>
    <input class="form-control" id="q" name="q" value="<?= e($q) ?>" placeholder="Cari nama atau no. daftar" style="min-width:220px">
    <button class="btn btn-quiet"><?= icon('search', 18) ?>Cari</button>
  </form>
</div>

<div class="kcf-card">
<?php if (!$rows): ?>
  <div class="kcf-empty"><?= $q !== '' ? 'Tidak ada anak yang cocok dengan pencarian.' : 'Belum ada data anak. Daftar ini terisi setelah sesi dikonfirmasi.' ?></div>
<?php else: ?>
  <div class="kcf-scroll"><table class="kcf-ledger">
    <thead><tr><th>No.</th><th>Nama</th><th class="num">Umur</th><th>Catatan terakhir</th><th class="num">BB (kg)</th><th class="num">TB (cm)</th><th>Penanda</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $x): $r = $x['r']; $l = $x['last']; $sg = $x['sig']; ?>
      <tr>
        <td><?= e($r['ref'] ?? '—') ?></td>
        <td><a class="row-link" href="anak_detail.php?id=<?= (int)$r['id'] ?>"><?= e($r['name']) ?></a><?php if (isset($dupSet[normalize_name($r['name'])])): ?><div class="small muted">nama sama dengan anak lain, <?= e(person_hint($r)) ?></div><?php endif; ?></td>
        <td class="num"><?= $x['age'] !== null ? (int)$x['age'] . ' bln' : '—' ?></td>
        <td><?= $l ? e(format_period($l['period'])) : '—' ?></td>
        <td class="num"><?= $l ? e(show_num($l['weight_kg'])) : '—' ?></td>
        <td class="num"><?= $l ? e(show_num($l['height_cm'])) : '—' ?></td>
        <td><?php
          if ($sg && $sg['level'] === 'prioritas') { echo pill('Prioritas', 'strong', 'alert'); }
          elseif ($sg && $sg['level'] === 'cek') { echo pill('Cek', 'warn', 'info'); }
          elseif ($sg) { echo pill('BB naik', 'ok', 'check'); }
          else { echo '<span class="muted small">belum ada pembanding</span>'; }
        ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
