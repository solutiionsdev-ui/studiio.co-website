<?php
/**
 * STUDIIO.CO admin — a small PDF writer for client documents (proposal, quotation, agreement,
 * welcome pack). No libraries, so it runs on this Mac and on Hostinger alike.
 *
 * A4 pages, Helvetica and Helvetica-Bold (built into every PDF reader, nothing embedded),
 * filled rectangles, lines, and the STUDIIO logo drawn from the paths in img/studiio-logo.svg.
 * Text is Windows-1252 (English and other Western European text); anything outside it prints
 * as "?", which is why Arabic documents will need an embedded font later.
 */
declare(strict_types=1);

final class Pdf
{
    public const W = 595.28;
    public const H = 841.89;

    /** @var string[] content stream per page */
    private array $pages = [];
    private int $at = -1;

    public function addPage(): int
    {
        $this->pages[] = '';
        $this->at = count($this->pages) - 1;
        return $this->at;
    }

    public function pageCount(): int
    {
        return count($this->pages);
    }

    /** Draw on an earlier page (for footers that need the page count). */
    public function onPage(int $index): void
    {
        $this->at = $index;
    }

    private function op(string $s): void
    {
        $this->pages[$this->at] .= $s . "\n";
    }

    private static function rgb(array $c): string
    {
        return sprintf('%.3F %.3F %.3F', $c[0] / 255, $c[1] / 255, $c[2] / 255);
    }

    public function rect(float $x, float $y, float $w, float $h, array $color): void
    {
        $this->op(sprintf('%s rg %.2F %.2F %.2F %.2F re f', self::rgb($color), $x, $y, $w, $h));
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width, array $color): void
    {
        $this->op(sprintf('%s RG %.2F w %.2F %.2F m %.2F %.2F l S', self::rgb($color), $width, $x1, $y1, $x2, $y2));
    }

    /** Text with its baseline at $y. $tracking is extra space between letters, in points. */
    public function text(float $x, float $y, string $utf8, bool $bold, float $size, array $color, float $tracking = 0.0): void
    {
        $bytes = self::encode($utf8);
        if ($bytes === '') {
            return;
        }
        $this->op(sprintf('BT /%s %.2F Tf %s rg %.2F Tc %.2F %.2F Td (%s) Tj ET',
            $bold ? 'F2' : 'F1', $size, self::rgb($color), $tracking, $x, $y, self::escape($bytes)));
    }

    /** Right-aligned text ending at $right. */
    public function textRight(float $right, float $y, string $utf8, bool $bold, float $size, array $color, float $tracking = 0.0): void
    {
        $this->text($right - self::width($utf8, $bold, $size, $tracking), $y, $utf8, $bold, $size, $color, $tracking);
    }

    /** Width of a string in points. */
    public static function width(string $utf8, bool $bold, float $size, float $tracking = 0.0): float
    {
        $bytes = self::encode($utf8);
        $table = self::widths($bold);
        $sum = 0;
        $n = strlen($bytes);
        for ($i = 0; $i < $n; $i++) {
            $sum += $table[ord($bytes[$i])] ?? 556;
        }
        return $sum * $size / 1000 + $tracking * max(0, $n - 1) + ($n ? $tracking : 0);
    }

    /** UTF-8 → Windows-1252 bytes; a few common characters outside it get a close stand-in. */
    public static function encode(string $utf8): string
    {
        $utf8 = strtr($utf8, ["\u{2192}" => '->', "\u{2190}" => '<-', "\u{2713}" => 'v', "\u{2011}" => '-', "\u{00A0}" => ' ', "\u{202F}" => ' ', "\t" => ' ']);
        $utf8 = preg_replace('/[\x00-\x1F\x7F]/u', '', $utf8) ?? '';
        $out = @mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
        return is_string($out) ? $out : (string) preg_replace('/[^\x20-\x7E]/', '?', $utf8);
    }

    private static function escape(string $bytes): string
    {
        return strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
    }

    /** Glyph widths (per 1000 em) for Windows-1252 bytes, from the standard Helvetica metrics. */
    private static function widths(bool $bold): array
    {
        static $cache = [];
        if (isset($cache[$bold])) {
            return $cache[$bold];
        }
        $ascii = $bold
            ? '278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584'
            : '278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584';
        $t = [];
        foreach (explode(',', $ascii) as $i => $w) {
            $t[32 + $i] = (int) $w;
        }
        $q = $bold ? 278 : 222;
        $qq = $bold ? 500 : 333;
        $t += [0x80 => 556, 0x82 => $q, 0x84 => $qq, 0x85 => 1000, 0x91 => $q, 0x92 => $q, 0x93 => $qq, 0x94 => $qq,
            0x95 => 350, 0x96 => 556, 0x97 => 1000, 0x99 => 1000, 0xA0 => 278, 0xA3 => 556, 0xA7 => 556, 0xA9 => 737,
            0xAB => 556, 0xAE => 737, 0xB0 => 400, 0xB7 => 278, 0xBB => 556, 0xD7 => 584, 0xDF => 611, 0xC6 => 1000,
            0xE6 => 889, 0xD8 => 778, 0xF8 => 611];
        // Accented letters are as wide as the letter underneath (accented i's are 278 in both fonts).
        $base = 'AAAAAAACEEEEIIIIDNOOOOO*OUUUUYPsaaaaaaaceeeeiiiidnooooo/ouuuuypy';
        for ($b = 0xC0; $b <= 0xFF; $b++) {
            if (isset($t[$b])) {
                continue;
            }
            $ch = $base[$b - 0xC0];
            $t[$b] = in_array($ch, ['I', 'i'], true) ? 278 : ($t[ord($ch)] ?? 556);
        }
        return $cache[$bold] = $t;
    }

