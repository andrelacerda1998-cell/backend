<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\ServicesResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('backoffice/service.plural');
    }

    public function table(Table $table): Table
    {
        return ServicesResource::table($table);
    }
}
