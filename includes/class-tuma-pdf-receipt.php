<?php

if (!defined('ABSPATH') && PHP_SAPI !== 'cli') {
    exit;
}

/**
 * Small, dependency-free PDF writer for Tuma order receipts.
 *
 * WooCommerce does not ship with a PDF library. Keeping the writer here makes
 * receipt downloads work on ordinary WordPress hosting without Composer.
 */
final class Tuma_PDF_Receipt {
    private const PAGE_WIDTH = 595;
    private const PAGE_HEIGHT = 842;

    public static function generate(array $receipt): string {
        $pages = array();
        $commands = self::page_header($receipt, false);
        $y = 615;

        foreach ($receipt['items'] as $index => $item) {
            if ($y < 155) {
                $commands .= self::page_footer(count($pages) + 1);
                $pages[] = $commands;
                $commands = self::page_header($receipt, true);
                $y = 675;
            }

            $name = self::plain_text($item['name']);
            $quantity = (string) $item['quantity'];
            $unit_price = self::money($item['unit_price'], $receipt['currency']);
            $line_total = self::money($item['total'], $receipt['currency']);

            $commands .= self::text(48, $y, 10, (string) ($index + 1), false);
            $commands .= self::text(70, $y, 10, self::truncate($name, 42), false);
            $commands .= self::text(355, $y, 10, $quantity, false);
            $commands .= self::right_text(475, $y, 10, $unit_price, false);
            $commands .= self::right_text(548, $y, 10, $line_total, false);
            $commands .= "0.88 0.90 0.92 RG 0.5 w 48 " . ($y - 11) . " m 548 " . ($y - 11) . " l S\n";
            $y -= 28;
        }

        if ($y < 260) {
            $commands .= self::page_footer(count($pages) + 1);
            $pages[] = $commands;
            $commands = self::page_header($receipt, true);
            $y = 675;
        }

        $y -= 12;
        $commands .= "0.06 0.58 0.54 rg 345 " . ($y - 82) . " 203 102 re f\n";
        $commands .= self::text(365, $y - 5, 10, 'Items / adjustments', false, '1 1 1');
        $commands .= self::right_text(530, $y - 5, 10, self::money($receipt['subtotal'], $receipt['currency']), false, '1 1 1');
        $commands .= self::text(365, $y - 30, 10, 'Shipping', false, '1 1 1');
        $commands .= self::right_text(530, $y - 30, 10, self::money($receipt['shipping'], $receipt['currency']), false, '1 1 1');
        $commands .= "1 1 1 RG 0.7 w 365 " . ($y - 43) . " m 530 " . ($y - 43) . " l S\n";
        $commands .= self::text(365, $y - 67, 13, 'TOTAL PAID', true, '1 1 1');
        $commands .= self::right_text(530, $y - 67, 13, self::money($receipt['total'], $receipt['currency']), true, '1 1 1');

        $note_y = $y - 120;
        $commands .= self::text(48, $note_y, 10, 'Payment method', true);
        $commands .= self::text(160, $note_y, 10, self::truncate($receipt['payment_method'], 50), false);
        $commands .= self::text(48, $note_y - 22, 10, 'Transaction ID', true);
        $commands .= self::text(160, $note_y - 22, 10, self::truncate($receipt['transaction_id'], 50), false);
        $commands .= self::text(48, $note_y - 54, 10, 'Thank you for your purchase.', true, '0.06 0.58 0.54');
        $commands .= self::page_footer(count($pages) + 1);
        $pages[] = $commands;

        return self::build_document($pages);
    }

