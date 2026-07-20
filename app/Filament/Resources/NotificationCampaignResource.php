<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationCampaignResource\Pages;
use App\Jobs\ProcessNotificationCampaign;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\NotificationCampaign;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use SolutionForest\FilamentTranslateField\Forms\Component\Translate;

class NotificationCampaignResource extends Resource
{
    protected static ?string $model = NotificationCampaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Informações da Campanha')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome da Campanha')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Translate::make([
                            TextInput::make('title')
                                ->label('Título')
                                ->required()
                                ->maxLength(255),
                            Textarea::make('body')
                                ->label('Corpo da Mensagem')
                                ->required()
                                ->rows(4)
                                ->maxLength(1000),
                        ])
                            ->columnSpanFull()
                            ->locales(['en', 'pt-pt']),
                        Fieldset::make('Abertura (apenas Customers)')
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => in_array($get('target_type'), ['customer', 'both']))
                            ->schema([
                                Select::make('open_type')
                                    ->label('Tipo')
                                    ->reactive()
                                    ->nullable()
                                    ->options([
                                        'ServicesType' => 'Tipo de Serviço',
                                        'OperationArea' => 'Área de Operação',
                                    ]),
                                Select::make('open_id')
                                    ->label('Destino')
                                    ->nullable()
                                    ->options(function (Get $get) {
                                        return match ($get('open_type')) {
                                            'ServicesType' => ServicesType::all()->pluck('name', 'id'),
                                            'OperationArea' => OperationArea::all()->pluck('name', 'id'),
                                            default => [],
                                        };
                                    })
                                    ->visible(fn (Get $get) => filled($get('open_type'))),
                            ]),
                    ])
                    ->columns(2),
                Section::make('Público-Alvo e Frequência')
                    ->schema([
                        Select::make('target_type')
                            ->label('Público-Alvo')
                            ->options([
                                'vendor' => 'Apenas Vendors',
                                'customer' => 'Apenas Customers',
                                'both' => 'Vendors e Customers',
                            ])
                            ->required()
                            ->default('both')
                            ->reactive(),
                        Select::make('user_status')
                            ->label('User Status')
                            ->options([
                                'online' => 'Apenas Online',
                                'offline' => 'Apenas Offline',
                                'both' => 'Online e Offline',
                            ])
                            ->nullable(),
                        Select::make('frequency_type')
                            ->label('Frequência')
                            ->options([
                                'once' => 'Enviar Uma Vez',
                                'daily' => 'Diário',
                                'weekly' => 'Semanal',
                                'custom' => 'Intervalo Personalizado',
                            ])
                            ->required()
                            ->default('once')
                            ->reactive(),
                        TextInput::make('frequency_value')
                            ->label('Valor do Intervalo')
                            ->numeric()
                            ->minValue(1)
                            ->visible(fn (Get $get) => $get('frequency_type') === 'custom')
                            ->required(fn (Get $get) => $get('frequency_type') === 'custom'),
                        Select::make('frequency_unit')
                            ->label('Unidade do Intervalo')
                            ->options([
                                'minutes' => 'Minutos',
                                'hours' => 'Horas',
                                'days' => 'Dias',
                            ])
                            ->default('minutes')
                            ->visible(fn (Get $get) => $get('frequency_type') === 'custom')
                            ->required(fn (Get $get) => $get('frequency_type') === 'custom'),
                    ])
                    ->columns(2),
                Section::make('Agendamento')
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label('Data/Hora de Início')
                            ->timezone('Europe/Lisbon')
                            ->nullable(),
                        DateTimePicker::make('ends_at')
                            ->label('Data/Hora de Fim')
                            ->timezone('Europe/Lisbon')
                            ->nullable()
                            ->after('starts_at'),
                    ])
                    ->columns(2),
                Section::make('Estado')
                    ->schema([
                        Toggle::make('is_active')
                            ->label('Ativo')
                            ->default(true)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome da Campanha')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('target_type')
                    ->label('Público-Alvo')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'vendor' => 'Vendors',
                        'customer' => 'Customers',
                        'both' => 'Ambos',
                        default => $state,
                    }),
                TextColumn::make('user_status')
                    ->label('User Status')
                    ->badge()
                    ->default('-'),
                TextColumn::make('frequency_type')
                    ->label('Frequência')
                    ->badge()
                    ->formatStateUsing(function (string $state, $record): string {
                        if ($state === 'custom' && $record->frequency_value && $record->frequency_unit) {
                            $unit = match ($record->frequency_unit) {
                                'minutes' => 'Minutos',
                                'hours' => 'Horas',
                                'days' => 'Dias',
                                default => ucfirst($record->frequency_unit),
                            };

                            return "A cada {$record->frequency_value} {$unit}";
                        }

                        return match ($state) {
                            'once' => 'Uma Vez',
                            'daily' => 'Diário',
                            'weekly' => 'Semanal',
                            'custom' => 'Personalizado',
                            default => $state,
                        };
                    }),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('last_sent_at')
                    ->label('Último Envio')
                    ->dateTime()
                    ->timezone('Europe/Lisbon')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('next_send_at')
                    ->label('Próximo Envio')
                    ->dateTime()
                    ->timezone('Europe/Lisbon')
                    ->placeholder('-')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime()
                    ->timezone('Europe/Lisbon')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('target_type')
                    ->label('Público-Alvo')
                    ->options([
                        'vendor' => 'Vendors',
                        'customer' => 'Customers',
                        'both' => 'Ambos',
                    ]),
                SelectFilter::make('frequency_type')
                    ->label('Frequência')
                    ->options([
                        'once' => 'Uma Vez',
                        'daily' => 'Diário',
                        'weekly' => 'Semanal',
                        'custom' => 'Personalizado',
                    ]),
                TernaryFilter::make('is_active')
                    ->label('Ativo'),
            ])
            ->actions([
                Tables\Actions\Action::make('test')
                    ->label('Testar Agora')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->authorize(fn (): bool => auth()->user()?->hasRole('super-admin') ?? false)
                    ->modalHeading('Testar Campanha de Notificação')
                    ->modalDescription('Isto irá enviar a notificação a todos os utilizadores elegíveis imediatamente. Continuar?')
                    ->action(function (NotificationCampaign $record) {
                        try {
                            // dispatch (não dispatchSync): dispatchSync enviava TODOS os pushes Expo
                            // sincronamente dentro do request Livewire (Guzzle sem timeout) e podia
                            // pendurar o backoffice. Agora corre em fila.
                            ProcessNotificationCampaign::dispatch($record);

                            FilamentNotification::make()
                                ->title('Campanha em Processamento')
                                ->success()
                                ->body('A campanha foi colocada em fila e será enviada aos utilizadores elegíveis.')
                                ->send();
                        } catch (\Exception $e) {
                            FilamentNotification::make()
                                ->title('Erro')
                                ->danger()
                                ->body('Falha ao enviar notificações: '.$e->getMessage())
                                ->send();
                        }
                    }),
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Métricas da Campanha')
                    ->columns(3)
                    ->schema([
                        Infolists\Components\TextEntry::make('open_rate')
                            ->label('Taxa de Abertura')
                            ->state(fn (NotificationCampaign $record) => $record->openRate().'%')
                            ->badge()
                            ->color('info'),
                        Infolists\Components\TextEntry::make('conversion_rate')
                            ->label('Taxa de Conversão')
                            ->state(fn (NotificationCampaign $record) => $record->conversionRate().'%')
                            ->badge()
                            ->color('success'),
                        Infolists\Components\TextEntry::make('opt_out_rate')
                            ->label('Opt-out Rate')
                            ->state(fn (NotificationCampaign $record) => $record->optOutRate().'%')
                            ->badge()
                            ->color('danger'),
                        Infolists\Components\TextEntry::make('revenue_per_send')
                            ->label('Receita por Envio')
                            ->state(fn (NotificationCampaign $record) => '€'.$record->revenuePerSend())
                            ->badge()
                            ->color('warning'),
                        Infolists\Components\TextEntry::make('click_to_service')
                            ->label('Click to Service')
                            ->state(fn (NotificationCampaign $record) => $record->clickToServiceRate().'%')
                            ->badge()
                            ->color('primary'),
                        Infolists\Components\TextEntry::make('total_sent')
                            ->label('Total Enviados')
                            ->state(fn (NotificationCampaign $record) => $record->logs()->where('success', true)->count()),
                        Infolists\Components\TextEntry::make('total_opened')
                            ->label('Total Abertos')
                            ->state(fn (NotificationCampaign $record) => $record->logs()->whereNotNull('opened_at')->count()),
                    ]),
                Infolists\Components\Section::make('Informações')
                    ->columns(2)
                    ->schema([
                        Infolists\Components\TextEntry::make('name')->label('Nome'),
                        Infolists\Components\TextEntry::make('open_type')->label('Tipo de Abertura')->default('-'),
                        Infolists\Components\TextEntry::make('open_id')->label('ID de Abertura')->default('-'),
                        Infolists\Components\TextEntry::make('target_type')->label('Público-Alvo')->badge(),
                        Infolists\Components\TextEntry::make('frequency_type')->label('Frequência')->badge(),
                        Infolists\Components\IconEntry::make('is_active')->label('Ativo')->boolean(),
                        Infolists\Components\TextEntry::make('last_sent_at')->label('Último Envio')->dateTime()->timezone('Europe/Lisbon'),
                        Infolists\Components\TextEntry::make('next_send_at')->label('Próximo Envio')->dateTime()->timezone('Europe/Lisbon'),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationCampaigns::route('/'),
            'create' => Pages\CreateNotificationCampaign::route('/create'),
            'edit' => Pages\EditNotificationCampaign::route('/{record}/edit'),
            'view' => Pages\ViewNotificationCampaign::route('/{record}'),
        ];
    }
}
