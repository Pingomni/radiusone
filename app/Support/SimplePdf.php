<?php

declare(strict_types=1);

namespace App\Support;

final class SimplePdf
{
    /**
     * @param array<int, string> $lines
     */
    public static function document(array $lines, string $title = 'Document'): string
    {
        $body = [
            'title' => $title,
            'subtitle' => '',
            'app_name' => 'FreeRADIUS Web',
            'logo_path' => null,
            'meta' => [],
            'items' => array_map(fn (string $line): array => [$line, '', ''], $lines),
            'payments' => [],
            'totals' => [],
            'footer' => '',
        ];
        return self::receipt($body);
    }

    /**
     * @param array{
     *   title:string,
     *   subtitle?:string,
     *   app_name:string,
     *   logo_path?:?string,
     *   meta:array<string,string>,
     *   items:array<int,array<int,string>>,
     *   payments:array<int,array<int,string>>,
     *   totals:array<string,string>,
     *   footer?:string
     * } $data
     */
    public static function receipt(array $data): string
    {
        $image = self::imageObject((string) ($data['logo_path'] ?? ''));
        $content = self::receiptContent($data, $image);

        $objects = [];
        $imageId = null;
        if ($image) {
            $imageId = count($objects) + 1;
            $objects[] = $image['object'];
        }

        $contentId = count($objects) + 1;
        $objects[] = "<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream";
        $pageId = count($objects) + 1;
        $objects[] = 'PAGE_PLACEHOLDER';
        $fontRegularId = count($objects) + 1;
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $fontBoldId = count($objects) + 1;
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $pagesId = count($objects) + 1;
        $objects[] = "<< /Type /Pages /Kids [{$pageId} 0 R] /Count 1 >>";
        $catalogId = count($objects) + 1;
        $objects[] = "<< /Type /Catalog /Pages {$pagesId} 0 R >>";
        $infoId = count($objects) + 1;
        $objects[] = '<< /Title (' . self::pdfText((string) $data['title']) . ') /Producer (FreeRADIUS Web) >>';

        $xObject = $imageId ? "/XObject << /Logo {$imageId} 0 R >>" : '';
        $objects[$pageId - 1] = "<< /Type /Page /Parent {$pagesId} 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 {$fontRegularId} 0 R /F2 {$fontBoldId} 0 R >> {$xObject} >> /Contents {$contentId} 0 R >>";

        return self::assemble($objects, $catalogId, $infoId);
    }

    /**
     * @param array<int, string> $objects
     */
    private static function assemble(array $objects, int $catalogId, int $infoId): string
    {
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root {$catalogId} 0 R /Info {$infoId} 0 R >>\n";
        $pdf .= "startxref\n{$xref}\n%%EOF\n";
        return $pdf;
    }

    private static function receiptContent(array $data, ?array $image): string
    {
        $c = "q 0.08 0.25 0.55 rg 0 746 595 96 re f Q\n";
        $c .= "q 0.93 0.96 1 rg 0 0 595 746 re f Q\n";
        if ($image) {
            $box = self::fitBox((int) $image['width'], (int) $image['height'], 72, 48);
            $c .= sprintf("q %.2F 0 0 %.2F 42 %.2F cm /Logo Do Q\n", $box['w'], $box['h'], 792 - $box['h']);
            $textX = 132;
        } else {
            $textX = 42;
        }

        $c .= self::text((string) $data['app_name'], $textX, 804, 16, true, [1, 1, 1]);
        $c .= self::text((string) $data['title'], 42, 724, 22, true, [0.06, 0.14, 0.25]);
        if (!empty($data['subtitle'])) {
            $c .= self::text((string) $data['subtitle'], 42, 704, 10, false, [0.38, 0.45, 0.55]);
        }
        $state = (string) ($data['payment_state'] ?? '');
        if (in_array($state, ['paid', 'partial'], true)) {
            $c .= self::stamp($state === 'paid' ? 'PAID' : 'PARTIAL', 444, 704);
        }

        $leftMeta = array_slice($data['meta'], 0, 5, true);
        $rightMeta = array_slice($data['meta'], 5, 5, true);
        $c .= self::panel(42, 584, 244, 104, 'Customer / Invoice', $leftMeta);
        $c .= self::panel(310, 584, 244, 104, 'Payment Summary', $rightMeta);

        $y = 538;
        $c .= self::table($y, ['Description', 'Billing period', 'Total'], $data['items'], [285, 115, 100]);
        $y -= 32 + (max(1, count($data['items'])) * 22) + 22;

        if (!empty($data['payments'])) {
            $c .= self::text('Payments Received', 42, $y, 12, true, [0.06, 0.14, 0.25]);
            $y -= 18;
            $c .= self::table($y, ['Date', 'Amount', 'Method', 'Reference'], $data['payments'], [110, 110, 120, 160]);
            $y -= 32 + (max(1, count($data['payments'])) * 22) + 18;
        }

        $totalBoxHeight = 22 + (count($data['totals']) * 18);
        $c .= self::totalsBox(354, max(92, $y - $totalBoxHeight - 10), $data['totals']);
        $footer = (string) ($data['footer'] ?? 'Thank you for your payment.');
        $c .= self::text($footer, 42, 52, 9, false, [0.38, 0.45, 0.55]);
        return $c;
    }

