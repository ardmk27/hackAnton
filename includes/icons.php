<?php
declare(strict_types=1);

/** Ikon SVG garis (24x24). Dekoratif: aria-hidden. Beri teks/aria-label pada elemen pembungkus. */
function icon(string $name, int $size = 20, string $class = ''): string
{
    static $paths = [
        'camera'   => '<path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/>',
        'book'     => '<path d="M5 4h11a3 3 0 013 3v13H8a3 3 0 01-3-3z"/><path d="M5 17a3 3 0 013-3h11"/>',
        'alert'    => '<path d="M12 4l9 16H3z"/><path d="M12 10v4"/><path d="M12 17h.01"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/>',
        'bars'     => '<path d="M5 20V10"/><path d="M12 20V4"/><path d="M19 20v-7"/>',
        'check'    => '<path d="M5 12l4 4 10-10"/>',
        'x'        => '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
        'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'wifi-off' => '<path d="M2 9a15 15 0 0120 0"/><path d="M5 13a10 10 0 0114 0"/><path d="M8.5 16.5a5 5 0 017 0"/><path d="M3 3l18 18"/>',
        'chevron'  => '<path d="M9 5l7 7-7 7"/>',
        'back'     => '<path d="M15 5l-7 7 7 7"/>',
        'download' => '<path d="M12 4v11"/><path d="M7 11l5 5 5-5"/><path d="M5 20h14"/>',
        'printer'  => '<path d="M7 9V4h10v5"/><path d="M7 17H5a1 1 0 01-1-1v-6a1 1 0 011-1h14a1 1 0 011 1v6a1 1 0 01-1 1h-2"/><path d="M7 14h10v6H7z"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0113 0"/><path d="M16 4.6a3.5 3.5 0 010 6.8"/><path d="M18 14.2A6.5 6.5 0 0121.5 20"/>',
        'list'     => '<path d="M8 6h12"/><path d="M8 12h12"/><path d="M8 18h12"/><path d="M4 6h.01"/><path d="M4 12h.01"/><path d="M4 18h.01"/>',
        'key'      => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9"/><path d="M16 7l3 3"/>',
        'trash'    => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
        'edit'     => '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/>',
        'image'    => '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M4 17l5-4 4 3 3-2 4 3"/>',
        'sheet'    => '<path d="M6 3h9l4 4v14H6z"/><path d="M15 3v4h4"/><path d="M9 12h7"/><path d="M9 16h7"/>',
        'copy'     => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 00-1-1H5a1 1 0 00-1 1v10a1 1 0 001 1h3"/>',
        'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4"/>',
        'undo'     => '<path d="M9 7L4 12l5 5"/><path d="M4 12h11a5 5 0 010 10h-2"/>',
        'flask'    => '<path d="M9 3h6"/><path d="M10 3v6L5 19a1 1 0 001 1.5h12A1 1 0 0019 19l-5-10V3"/>',
    ];
    $p = $paths[$name] ?? '';
    $cls = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<svg' . $cls . ' width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

/** Lencana status: ikon + teks (tidak mengandalkan warna saja). */
function pill(string $text, string $tone = 'muted', ?string $icon = null): string
{
    $ic = $icon ? icon($icon, 14) : '';
    return '<span class="kcf-pill is-' . htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') . '">' . $ic . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
}

/** Logo Rakit: berkas gambar resmi (assets/img/rakit-logo.png). */
function brand_mark(int $size = 28): string
{
    return '<img src="assets/img/rakit-logo.png" width="' . $size . '" height="' . $size . '" alt="" decoding="async" style="display:block;flex:none">';
}

/** Kotak kode (ID atau kode akses) dengan tombol salin di sampingnya. Perlu assets/js/copy.js (dimuat footer). */
function code_box(string $value, string $what): string
{
    $v = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $w = htmlspecialchars($what, ENT_QUOTES, 'UTF-8');
    return '<div class="kcf-codebox">'
        . '<div class="kcf-code">' . $v . '</div>'
        . '<button type="button" class="kcf-copy" data-copy="' . $v . '" aria-label="Salin ' . $w . '">'
        . '<span class="ic">' . icon('copy', 18) . '</span><span class="lbl">Salin</span></button>'
        . '<span class="visually-hidden" role="status" aria-live="polite"></span>'
        . '</div>';
}
