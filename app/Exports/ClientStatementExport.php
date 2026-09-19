<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Customer Account Statement — Excel export (Build M4).
 *
 * $meta carries the header block (customer name/code, opening/closing
 * balance, date range) printed above the ledger table, matching what the
 * PDF export shows. $entries is the ledger produced by
 * App\Services\ClientStatementService::build()['entries']. A bold
 * "Closing Balance" total row is appended after the last data row via
 * AfterSheet — the same figure shown in the header block and on the
 * Customer Statement screen, so it's easy to spot without scrolling back up.
 */
class ClientStatementExport implements FromCollection, ShouldAutoSize, WithEvents, WithHeadings, WithMapping, WithStyles
{
    protected array $entries;

    protected array $meta;

    public function __construct(array $entries, array $meta = [])
    {
        $this->entries = $entries;
        $this->meta = $meta;
    }

    public function collection()
    {
        return collect($this->entries);
    }

    public function headings(): array
    {
        $rows = [];
        $rows[] = ['Customer Account Statement'];
        if (! empty($this->meta['client_name'])) {
            $rows[] = ['Customer:', $this->meta['client_name']];
        }
        if (! empty($this->meta['period'])) {
            $rows[] = ['Period:', $this->meta['period']];
        }
        $rows[] = ['Opening Balance:', $this->meta['opening_balance'] ?? ''];
        $rows[] = ['Closing Balance:', $this->meta['closing_balance'] ?? ''];
        $rows[] = [];
        $rows[] = ['Date', 'Type', 'Ref', 'Description', 'Debit', 'Credit', 'Balance'];

        return $rows;
    }

    public function map($row): array
    {
        return [
            $row['date'],
            $this->typeLabel($row['type']),
            $row['ref'],
            $row['description'],
            $row['debit'] ? number_format((float) $row['debit'], 2, '.', '') : '',
            $row['credit'] ? number_format((float) $row['credit'], 2, '.', '') : '',
            number_format((float) $row['balance'], 2, '.', ''),
        ];
    }

    protected function typeLabel(string $type): string
    {
        return match ($type) {
            'invoice' => 'Invoice',
            'payment' => 'Payment',
            'opening' => 'Opening Balance',
            'opening_payment' => 'Opening Balance Payment',
            'return' => 'Sale Return',
            'refund' => 'Refund',
            'service' => 'Service Job',
            'service_payment' => 'Service Payment',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    public function styles(Worksheet $sheet)
    {
        // Row 1: title. The table header lands on row 7 (5 meta rows + 1 blank).
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A2:A5')->getFont()->setBold(true);
        $sheet->getStyle('A7:G7')->getFont()->setBold(true);
        $sheet->getStyle('A7:G7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F1F5F9');

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                // 7 header/meta rows + one row per entry = the last data row.
                $lastDataRow = 7 + count($this->entries);
                $totalRow = $lastDataRow + 1;

                $sheet->setCellValue("A{$totalRow}", 'Closing Balance');
                $sheet->mergeCells("A{$totalRow}:F{$totalRow}");
                $sheet->setCellValue("G{$totalRow}", $this->meta['closing_balance'] ?? '');

                $sheet->getStyle("A{$totalRow}:G{$totalRow}")->getFont()->setBold(true)->setSize(11);
                $sheet->getStyle("A{$totalRow}:G{$totalRow}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EFF6FF');
                $sheet->getStyle("G{$totalRow}")->getAlignment()->setHorizontal('right');
            },
        ];
    }
}
