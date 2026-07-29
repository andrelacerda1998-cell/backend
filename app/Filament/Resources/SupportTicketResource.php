<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportTicketResource\Pages;
use App\Models\SupportTicket;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static ?string $slug = 'support-tickets';

    protected static ?string $label = 'Suporte (técnicos)';

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    public static function getNavigationBadge(): ?string
    {
        $open = static::getModel()::where('status', 'open')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Pedido do técnico')->schema([
                Placeholder::make('vendor')
                    ->label('Técnico')
                    ->content(fn (?SupportTicket $record) => $record?->vendor?->username ?? '—'),
                Placeholder::make('subject_view')
                    ->label('Assunto')
                    ->content(fn (?SupportTicket $record) => $record?->subject ?? '—'),
                Placeholder::make('message_view')
                    ->label('Mensagem')
                    ->content(fn (?SupportTicket $record) => $record?->message ?? '—'),
            ])->columns(1),

            Section::make('Resposta da Piquet')->schema([
                Textarea::make('admin_reply')
                    ->label('Resposta')
                    ->rows(5)
                    ->helperText('O técnico vê esta resposta na app.'),
                Select::make('status')
                    ->label('Estado')
                    ->options([
                        'open' => 'Aberto',
                        'answered' => 'Respondido',
                        'closed' => 'Fechado',
                    ])
                    ->default('open')
                    ->required(),
            ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('vendor.username')->label('Técnico')->searchable(),
                TextColumn::make('subject')->label('Assunto')->limit(40)->searchable(),
                BadgeColumn::make('status')
                    ->label('Estado')
                    ->colors([
                        'warning' => 'open',
                        'success' => 'answered',
                        'gray' => 'closed',
                    ])
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'open' => 'Aberto',
                        'answered' => 'Respondido',
                        'closed' => 'Fechado',
                        default => $state,
                    }),
                TextColumn::make('created_at')->label('Criado')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options([
                    'open' => 'Aberto',
                    'answered' => 'Respondido',
                    'closed' => 'Fechado',
                ]),
            ])
            ->actions([
                \Filament\Tables\Actions\EditAction::make()->label('Responder'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'edit' => Pages\EditSupportTicket::route('/{record}/edit'),
        ];
    }
}
