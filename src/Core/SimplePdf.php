<?php

declare(strict_types=1);

namespace App\Core;

final class SimplePdf
{
    public static function fromLines(array $lines, string $title = 'Receipt'): string
    {
        $safeLines = [];
        foreach ($lines as $line) {
            $safeLines[] = self::escape((string) $line);
        }

        $content = "BT\n/F1 11 Tf\n";
        $y = 780;
        $content .= "1 0 0 1 40 {$y} Tm\n";
        $firstLine = $safeLines[0] ?? self::escape($title);
        $content .= "/F1 16 Tf\n({$firstLine}) Tj\n";
        $content .= "/F1 11 Tf\n";
        $y -= 24;

        foreach ($safeLines as $index => $line) {
            if ($index === 0) {
                continue;
            }
            $content .= "1 0 0 1 40 {$y} Tm\n({$line}) Tj\n";
            $y -= 16;
            if ($y < 50) {
                break;
            }
        }
        $content .= "ET\n";

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n{$content}endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n{$object}\nendobj\n";
        }

        $xrefPos = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root 1 0 R >>' . "\n";
        $pdf .= 'startxref' . "\n" . $xrefPos . "\n%%EOF";

        return $pdf;
    }

    private static function escape(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        return preg_replace('/[^\x20-\x7E]/', '', $text) ?? '';
    }
}