    private static function panel(float $x, float $y, float $w, float $h, string $title, array $rows): string
    {
        $c = self::rect($x, $y, $w, $h, [1, 1, 1], [0.82, 0.87, 0.93]);
        $c .= self::text($title, $x + 12, $y + $h - 20, 10, true, [0.06, 0.14, 0.25]);
        $lineY = $y + $h - 38;
        foreach ($rows as $label => $value) {
            $c .= self::text((string) $label . ':', $x + 12, $lineY, 8.5, true, [0.38, 0.45, 0.55]);
            $c .= self::text(self::clip((string) $value, 34), $x + 88, $lineY, 8.5, false, [0.06, 0.14, 0.25]);
            $lineY -= 13;
        }
        return $c;
    }

    private static function table(float $y, array $headers, array $rows, array $widths): string
    {
        $x = 42;
        $c = self::rect($x, $y - 18, array_sum($widths), 24, [0.08, 0.25, 0.55], [0.08, 0.25, 0.55]);
        $cellX = $x;
        foreach ($headers as $i => $header) {
            $c .= self::text((string) $header, $cellX + 8, $y - 4, 8.5, true, [1, 1, 1]);
            $cellX += $widths[$i];
        }
        if (!$rows) {
            $rows = array_fill(0, count($widths), '');
            $rows[0] = 'No records';
            $rows = [$rows];
        }
        $rowY = $y - 40;
        foreach ($rows as $row) {
            $c .= self::rect($x, $rowY - 7, array_sum($widths), 22, [1, 1, 1], [0.88, 0.91, 0.95]);
            $cellX = $x;
            foreach ($widths as $i => $width) {
                $c .= self::text(self::clip((string) ($row[$i] ?? ''), max(8, (int) ($width / 5))), $cellX + 8, $rowY, 8.5, false, [0.06, 0.14, 0.25]);
                $cellX += $width;
            }
            $rowY -= 22;
        }
        return $c;
    }

    private static function totalsBox(float $x, float $y, array $totals): string
    {
        $h = 22 + (count($totals) * 18);
        $c = self::rect($x, $y, 200, $h, [1, 1, 1], [0.82, 0.87, 0.93]);
        $lineY = $y + $h - 20;
        foreach ($totals as $label => $value) {
            $bold = stripos((string) $label, 'balance') !== false || stripos((string) $label, 'paid') !== false;
            $c .= self::text((string) $label, $x + 12, $lineY, $bold ? 10 : 9, $bold, [0.06, 0.14, 0.25]);
            $c .= self::text((string) $value, $x + 112, $lineY, $bold ? 10 : 9, $bold, [0.06, 0.14, 0.25]);
            $lineY -= 18;
        }
        return $c;
    }

    private static function stamp(string $text, float $x, float $y): string
    {
        $c = "q 0.10 0.55 0.32 RG 0.90 1.00 0.94 rg 3 w {$x} {$y} 92 34 re B Q\n";
        $c .= self::text($text, $x + 23, $y + 11, 15, true, [0.06, 0.45, 0.25]);
        return $c;
    }

