<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class StationMeasurementsSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private array $data) {}

    public function title(): string
    {
        return 'Dados padronizados & QC';
    }

    public function headings(): array
    {
        return [
            'Timestamp (UTC)',
            'Timestamp (Local - SP)',
            'ID Estação',
            'Nome Estação',
            'Tipo de Sensor',
            'Valor',
            'Flag (Código)',
            'Status (Texto)',
            'Motivo da Flag',
        ];
    }

    public function array(): array
    {
        $station = $this->data['station'];

        return $this->data['rows']
            ->map(fn (array $row): array => [
                $row['utc'],
                $row['local'],
                $station->id,
                $station->name,
                $row['sensor'],
                $row['value'],
                $row['flag'],
                $row['status'],
                $row['reason'],
            ])
            ->all();
    }
}
