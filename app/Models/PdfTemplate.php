<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfTemplate extends Model
{
    protected $fillable = ['doc_type', 'settings'];

    protected $casts = ['settings' => 'array'];

    /**
     * Everything the Invoice PDF customizer can change, with the values the
     * shipped templates were designed around. Unknown/missing keys always fall
     * back here, so old saves survive new options.
     */
    public const DEFAULTS = [
        // Colors
        'primary_color' => '#1a56db',
        'secondary_color' => '#3b82f6',
        'text_color' => '#1f2937',
        'background_color' => '#ffffff',
        // Typography (DomPDF-safe families only)
        'font_family' => 'DejaVu Sans',
        'font_size' => 9,          // pt
        // Layout
        'margin_v' => 10,          // mm (top/bottom)
        'margin_h' => 15,          // mm (left/right)
        'logo_show' => true,
        'logo_width' => 120,       // px
        'logo_height' => 60,       // px (max-height cap; aspect ratio is kept)
        // Table
        'table_borders' => true,
        'table_striped' => true,
        // Sections
        'show_status' => true,     // status / payment badges in the header
        'show_customer' => true,   // bill-to block
        'show_company' => true,    // from block
        'show_notes' => true,
        'show_footer_text' => true,
        'show_thank_you' => true,
        // Sale-only: the client's outstanding balance from other sales, and
        // that balance plus this sale's own due. No effect on quotation/
        // purchase PDFs (they have no "previous dues" concept) — harmless
        // to carry on every doc type's settings row for schema simplicity.
        'show_previous_dues' => true,
        'show_net_balance' => true,
        // Sale-only: this invoice's own Due Date (Payment Terms & Due Dates,
        // Build M1). No effect when the feature is off in Settings, or on
        // quotation/purchase PDFs — harmless to carry on every doc type's
        // settings row for schema simplicity, same as the two rows above.
        'show_due_date' => true,
        // Sale-only: Warehouse / Tracking Ref / Zone / Courier block (Build
        // M6). Same fields already shown on the Sale Detail page's header
        // card — printed on the invoice only when at least one of the four
        // is actually set on the sale. No effect on quotation/purchase
        // PDFs (harmless to carry on every doc type's settings row, same as
        // the three rows above).
        'show_delivery_info' => true,
        // Text overrides ('' = keep the translated default)
        'labels' => [
            'title' => '',
            'thank_you' => '',
        ],
        'footer_text' => '',       // '' = use System Settings invoice footer
        // Which Blade file renders this doc type's PDF/print HTML. Only
        // 'sale' currently has a second option — 'modern' picks
        // resources/views/pdf/sale_pdf_modern.blade.php (a self-styled
        // layout with its own colors/fonts; the Colors/Typography/Layout &
        // logo/Items table panels above only affect 'classic'). Unknown
        // values fall back to 'classic' wherever the layout is resolved.
        'layout' => 'classic',
    ];

    public const TYPES = ['sale', 'quotation', 'purchase'];

    /** doc_type => [layout key => display label]. Types not listed only have 'classic'. */
    public const LAYOUTS = [
        'sale' => [
            'classic' => 'Classic',
            // Build N3: was "Modern (with Shipping Label)" — stale even
            // before this rename, since Build M5 removed the embedded
            // shipping-label section from this layout; the standalone
            // Shipping Label PDF (shipping_label.blade.php) is unaffected.
            'modern' => 'Modern',
        ],
    ];

    /** Saved settings for a document type, merged over the defaults. */
    public static function settingsFor(string $type): array
    {
        static $cache = [];
        if (isset($cache[$type])) {
            return $cache[$type];
        }
        $saved = [];
        try {
            $row = static::where('doc_type', $type)->first();
            $saved = is_array($row?->settings) ? $row->settings : [];
        } catch (\Throwable $e) {
            // Table not migrated yet — render with defaults.
        }
        $merged = array_replace(self::DEFAULTS, $saved);
        $merged['labels'] = array_replace(self::DEFAULTS['labels'], (array) ($saved['labels'] ?? []));

        return $cache[$type] = $merged;
    }
}
