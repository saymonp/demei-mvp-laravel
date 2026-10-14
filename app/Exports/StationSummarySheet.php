<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class StationSummarySheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Estatísticas resumidas';
    }

    public function headings(): array
    {
        return ['Período', 'Sensor', 'Leituras válidas', 'Média', 'Mínima', 'Máxima', 'Desvio padrão'];
    }

    public function array(): array
    {
        $round = static fn (?float $value): ?float => $value === null ? null : round($value, 2);

        return array_map(
            fn (array $row): array => [
                $row['period'],
                $row['sensor'],
                $row['count'],
                $round($row['avg']),
                $round($row['min']),
                $round($row['max']),
                $round($row['std']),
            ],
            $this->data['summary'],
        );
    }
}