    private static function text(string $text, float $x, float $y, float $size, bool $bold = false, array $rgb = [0, 0, 0]): string
    {
        return sprintf("%.3F %.3F %.3F rg BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $rgb[0], $rgb[1], $rgb[2], $bold ? 'F2' : 'F1', $size, $x, $y, self::pdfText($text));
    }

    private static function rect(float $x, float $y, float $w, float $h, array $fill, array $stroke): string
    {
        return sprintf("q %.3F %.3F %.3F rg %.3F %.3F %.3F RG %.2F %.2F %.2F %.2F re B Q\n", $fill[0], $fill[1], $fill[2], $stroke[0], $stroke[1], $stroke[2], $x, $y, $w, $h);
    }

    private static function clip(string $text, int $length): string
    {
        return strlen($text) > $length ? substr($text, 0, max(0, $length - 3)) . '...' : $text;
    }

    private static function fitBox(int $imageWidth, int $imageHeight, int $maxWidth, int $maxHeight): array
    {
        $scale = min($maxWidth / max(1, $imageWidth), $maxHeight / max(1, $imageHeight));
        return ['w' => $imageWidth * $scale, 'h' => $imageHeight * $scale];
    }

    private static function imageObject(string $path): ?array
    {
        if ($path === '' || !is_file($path)) {
            return null;
        }
        $type = @exif_imagetype($path);
        if ($type === IMAGETYPE_JPEG) {
            return self::jpegObject($path);
        }
        if ($type === IMAGETYPE_PNG) {
            return self::pngObject($path);
        }
        return null;
    }

    private static function jpegObject(string $path): ?array
    {
        $info = @getimagesize($path);
        $data = @file_get_contents($path);
        if (!$info || $data === false) {
            return null;
        }
        return [
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'object' => "<< /Type /XObject /Subtype /Image /Width {$info[0]} /Height {$info[1]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . " >>\nstream\n{$data}\nendstream",
        ];
    }

    private static function pngObject(string $path): ?array
    {
        $png = @file_get_contents($path);
        if ($png === false || substr($png, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }
        $offset = 8;
        $width = $height = $bitDepth = $colorType = null;
        $idat = '';
        $palette = [];
        while ($offset + 8 <= strlen($png)) {
            $length = unpack('N', substr($png, $offset, 4))[1];
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $length);
            $offset += 12 + $length;
            if ($type === 'IHDR') {
                $parts = unpack('Nwidth/Nheight/Cbit/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
                $width = $parts['width'];
                $height = $parts['height'];
                $bitDepth = $parts['bit'];
                $colorType = $parts['color'];
            } elseif ($type === 'PLTE') {
                for ($i = 0; $i + 2 < strlen($data); $i += 3) {
                    $palette[] = substr($data, $i, 3);
                }
            } elseif ($type === 'IDAT') {
                $idat .= $data;
            } elseif ($type === 'IEND') {
                break;
            }
        }
        if (!$width || !$height || $bitDepth !== 8 || !in_array($colorType, [0, 2, 3, 4, 6], true)) {
            return null;
        }
        $raw = @gzuncompress($idat);
        if ($raw === false) {
            return null;
        }
        $rgb = self::pngToRgb($raw, (int) $width, (int) $height, (int) $colorType, $palette);
        if ($rgb === null) {
            return null;
        }
        $compressed = gzcompress($rgb);
        return [
            'width' => (int) $width,
            'height' => (int) $height,
            'object' => "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($compressed) . " >>\nstream\n{$compressed}\nendstream",
        ];
    }

    private static function pngToRgb(string $raw, int $width, int $height, int $colorType, array $palette): ?string
    {
        $channels = match ($colorType) {
            0, 3 => 1,
            2 => 3,
            4 => 2,
            6 => 4,
            default => 0,
        };
        if ($channels === 0) {
            return null;
        }
        $stride = $width * $channels;
        $pos = 0;
        $prev = str_repeat("\0", $stride);
        $rgb = '';
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$pos] ?? "\0");
            $pos++;
            $line = substr($raw, $pos, $stride);
            $pos += $stride;
            $line = self::pngUnfilter($filter, $line, $prev, $channels);
            $prev = $line;
            for ($x = 0; $x < $width; $x++) {
                $i = $x * $channels;
                if ($colorType === 0 || $colorType === 4) {
                    $g = $line[$i] ?? "\0";
                    $rgb .= $g . $g . $g;
                } elseif ($colorType === 3) {
                    $rgb .= $palette[ord($line[$i] ?? "\0")] ?? "\xff\xff\xff";
                } else {
                    $rgb .= substr($line, $i, 3);
                }
            }
        }
        return $rgb;
    }

    private static function pngUnfilter(int $filter, string $line, string $prev, int $bpp): string
    {
        $out = '';
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $x = ord($line[$i]);
            $a = $i >= $bpp ? ord($out[$i - $bpp]) : 0;
            $b = ord($prev[$i] ?? "\0");
            $c = $i >= $bpp ? ord($prev[$i - $bpp] ?? "\0") : 0;
            $value = match ($filter) {
                1 => $x + $a,
                2 => $x + $b,
                3 => $x + intdiv($a + $b, 2),
                4 => $x + self::paeth($a, $b, $c),
                default => $x,
            };
            $out .= chr($value & 0xff);
        }
        return $out;
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    private static function pdfText(string $value): string
    {
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
        $value = preg_replace('/[^\x20-\x7E]/', ' ', $value) ?? $value;
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