    private static function page_header(array $receipt, bool $continued): string {
        $commands = "1 1 1 rg 0 0 595 842 re f\n";
        $commands .= "0.06 0.58 0.54 rg 0 742 595 100 re f\n";
        $commands .= self::text(48, 790, 23, self::truncate($receipt['store_name'], 36), true, '1 1 1');
        $commands .= self::text(48, 768, 9, self::truncate($receipt['store_url'], 75), false, '1 1 1');
        $commands .= self::right_text(548, 790, 21, $continued ? 'RECEIPT (CONTINUED)' : 'PAYMENT RECEIPT', true, '1 1 1');
        $commands .= self::right_text(548, 767, 10, '#' . self::plain_text($receipt['order_number']), false, '1 1 1');

        $commands .= self::text(48, 713, 9, 'BILLED TO', true, '0.37 0.42 0.46');
        $commands .= self::text(48, 691, 12, self::truncate($receipt['customer_name'], 48), true);
        if (!empty($receipt['customer_email'])) {
            $commands .= self::text(48, 673, 9, self::truncate($receipt['customer_email'], 60), false, '0.37 0.42 0.46');
        }
        $commands .= self::right_text(548, 713, 9, 'PAYMENT DATE', true, '0.37 0.42 0.46');
        $commands .= self::right_text(548, 691, 12, self::plain_text($receipt['date']), true);
        $commands .= self::right_text(548, 671, 10, 'PAID', true, '0.06 0.58 0.54');

        $commands .= "0.95 0.96 0.97 rg 40 625 515 28 re f\n";
        $commands .= self::text(48, 635, 9, '#', true, '0.25 0.29 0.33');
        $commands .= self::text(70, 635, 9, 'ITEM', true, '0.25 0.29 0.33');
        $commands .= self::text(350, 635, 9, 'QTY', true, '0.25 0.29 0.33');
        $commands .= self::right_text(475, 635, 9, 'PRICE', true, '0.25 0.29 0.33');
        $commands .= self::right_text(548, 635, 9, 'TOTAL', true, '0.25 0.29 0.33');
        return $commands;
    }

    private static function page_footer(int $page): string {
        return "0.88 0.90 0.92 RG 0.6 w 40 54 m 555 54 l S\n"
            . self::text(48, 35, 8, 'Generated securely by Tuma Payments for WooCommerce', false, '0.06 0.58 0.54')
            . self::right_text(548, 35, 8, 'Page ' . $page, false, '0.45 0.49 0.53');
    }

    private static function text(float $x, float $y, int $size, string $value, bool $bold = false, string $color = '0.13 0.15 0.17'): string {
        $font = $bold ? 'F2' : 'F1';
        return "BT /{$font} {$size} Tf {$color} rg {$x} {$y} Td (" . self::escape($value) . ") Tj ET\n";
    }

    private static function right_text(float $right, float $y, int $size, string $value, bool $bold = false, string $color = '0.13 0.15 0.17'): string {
        $width = strlen(self::plain_text($value)) * $size * 0.51;
        return self::text(max(40, $right - $width), $y, $size, $value, $bold, $color);
    }

    private static function money($amount, string $currency): string {
        return self::plain_text($currency) . ' ' . number_format((float) $amount, 2, '.', ',');
    }

    private static function truncate(string $value, int $length): string {
        return strlen($value) > $length ? substr($value, 0, $length - 3) . '...' : $value;
    }

    private static function plain_text($value): string {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        return preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);
    }

    private static function escape(string $value): string {
        return str_replace(array('\\', '(', ')'), array('\\\\', '\\(', '\\)'), self::plain_text($value));
    }

    private static function build_document(array $pages): string {
        $objects = array(
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        );
        $page_refs = array();
        $next = 5;

        foreach ($pages as $content) {
            $page_id = $next++;
            $content_id = $next++;
            $link_id = $next++;
            $page_refs[] = $page_id . ' 0 R';
            $objects[$page_id] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $content_id . ' 0 R /Annots [' . $link_id . ' 0 R] >>';
            $objects[$content_id] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
            $objects[$link_id] = '<< /Type /Annot /Subtype /Link /Rect [48 28 255 46] /Border [0 0 0] /A << /S /URI /URI (https://tuma.co.ke) >> >>';
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $page_refs) . '] /Count ' . count($page_refs) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array(0);
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        return $pdf;
    }
}
