<?php

namespace App\Providers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Illuminate\Support\ServiceProvider;

class FilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Select::configureUsing(function (Select $component): void {
            $component->native(false);
        });
        DatePicker::configureUsing(function (DatePicker $component): void {
            $component->native(false);
        });
        DateTimePicker::configureUsing(function (DateTimePicker $component): void {
            $component->native(false);
        });
        TimePicker::configureUsing(function (TimePicker $component): void {
            $component->native(false);
        });
        MorphToSelect::configureUsing(function (MorphToSelect $component): void {
            $component->native(false);
            $component->searchable();
            $component->preload();
        });
    }

    public function boot(): void {}
}
