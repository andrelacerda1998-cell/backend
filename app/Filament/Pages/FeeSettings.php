<?php

namespace App\Filament\Pages;

use App\Settings\RateSettings;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SettingsPage;
use Illuminate\Contracts\Support\Htmlable;

class FeeSettings extends SettingsPage
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $settings = RateSettings::class;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        return $user->hasRole('super-admin');
    }

    /**
     * @return bool
     */
    public static function isShouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        return $user->hasRole('super-admin');
    }

    public function getTitle(): string|Htmlable
    {
        return __('backoffice/fees_settings.navigation');
    }
    public static function getNavigationLabel(): string
    {
        return __('backoffice/fees_settings.navigation');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('backoffice/navigation.general_settings');
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('backoffice/fees_settings.form.time_settings'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('daytime')
                            ->integer()
                            ->required()
                            ->label('08:00 - 17:59')
                            ->suffix('%'),
                        Forms\Components\TextInput::make('evening')
                            ->integer()
                            ->required()
                            ->label('18:00 - 20:59')
                            ->suffix('%'),
                        Forms\Components\TextInput::make('night')
                            ->integer()
                            ->required()
                            ->label('21:00 - 23:59')
                            ->suffix('%'),
                        Forms\Components\TextInput::make('late_night')
                            ->integer()
                            ->required()
                            ->label('00:00 - 02:59')
                            ->suffix('%'),
                        Forms\Components\TextInput::make('midnight')
                            ->integer()
                            ->required()
                            ->label('03:00 - 07:59')
                            ->suffix('%'),
                    ]),
                Forms\Components\Section::make(__('backoffice/fees_settings.form.rate_settings'))
                    ->columns(2)
                    ->schema([

                        Forms\Components\TextInput::make('kilometer_price')
                            ->label(__('backoffice/fees_settings.form.kilometer_price'))
                            ->numeric()
                            ->required()
                            ->helperText(__('backoffice/fees_settings.form.price_per_kilometer'))
                            ->dehydrateStateUsing(function (string $state) {
                                return $state * 100;
                            })
                            ->formatStateUsing(function ($state) {
                                return $state / 100;
                            }),
                        Forms\Components\TextInput::make('system_commission')
                            ->label(__('backoffice/fees_settings.form.system_commission'))
                            ->integer()
                            ->required()
                            ->suffix('%'),

                    ]),
            ]);
    }
}
