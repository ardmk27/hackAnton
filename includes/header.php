<?php
/** @var string $pageTitle */
/** @var ?array $currentPosyandu */
require_once __DIR__ . '/trends.php';

$pageTitle = $pageTitle ?? 'Rakit';
$currentPosyandu = $currentPosyandu ?? null;
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');

$groupOf = [
    'dashboard.php' => 'home',
    'upload.php' => 'foto', 'review.php' => 'foto', 'process_photo.php' => 'foto',
    'attention.php' => 'attn',
    'anak.php' => 'anak', 'anak_detail.php' => 'anak',
    'recap.php' => 'rekap', 'report.php' => 'rekap',
    'history.php' => 'riwayat', 'session_detail.php' => 'riwayat',
    'account.php' => 'akun',
];
$activeGroup = $groupOf[$script] ?? '';
$on = fn(string ...$groups) => in_array($activeGroup, $groups, true) ? ' is-active' : '';
$cur = fn(string ...$groups) => in_array($activeGroup, $groups, true) ? ' aria-current="page"' : '';

$attnCount = 0;
if ($currentPosyandu) {
    try {
        $pdoNav = db();
        $rosterNav = load_roster($pdoNav, (int)$currentPosyandu['id']);
        $attnNav = attention_for_period($rosterNav, latest_confirmed_period($pdoNav, (int)$currentPosyandu['id']));
        $attnCount = (int)$attnNav['not_rising'];
    } catch (Throwable) {
        $attnCount = 0;
    }
}
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#124170">
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <title><?= e($pageTitle) ?> · Rakit</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&amp;family=Instrument+Serif&amp;family=Caveat:wght@500;700&amp;display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/css/theme.css?v=3" rel="stylesheet">
</head>
<body>
<a class="skip-link" href="#main">Lewati ke isi halaman</a>
<header class="kcf-topbar">
  <div class="kcf-wrap">
    <a class="kcf-brand" href="<?= $currentPosyandu ? 'dashboard.php' : 'index.php' ?>"><?= brand_mark(28) ?><span>Rakit</span></a>
    <?php if ($currentPosyandu): ?>
      <nav aria-label="Menu utama">
        <ul class="kcf-nav">
          <li><a class="<?= trim($on('home')) ?>" href="dashboard.php"<?= $cur('home') ?>>Beranda</a></li>
          <li><a class="<?= trim($on('foto')) ?>" href="upload.php"<?= $cur('foto') ?>>Foto</a></li>
          <li><a class="<?= trim($on('attn')) ?>" href="attention.php"<?= $cur('attn') ?>>Perhatian<?php if ($attnCount > 0): ?><span class="kcf-count" aria-label="<?= $attnCount ?> anak"><?= $attnCount ?></span><?php endif; ?></a></li>
          <li><a class="<?= trim($on('anak')) ?>" href="anak.php"<?= $cur('anak') ?>>Anak</a></li>
          <li><a class="<?= trim($on('rekap')) ?>" href="recap.php"<?= $cur('rekap') ?>>Rekap</a></li>
          <li><a class="<?= trim($on('riwayat')) ?>" href="history.php"<?= $cur('riwayat') ?>>Riwayat</a></li>
          <li><a class="<?= trim($on('akun')) ?>" href="account.php"<?= $cur('akun') ?>>Akun</a></li>
        </ul>
      </nav>
      <div class="kcf-who">
        <span class="name" title="<?= e($currentPosyandu['name']) ?>"><?= e($currentPosyandu['name']) ?></span>
        <form method="post" action="logout.php" class="d-inline">
          <?= csrf_field() ?>
          <button class="btn btn-quiet btn-sm">Keluar</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</header>
<main id="main" class="kcf-wrap kcf-main">
<?php foreach (pull_flashes() as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="<?= in_array($f['type'], ['danger', 'warning'], true) ? 'alert' : 'status' ?>">
    <?= e($f['msg']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup pesan"></button>
  </div>
<?php endforeach; ?>
