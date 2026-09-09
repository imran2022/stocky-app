<?php

namespace App\Services;

use App\Models\PosSetting;

/**
 * Builds raw TSPL/TSPL2 command payloads for thermal label printers
 * (TSC, 4BARCODE, Xprinter, Rongta… — anything TSPL-compatible).
 *
 * The barcode is rendered by the printer's own BARCODE command, so bars are
 * printed at native resolution instead of being rasterized from HTML.
 * Coordinates are in dots at 203 dpi (8 dots/mm).
 */
class TsplLabelService
{
    const DOTS_PER_MM = 8;      // 203 dpi
    const PX_TO_DOTS = 2.1167;  // CSS px (96 dpi) -> dots (203 dpi)

    /**
     * TSPL built-in bitmap fonts: name => [char width, char height] in dots.
     * These are the sizes universally supported by TSPL clones.
     */
    const FONTS = [
        '1' => [8, 12],
        '2' => [12, 20],
        '3' => [16, 24],
        '4' => [24, 32],
    ];

    /**
     * @param array      $labels  [{name, barcode, Type_barcode, Net_price, qte}]
     * @param array      $design  {width_mm, height_mm, show_name, show_price,
     *                            show_barcode, show_barcode_number, barcode_height,
     *                            font_size, number_font_size, bold_font, currency}
     * @param PosSetting $printer gap/density/speed/direction come from here
     */
    public function build(array $labels, array $design, PosSetting $printer): string
    {
        $widthMm = $this->clampFloat($design['width_mm'] ?? 50, 20, 200);
        $heightMm = $this->clampFloat($design['height_mm'] ?? 30, 10, 200);
        $gapMm = $this->clampFloat($printer->label_printer_gap_mm ?? 2, 0, 10);
        $density = max(0, min(15, (int) ($printer->label_printer_density ?? 8)));
        $speed = max(1, min(8, (int) ($printer->label_printer_speed ?? 3)));
        $direction = ((int) ($printer->label_printer_direction ?? 0)) === 1 ? 1 : 0;

        $out = "SIZE {$widthMm} mm,{$heightMm} mm\r\n";
        $out .= "GAP {$gapMm} mm,0 mm\r\n";
        $out .= "DIRECTION {$direction}\r\n";
        $out .= "DENSITY {$density}\r\n";
        $out .= "SPEED {$speed}\r\n";
        $out .= "SET TEAR ON\r\n";

        foreach ($labels as $label) {
            $qty = max(1, min(1000, (int) ($label['qte'] ?? 1)));
            $out .= $this->buildOne($label, $design, $widthMm, $heightMm);
            $out .= "PRINT 1,{$qty}\r\n";
        }

        return $out;
    }

    private function buildOne(array $label, array $design, float $widthMm, float $heightMm): string
    {
        $w = (int) round($widthMm * self::DOTS_PER_MM);
        $h = (int) round($heightMm * self::DOTS_PER_MM);
        $margin = self::DOTS_PER_MM; // 1 mm safe margin

        // Missing keys default to visible so a sparse design payload still
        // yields a printable label instead of a blank one.
        $showName = (bool) ($design['show_name'] ?? true);
        $showBarcode = (bool) ($design['show_barcode'] ?? true);
        $showNumber = (bool) ($design['show_barcode_number'] ?? true);
        $showPrice = (bool) ($design['show_price'] ?? true);
        $bold = (bool) ($design['bold_font'] ?? true);

        $nameFont = $this->pickFont((int) ($design['font_size'] ?? 10));
        $priceFont = $nameFont;

        $out = "CLS\r\n";
        $y = $margin;

        // ---- Product name: centered, wrapped to at most 2 lines ----
        if ($showName) {
            [$fw, $fh] = self::FONTS[$nameFont];
            $usable = $w - 2 * $margin;
            $maxChars = max(4, (int) floor($usable / $fw));
            $lines = $this->wrapText($this->sanitize((string) ($label['name'] ?? '')), $maxChars, 2);
            foreach ($lines as $line) {
                $x = max($margin, (int) (($w - strlen($line) * $fw) / 2));
                $out .= $this->text($x, $y, $nameFont, $line, $bold);
                $y += $fh + 2;
            }
            $y += 2;
        }

        // ---- Price line height reserved at the bottom ----
        [$pfw, $pfh] = self::FONTS[$priceFont];
        $priceReserve = $showPrice ? ($pfh + $margin + 2) : $margin;

        // ---- Barcode: centered, printer-rendered ----
        if ($showBarcode) {
            $code = $this->sanitize((string) ($label['barcode'] ?? ''));
            if ($code !== '') {
                $type = $this->mapSymbology((string) ($label['Type_barcode'] ?? 'CODE128'), $code);
                $numberDots = $showNumber ? (self::FONTS['2'][1] + 2) : 0;

                // Requested height in dots, clamped to the space that is left.
                $requested = (int) round($this->clampFloat($design['barcode_height'] ?? 28, 10, 100) * self::PX_TO_DOTS);
                $available = $h - $y - $priceReserve - $numberDots;
                $bcHeight = max(24, min($requested, $available));

                $narrow = 2;
                $estWidth = $this->estimateBarcodeWidth($type, $code, $narrow);
                if ($estWidth > $w - 2 * $margin) {
                    $narrow = 1;
                    $estWidth = $this->estimateBarcodeWidth($type, $code, $narrow);
                }
                $x = max($margin, (int) (($w - $estWidth) / 2));
                $readable = $showNumber ? 2 : 0; // 2 = human-readable text centered below

                $out .= sprintf(
                    "BARCODE %d,%d,\"%s\",%d,%d,0,%d,%d,\"%s\"\r\n",
                    $x, $y, $type, $bcHeight, $readable, $narrow, $narrow * 2, $code
                );
            }
        }

        // ---- Price: bottom-right corner ----
        if ($showPrice) {
            $price = $this->sanitize(trim(($design['currency'] ?? '') . ' ' . ($label['Net_price'] ?? '')));
            if ($price !== '') {
                $x = max($margin, $w - $margin - strlen($price) * $pfw);
                $out .= $this->text($x, $h - $pfh - $margin, $priceFont, $price, $bold);
            }
        }

        return $out;
    }

