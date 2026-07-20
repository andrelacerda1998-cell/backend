<?php

namespace App\Filament\RelationManagers\User;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ImpersonationCodesRelationManager extends RelationManager
{
    protected static string $relationship = 'impersonationCodes';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('backoffice/impersonation.relation_manager.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('generatedBy.name')
                    ->label(__('backoffice/impersonation.relation_manager.columns.generated_by'))
                    ->default('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('backoffice/impersonation.relation_manager.columns.generated_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('backoffice/impersonation.relation_manager.columns.expires_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('used_at')
                    ->label(__('backoffice/impersonation.relation_manager.columns.used_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->sortable(),
                Tables\Columns\TextColumn::make('failed_attempts')
                    ->label(__('backoffice/impersonation.relation_manager.columns.failed_attempts'))
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('backoffice/impersonation.relation_manager.columns.status'))
                    ->badge()
                    ->state(function ($record): string {
                        if ($record->used_at !== null) {
                            return __('backoffice/impersonation.relation_manager.status.used');
                        }
                        if ($record->expires_at->isPast()) {
                            return __('backoffice/impersonation.relation_manager.status.expired');
                        }
                        if ($record->failed_attempts >= 5) {
                            return __('backoffice/impersonation.relation_manager.status.blocked');
                        }
                        return __('backoffice/impersonation.relation_manager.status.active');
                    })
                    ->color(function ($record): string {
                        if ($record->used_at !== null) {
                            return 'gray';
                        }
                        if ($record->expires_at->isPast() || $record->failed_attempts >= 5) {
                            return 'danger';
                        }
                        return 'success';
                    }),
            ])
            ->headerActions([])
            ->actions([])
            ->bulkActions([]);
    }
}
