<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SentNotificationResource\Pages;
use App\Models\User;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;

class SentNotificationResource extends Resource
{
    protected static ?string $model = DatabaseNotification::class;

    protected static ?string $slug = 'sent-notifications';

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole('admin', 'super-admin') ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('notifiable.name')
                    ->label(__('backoffice/sent_notification.table.recipient'))
                    ->default('-')
                    ->url(fn (DatabaseNotification $record): ?string => static::recipientUrl($record))
                    ->color('primary')
                    ->searchable(),
                TextColumn::make('recipient_type')
                    ->label(__('backoffice/sent_notification.table.recipient_type'))
                    ->badge()
                    ->getStateUsing(fn (DatabaseNotification $record): string => static::recipientType($record))
                    ->color(fn (string $state): string => $state === __('backoffice/sent_notification.recipient_types.vendor') ? 'warning' : 'success'),
                TextColumn::make('type')
                    ->label(__('backoffice/sent_notification.table.type'))
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '-')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('title')
                    ->label(__('backoffice/sent_notification.table.title'))
                    ->getStateUsing(fn (DatabaseNotification $record): string => $record->data['title'] ?? '-')
                    ->wrap(),
                TextColumn::make('read_at')
                    ->label(__('backoffice/sent_notification.table.status'))
                    ->badge()
                    ->getStateUsing(fn (DatabaseNotification $record): string => $record->read_at
                        ? __('backoffice/sent_notification.status.read')
                        : __('backoffice/sent_notification.status.unread'))
                    ->color(fn (DatabaseNotification $record): string => $record->read_at ? 'gray' : 'success'),
                TextColumn::make('created_at')
                    ->label(__('backoffice/sent_notification.table.created_at'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('backoffice/sent_notification.filters.type'))
                    ->options(fn (): array => static::typeOptions()),
                TernaryFilter::make('read_at')
                    ->label(__('backoffice/sent_notification.filters.read'))
                    ->nullable()
                    ->trueLabel(__('backoffice/sent_notification.status.read'))
                    ->falseLabel(__('backoffice/sent_notification.status.unread'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('read_at'),
                        false: fn (Builder $query) => $query->whereNull('read_at'),
                        blank: fn (Builder $query) => $query,
                    ),
            ])
            ->actions([
                ViewAction::make()->url(fn (DatabaseNotification $record): string => static::getUrl('view', ['record' => $record])),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Details')
                ->heading(__('backoffice/sent_notification.infolist.details'))
                ->columns(2)
                ->schema([
                    TextEntry::make('notifiable.name')
                        ->label(__('backoffice/sent_notification.infolist.recipient'))
                        ->default('-')
                        ->url(fn (DatabaseNotification $record): ?string => static::recipientUrl($record))
                        ->color('primary'),
                    TextEntry::make('recipient_type')
                        ->label(__('backoffice/sent_notification.infolist.recipient_type'))
                        ->badge()
                        ->state(fn (DatabaseNotification $record): string => static::recipientType($record)),
                    TextEntry::make('type')
                        ->label(__('backoffice/sent_notification.infolist.type'))
                        ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '-'),
                    TextEntry::make('read_at')
                        ->label(__('backoffice/sent_notification.infolist.read_at'))
                        ->dateTime('d/m/Y H:i')
                        // placeholder (não default): mostra '-' quando read_at é null SEM passar
                        // pelo formatador de data. Com default('-'), o '-' era parseado como data
                        // (Carbon) e rebentava a página com 500 em notificações não lidas.
                        ->placeholder('-'),
                    TextEntry::make('created_at')
                        ->label(__('backoffice/sent_notification.infolist.created_at'))
                        ->dateTime('d/m/Y H:i'),
                ]),
            Section::make('Content')
                ->heading(__('backoffice/sent_notification.infolist.content'))
                ->columns(1)
                ->schema([
                    TextEntry::make('data_title')
                        ->label(__('backoffice/sent_notification.infolist.title'))
                        ->state(fn (DatabaseNotification $record): string => $record->data['title'] ?? '-'),
                    TextEntry::make('data_body')
                        ->label(__('backoffice/sent_notification.infolist.body'))
                        ->state(fn (DatabaseNotification $record): string => $record->data['body'] ?? '-'),
                    TextEntry::make('data')
                        ->label(__('backoffice/sent_notification.infolist.payload'))
                        ->state(fn (DatabaseNotification $record): string => json_encode($record->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('notifiable');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSentNotifications::route('/'),
            'view' => Pages\ViewSentNotification::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return __('backoffice/sent_notification.singular');
    }

    public static function getPluralLabel(): string
    {
        return __('backoffice/sent_notification.plural');
    }

    /**
     * "Cliente" ou "Prestador" conforme o destinatário (User) da notificação.
     */
    protected static function recipientType(DatabaseNotification $record): string
    {
        $notifiable = $record->notifiable;

        if ($notifiable instanceof User && $notifiable->isVendor()) {
            return __('backoffice/sent_notification.recipient_types.vendor');
        }

        return __('backoffice/sent_notification.recipient_types.customer');
    }

    /**
     * Link para o CustomerResource/VendorResource conforme o destinatário.
     */
    protected static function recipientUrl(DatabaseNotification $record): ?string
    {
        $notifiable = $record->notifiable;

        if (! $notifiable instanceof User) {
            return null;
        }

        if ($notifiable->isVendor()) {
            $vendorId = $notifiable->vendor?->id;

            return $vendorId ? VendorResource::getUrl('view', ['record' => $vendorId]) : null;
        }

        return CustomerResource::getUrl('view', ['record' => $notifiable->id]);
    }

    /**
     * @return array<string,string> map type completo => nome curto da classe
     */
    protected static function typeOptions(): array
    {
        return DatabaseNotification::query()
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->mapWithKeys(fn (string $type): array => [$type => class_basename($type)])
            ->all();
    }
}
