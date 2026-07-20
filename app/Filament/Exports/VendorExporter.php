<?php

namespace App\Filament\Exports;

use App\Models\Vendor;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class VendorExporter extends Exporter
{
    protected static ?string $model = Vendor::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('user.name')
                ->label('Nome'),
            ExportColumn::make('user.email')
                ->label('Email'),
            ExportColumn::make('user.phone_number')
                ->label('Telefone'),
            ExportColumn::make('user.nif')
                ->label('NIF'),
            ExportColumn::make('company_name')
                ->label('Empresa'),
            ExportColumn::make('iban')
                ->label('IBAN'),
            ExportColumn::make('status')
                ->label('Estado')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? $state),
            ExportColumn::make('price_rate')
                ->label('Tarifa (€)'),
            ExportColumn::make('at_user')
                ->label('Utilizador AT'),
            ExportColumn::make('at_valid')
                ->label('AT Válido')
                ->formatStateUsing(fn ($state) => $state ? 'Sim' : 'Não'),
            ExportColumn::make('at_validated_at')
                ->label('AT Validado em'),
            ExportColumn::make('invoice_workspace')
                ->label('Workspace Fatura'),
            ExportColumn::make('created_at')
                ->label('Criado em'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Exportação de técnicos concluída. '.number_format($export->successful_rows).' '.str('linha')->plural($export->successful_rows).' exportadas.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('linha')->plural($failedRowsCount).' falharam.';
        }

        return $body;
    }
}
