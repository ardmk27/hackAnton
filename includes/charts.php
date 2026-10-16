<?php
declare(strict_types=1);

/** Singkatan bulan untuk label grafik, mis. "Okt 26". */
function short_period(string $period): string
{
    $m = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    if (!preg_match('/^(\d{4})-(\d{2})$/', $period, $x)) {
        return $period;
    }
    return ($m[(int)$x[2]] ?? $x[2]) . ' ' . substr($x[1], 2);
}

/**
 * Grafik garis berat badan sebagai SVG murni (tanpa pustaka).
 * Titik "tidak naik" memakai PERSEGI + teks (tidak mengandalkan warna saja).
 *
 * @param array<string, array> $points periode → titik (butuh weight_kg)
 */
function weight_chart_svg(array $points): string
{
    $pts = [];
    foreach ($points as $per => $pt) {
        if ($pt['weight_kg'] !== null) {
            $pts[] = ['period' => (string)$per, 'w' => (float)$pt['weight_kg']];
        }
    }
    $n = count($pts);
    if ($n === 0) {
        return '';
    }
    foreach ($pts as $i => &$p) {
        $p['flat'] = $i > 0 && $p['w'] <= $pts[$i - 1]['w'];
    }
    unset($p);

    $W = 360; $H = 240; $L = 44; $R = 38; $T = 36; $B = 50;
    $min = min(array_column($pts, 'w'));
    $max = max(array_column($pts, 'w'));
    $pad = max(0.4, ($max - $min) * 0.25);
    $lo = $min - $pad;
    $hi = $max + $pad;
    $y = fn(float $v) => $T + ($hi - $v) / ($hi - $lo) * ($H - $T - $B);

    $span = max(1, months_between($pts[0]['period'], $pts[$n - 1]['period']));
    $x = function (int $i) use ($pts, $n, $span, $L, $R, $W): float {
        if ($n === 1) {
            return ($L + $W - $R) / 2;
        }
        return $L + months_between($pts[0]['period'], $pts[$i]['period']) / $span * ($W - $L - $R);
    };

    $aria = [];
    foreach ($pts as $p) {
        $aria[] = format_period($p['period']) . ' ' . fmt_kg($p['w']) . ' kg' . ($p['flat'] ? ' tidak naik' : '');
    }

    $svg = '<svg class="kcf-chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Grafik berat badan: ' . e(implode('; ', $aria)) . '">';
    foreach ([$lo + ($hi - $lo) * 0.15, ($lo + $hi) / 2, $hi - ($hi - $lo) * 0.15] as $tick) {
        $ty = round($y($tick), 1);
        $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $ty . '" y2="' . $ty . '" stroke="var(--kcf-line)" stroke-width="1"/>';
        $svg .= '<text x="' . ($L - 8) . '" y="' . ($ty + 4) . '" text-anchor="end" font-size="13" fill="var(--kcf-muted)">' . e(fmt_kg(round($tick, 1))) . '</text>';
    }
    $svg .= '<text x="' . ($L - 8) . '" y="' . ($T - 16) . '" text-anchor="end" font-size="13" font-weight="700" fill="var(--kcf-muted)">kg</text>';

    if ($n > 1) {
        $line = [];
        foreach ($pts as $i => $p) {
            $line[] = round($x($i), 1) . ',' . round($y($p['w']), 1);
        }
        $svg .= '<polyline points="' . implode(' ', $line) . '" fill="none" stroke="var(--kcf-ink)" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
    }
    foreach ($pts as $i => $p) {
        $cx = round($x($i), 1);
        $cy = round($y($p['w']), 1);
        if ($p['flat']) {
            $svg .= '<rect x="' . ($cx - 8) . '" y="' . ($cy - 8) . '" width="16" height="16" fill="var(--kcf-red-tint)" stroke="var(--kcf-red)" stroke-width="3"/>';
            $svg .= '<text x="' . $cx . '" y="' . ($cy + 30) . '" text-anchor="middle" font-size="13" font-weight="700" fill="var(--kcf-red-ink)">tidak naik</text>';
        } else {
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="7" fill="#fff" stroke="var(--kcf-ink)" stroke-width="2.5"/>';
        }
        $svg .= '<text x="' . $cx . '" y="' . ($cy - 16) . '" text-anchor="middle" font-size="16" font-weight="700" fill="' . ($p['flat'] ? 'var(--kcf-red-ink)' : 'var(--kcf-ink)') . '">' . e(fmt_kg($p['w'])) . '</text>';
        $svg .= '<text x="' . $cx . '" y="' . ($H - 16) . '" text-anchor="middle" font-size="14" fill="var(--kcf-muted)">' . e(short_period($p['period'])) . '</text>';
    }
    return $svg . '</svg>';
}