    /** TEXT command; bold is simulated by overprinting shifted 1 dot right. */
    private function text(int $x, int $y, string $font, string $content, bool $bold): string
    {
        $cmd = sprintf("TEXT %d,%d,\"%s\",0,1,1,\"%s\"\r\n", $x, $y, $font, $content);
        if ($bold) {
            $cmd .= sprintf("TEXT %d,%d,\"%s\",0,1,1,\"%s\"\r\n", $x + 1, $y, $font, $content);
        }
        return $cmd;
    }

    private function pickFont(int $px): string
    {
        if ($px <= 7) return '1';
        if ($px <= 11) return '2';
        if ($px <= 15) return '3';
        return '4';
    }

    private function mapSymbology(string $type, string $code = ''): string
    {
        $mapped = match (strtoupper($type)) {
            'EAN13' => 'EAN13',
            'EAN8' => 'EAN8',
            'UPC', 'UPCA' => 'UPCA',
            'CODE39' => '39',
            default => '128',
        };
        // The printer prints nothing when the data doesn't fit the symbology
        // (wrong digit count for EAN/UPC, lowercase in CODE39…). Fall back to
        // CODE128, which accepts anything, so the label never comes out blank.
        return $this->symbologyFits($mapped, $code) ? $mapped : '128';
    }

    private function symbologyFits(string $mapped, string $code): bool
    {
        return match ($mapped) {
            'EAN13' => (bool) preg_match('/^\d{12,13}$/', $code),
            'EAN8' => (bool) preg_match('/^\d{7,8}$/', $code),
            'UPCA' => (bool) preg_match('/^\d{11,12}$/', $code),
            '39' => (bool) preg_match('#^[0-9A-Z\-. $/+%]+$#', $code),
            default => true,
        };
    }

    /** Rough module-count estimate, used only to center the barcode. */
    private function estimateBarcodeWidth(string $type, string $code, int $narrow): int
    {
        $len = strlen($code);
        $modules = match ($type) {
            'EAN13', 'UPCA' => 95,
            'EAN8' => 67,
            '39' => ($len + 2) * 16,
            default => 11 * ($len + 3) + 2, // CODE128, worst case (code set B)
        };
        return $modules * $narrow;
    }

    /** Wrap on word boundaries into at most $maxLines lines; truncate the rest. */
    private function wrapText(string $text, int $maxChars, int $maxLines): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return [];
        }
        $wrapped = explode("\n", wordwrap($text, $maxChars, "\n", true));
        $lines = array_slice($wrapped, 0, $maxLines);
        if (count($wrapped) > $maxLines) {
            $last = $lines[$maxLines - 1];
            $lines[$maxLines - 1] = substr($last, 0, max(1, $maxChars - 1)) . '.';
        }
        return $lines;
    }

    /**
     * TSPL bitmap fonts are ASCII; strip quotes/backslashes (command syntax)
     * and transliterate anything non-ASCII.
     */
    private function sanitize(string $value): string
    {
        $value = str_replace(['"', '\\'], '', $value);
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii === false) {
            $ascii = preg_replace('/[^\x20-\x7E]/', '?', $value);
        }
        return trim($ascii);
    }

    private function clampFloat($value, float $min, float $max): float
    {
        return max($min, min($max, (float) $value));
    }
}
