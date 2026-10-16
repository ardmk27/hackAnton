<?php
declare(strict_types=1);

/**
 * Penulis PDF minimal tanpa dependensi: halaman A4, teks Helvetica (Windows-1252), persegi, garis, dan kurva.
 * Satuan koordinat: milimeter, titik asal di kiri atas halaman (y bertambah ke bawah).
 * Teks diposisikan dengan baseline. Cukup untuk laporan tabel; bukan pengganti pustaka PDF umum.
 */
final class SimplePdf
{
    public const MM = 2.8346457;   // titik PDF per milimeter

    public float $w;
    public float $h;
    /** @var string[] */
    private array $pages = [];
    private int $cur = -1;
    private array $metrics;
    private string $title;

    public function __construct(string $title, bool $landscape = true)
    {
        $this->title = $title;
        $this->w = $landscape ? 297.0 : 210.0;
        $this->h = $landscape ? 210.0 : 297.0;
        $this->metrics = require __DIR__ . '/pdf_metrics.php';
    }

    public function addPage(): void
    {
        $this->pages[] = '';
        $this->cur = count($this->pages) - 1;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    public function gotoPage(int $i): void
    {
        $this->cur = $i;
    }

    // ---------- util ----------
    private static function n(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function rgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return self::n(hexdec(substr($hex, 0, 2)) / 255) . ' ' . self::n(hexdec(substr($hex, 2, 2)) / 255) . ' ' . self::n(hexdec(substr($hex, 4, 2)) / 255);
    }

    private function X(float $mm): string
    {
        return self::n($mm * self::MM);
    }

    private function Y(float $mm): string
    {
        return self::n(($this->h - $mm) * self::MM);
    }

    private function emit(string $ops): void
    {
        $this->pages[$this->cur] .= $ops . "\n";
    }

    private function enc(string $s): string
    {
        $s = str_replace(["\r", "\n", "\t"], ' ', $s);
        $prev = mb_substitute_character();
        mb_substitute_character(63);
        $w = mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        mb_substitute_character($prev);
        return $w === false ? '' : $w;
    }

    private function esc(string $win1252): string
    {
        $o = '';
        for ($i = 0, $n = strlen($win1252); $i < $n; $i++) {
            $c = ord($win1252[$i]);
            if ($c === 40 || $c === 41 || $c === 92) {
                $o .= '\\' . $win1252[$i];
            } elseif ($c < 32 || $c > 126) {
                $o .= '\\' . str_pad(decoct($c), 3, '0', STR_PAD_LEFT);
            } else {
                $o .= $win1252[$i];
            }
        }
        return $o;
    }

    // ---------- ukur teks ----------
    /** Lebar teks dalam mm. */
    public function textWidth(string $s, float $size, bool $bold = false): float
    {
        $m = $this->metrics[$bold ? 'B' : 'R'];
        $w = $this->enc($s);
        $sum = 0;
        for ($i = 0, $n = strlen($w); $i < $n; $i++) {
            $sum += $m[ord($w[$i])] ?: 278;
        }
        return $sum * $size / 1000 / self::MM;
    }

    /** Bungkus teks ke lebar $maxW (mm). Kata yang lebih panjang dari lebar dipotong per huruf. @return string[] */
    public function wrap(string $s, float $maxW, float $size, bool $bold = false): array
    {
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');
        if ($s === '') {
            return [''];
        }
        $lines = [];
        $line = '';
        foreach (explode(' ', $s) as $word) {
            while ($this->textWidth($word, $size, $bold) > $maxW && mb_strlen($word) > 1) {
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                $cut = $word;
                while ($this->textWidth($cut, $size, $bold) > $maxW && mb_strlen($cut) > 1) {
                    $cut = mb_substr($cut, 0, mb_strlen($cut) - 1);
                }
                $lines[] = $cut;
                $word = mb_substr($word, mb_strlen($cut));
            }
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && $this->textWidth($try, $size, $bold) > $maxW) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        return $lines;
    }

    // ---------- menggambar ----------
    /**
     * Teks pada baseline y. $o: size, bold, color, align (L|C|R terhadap kotak lebar $o['w'] mulai x; tanpa 'w': x adalah titik jangkar).
     */
    public function text(float $x, float $y, string $s, array $o = []): void
    {
        $size = (float)($o['size'] ?? 10);
        $bold = !empty($o['bold']);
        $align = $o['align'] ?? 'L';
        $tw = $this->textWidth($s, $size, $bold);
        if (isset($o['w'])) {
            $x += $align === 'R' ? $o['w'] - $tw : ($align === 'C' ? ($o['w'] - $tw) / 2 : 0);
        } else {
            $x -= $align === 'R' ? $tw : ($align === 'C' ? $tw / 2 : 0);
        }
        $this->emit('BT ' . self::rgb($o['color'] ?? '151A26') . ' rg /' . ($bold ? 'F2' : 'F1') . ' ' . self::n($size) . ' Tf '
            . $this->X($x) . ' ' . $this->Y($y) . ' Td (' . $this->esc($this->enc($s)) . ') Tj ET');
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color = 'C3CAD6', float $lw = 0.25): void
    {
        $this->emit('q ' . self::rgb($color) . ' RG ' . self::n($lw * self::MM) . ' w ' . $this->X($x1) . ' ' . $this->Y($y1) . ' m ' . $this->X($x2) . ' ' . $this->Y($y2) . ' l S Q');
    }

    /** Persegi (opsional sudut membulat). $fill/$stroke: hex atau null. */
    public function rect(float $x, float $y, float $w, float $h, ?string $fill, ?string $stroke = null, float $lw = 0.25, float $r = 0): void
    {
        $ops = 'q ';
        if ($fill !== null) {
            $ops .= self::rgb($fill) . ' rg ';
        }
        if ($stroke !== null) {
            $ops .= self::rgb($stroke) . ' RG ' . self::n($lw * self::MM) . ' w ';
        }
        if ($r <= 0) {
            $ops .= $this->X($x) . ' ' . $this->Y($y + $h) . ' ' . self::n($w * self::MM) . ' ' . self::n($h * self::MM) . ' re ';
        } else {
            $r = min($r, $w / 2, $h / 2);
            $k = 0.5523 * $r;
            $x2 = $x + $w;
            $y2 = $y + $h;
            $p = fn(float $a, float $b) => $this->X($a) . ' ' . $this->Y($b);
            $ops .= $p($x + $r, $y) . ' m ' . $p($x2 - $r, $y) . ' l '
                . $p($x2 - $r + $k, $y) . ' ' . $p($x2, $y + $r - $k) . ' ' . $p($x2, $y + $r) . ' c '
                . $p($x2, $y2 - $r) . ' l '
                . $p($x2, $y2 - $r + $k) . ' ' . $p($x2 - $r + $k, $y2) . ' ' . $p($x2 - $r, $y2) . ' c '
                . $p($x + $r, $y2) . ' l '
                . $p($x + $r - $k, $y2) . ' ' . $p($x, $y2 - $r + $k) . ' ' . $p($x, $y2 - $r) . ' c '
                . $p($x, $y + $r) . ' l '
                . $p($x, $y + $r - $k) . ' ' . $p($x + $r - $k, $y) . ' ' . $p($x + $r, $y) . ' c h ';
        }
        $ops .= $fill !== null && $stroke !== null ? 'B' : ($fill !== null ? 'f' : 'S');
        $this->emit($ops . ' Q');
    }

    /** Garis lengkung tebal (untuk tanda centang pada logo). @param array<int, array{0:float,1:float}> $pts */
    public function polyline(array $pts, string $color, float $lw = 0.6): void
    {
        $ops = 'q ' . self::rgb($color) . ' RG ' . self::n($lw * self::MM) . ' w 1 J 1 j ';
        foreach ($pts as $i => [$px, $py]) {
            $ops .= $this->X($px) . ' ' . $this->Y($py) . ($i === 0 ? ' m ' : ' l ');
        }
        $this->emit($ops . 'S Q');
    }

    // ---------- keluaran ----------
    /** @param ?callable(SimplePdf,int,int):void $footer dipanggil per halaman: (pdf, nomor mulai 1, total) */
    public function output(?callable $footer = null): string
    {
        $total = count($this->pages);
        if ($footer) {
            for ($i = 0; $i < $total; $i++) {
                $this->cur = $i;
                $footer($this, $i + 1, $total);
            }
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $first = 6;
        for ($i = 0; $i < $total; $i++) {
            $kids[] = ($first + $i * 2) . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $total . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $date = 'D:' . date('YmdHis');
        $objs[5] = '<< /Title (' . $this->esc($this->enc($this->title)) . ') /Producer (Rakit) /Creator (Rakit) /CreationDate (' . $date . ') >>';
        $wpt = self::n($this->w * self::MM);
        $hpt = self::n($this->h * self::MM);
        foreach ($this->pages as $i => $content) {
            $pageNo = $first + $i * 2;
            $stream = $content;
            $filter = '';
            if (function_exists('gzcompress')) {
                $stream = gzcompress($content, 6);
                $filter = ' /Filter /FlateDecode';
            }
            $objs[$pageNo] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . $wpt . ' ' . $hpt . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . ($pageNo + 1) . ' 0 R >>';
            $objs[$pageNo + 1] = ['dict' => '<< /Length ' . strlen($stream) . $filter . ' >>', 'stream' => $stream];
        }
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $num => $o) {
            $offsets[$num] = strlen($out);
            $out .= $num . " 0 obj\n" . (is_array($o) ? $o['dict'] . "\nstream\n" . $o['stream'] . "\nendstream" : $o) . "\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info 5 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }

    /** Kirim sebagai unduhan lalu hentikan skrip. */
    public function download(string $filename, ?callable $footer = null): never
    {
        $bin = $this->output($footer);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($bin));
        header('Cache-Control: private, no-store');
        echo $bin;
        exit;
    }
}