    /**
     * The STUDIIO wordmark (the letters of img/studiio-logo.svg, without ".CO"), drawn with its
     * bottom at $y and $h points tall. Returns its width.
     */
    public function logo(float $x, float $y, float $h, array $color): float
    {
        [$paths, $minX, $minY, $maxX, $maxY] = self::logoPaths();
        $s = $h / ($maxY - $minY);
        $px = static fn(float $vx): float => $x + ($vx - $minX) * $s;
        $py = static fn(float $vy): float => $y + ($maxY - $vy) * $s;
        $ops = [self::rgb($color) . ' rg'];
        foreach ($paths as $path) {
            foreach ($path as $seg) {
                switch ($seg[0]) {
                    case 'M':
                        $ops[] = sprintf('%.2F %.2F m', $px($seg[1]), $py($seg[2]));
                        break;
                    case 'L':
                        $ops[] = sprintf('%.2F %.2F l', $px($seg[1]), $py($seg[2]));
                        break;
                    case 'C':
                        $ops[] = sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c', $px($seg[1]), $py($seg[2]), $px($seg[3]), $py($seg[4]), $px($seg[5]), $py($seg[6]));
                        break;
                    case 'Z':
                        $ops[] = 'h';
                        break;
                }
            }
        }
        $ops[] = 'f*';
        $this->op(implode("\n", $ops));
        return ($maxX - $minX) * $s;
    }

    /** Logo paths as M/L/C/Z segments in SVG units, plus their bounds. */
    public static function logoPaths(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $svg = (string) @file_get_contents(dirname(__DIR__, 2) . '/img/studiio-logo.svg');
        preg_match_all('/<path[^>]*\sd="([^"]+)"/', $svg, $m);
        $paths = [];
        $minX = $minY = INF;
        $maxX = $maxY = -INF;
        foreach ($m[1] as $d) {
            preg_match_all('/[MHVLQZ]|-?\d+(?:\.\d+)?/i', $d, $t);
            $tok = $t[0];
            $segs = [];
            $cx = $cy = $sx = $sy = 0.0;
            $i = 0;
            $cmd = '';
            while ($i < count($tok)) {
                if (!is_numeric($tok[$i])) {
                    $cmd = strtoupper($tok[$i++]);
                    if ($cmd === 'Z') {
                        $segs[] = ['Z'];
                        [$cx, $cy] = [$sx, $sy];
                        continue;
                    }
                }
                $num = static function () use (&$i, $tok): float { return (float) $tok[$i++]; };
                switch ($cmd) {
                    case 'M':
                        $cx = $num();
                        $cy = $num();
                        [$sx, $sy] = [$cx, $cy];
                        $segs[] = ['M', $cx, $cy];
                        $cmd = 'L';
                        break;
                    case 'L':
                        $cx = $num();
                        $cy = $num();
                        $segs[] = ['L', $cx, $cy];
                        break;
                    case 'H':
                        $cx = $num();
                        $segs[] = ['L', $cx, $cy];
                        break;
                    case 'V':
                        $cy = $num();
                        $segs[] = ['L', $cx, $cy];
                        break;
                    case 'Q':
                        // Quadratic → cubic: control points 2/3 of the way to the quadratic control point.
                        $qx = $num();
                        $qy = $num();
                        $ex = $num();
                        $ey = $num();
                        $segs[] = ['C', $cx + 2 / 3 * ($qx - $cx), $cy + 2 / 3 * ($qy - $cy), $ex + 2 / 3 * ($qx - $ex), $ey + 2 / 3 * ($qy - $ey), $ex, $ey];
                        $cx = $ex;
                        $cy = $ey;
                        break;
                    default:
                        $i++; // unknown command: skip its number
                }
            }
            $xs = $ys = [];
            foreach ($segs as $seg) {
                for ($k = 1; $k + 1 < count($seg); $k += 2) {
                    $xs[] = $seg[$k];
                    $ys[] = $seg[$k + 1];
                }
            }
            // Keep the letters S T U D I I O; ".CO" sits to the right of x = 610 in the file.
            if (!$xs || min($xs) >= 610) {
                continue;
            }
            $paths[] = $segs;
            $minX = min($minX, ...$xs);
            $maxX = max($maxX, ...$xs);
            $minY = min($minY, ...$ys);
            $maxY = max($maxY, ...$ys);
        }
        if (!$paths) {
            throw new RuntimeException('img/studiio-logo.svg is missing or has no paths.');
        }
        return $cache = [$paths, $minX, $minY, $maxX, $maxY];
    }

    /** The finished PDF file. */
    public function output(string $title = ''): string
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $info = 5;
        $objs[$info] = '<< /Producer (STUDIIO admin) /Title (' . self::escape(self::encode($title)) . ') /CreationDate (D:' . gmdate('YmdHis') . 'Z) >>';
        $kids = [];
        $n = 6;
        foreach ($this->pages as $content) {
            $stream = $content;
            $filter = '';
            if (function_exists('gzcompress')) {
                $stream = (string) gzcompress($content, 6);
                $filter = ' /Filter /FlateDecode';
            }
            $objs[$n] = '<< /Length ' . strlen($stream) . $filter . " >>\nstream\n" . $stream . "\nendstream";
            $objs[$n + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>', self::W, self::H, $n);
            $kids[] = ($n + 1) . ' 0 R';
            $n += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($pdf);
        $max = max(array_keys($objs));
        $pdf .= 'xref' . "\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $max; $id++) {
            $pdf .= isset($offsets[$id]) ? sprintf("%010d 00000 n \n", $offsets[$id]) : "0000000000 65535 f \n";
        }
        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $pdf;
    }
}
