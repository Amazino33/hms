<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProductTemplateExport implements FromArray, WithHeadings, ShouldAutoSize, WithStyles
{
    // 1. Define a property to hold the incoming warehouse name
    protected string $warehouseName;

    // 2. The Constructor receives the name when we call "new ProductTemplateExport(...)"
    public function __construct(string $warehouseName)
    {
        $this->warehouseName = $warehouseName;
    }

    public function headings(): array
    {
        return [
            'name',
            'sku',
            'category_id',
            'price',
            'cost',
            'quantity',
            'warehouse', // 👈 The new column
        ];
    }

    public function array(): array
    {
        return [
            // Row 1: Uses the selected warehouse
            ['Gulder', '2345', 'beer', 1200, 1000, 50, $this->warehouseName],
            
            // Row 2: Uses the selected warehouse
            ['Coconut Rice', '6668', 'rice', 2800, 2400, 0, $this->warehouseName],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['argb' => 'FF2563EB'], 
                ],
            ],
        ];
    }
}