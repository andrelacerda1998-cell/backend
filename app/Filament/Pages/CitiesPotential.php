<?php

namespace App\Filament\Pages;

use App\Models\GeneralSettings\City;
use App\Models\Vendor;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Ranking de potencial de abertura: onde ha mais oferta de tecnicos. Serve
 * para decidir onde a Piquet deve lancar/expandir. Le dos dados que o tecnico
 * indica no onboarding (available_cities / preferred_cities).
 */
class CitiesPotential extends Page implements HasForms, HasTable
{
    use InteractsWithTable;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static string $view = 'filament.pages.cities-potential';

    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        return auth()->user()->hasRole('super-admin');
    }

    public static function isShouldRegisterNavigation(): bool
    {
        return auth()->user()->hasRole('super-admin');
    }

    public function getTitle(): string|Htmlable
    {
        return __('backoffice/cities_potential.navigation');
    }

    public static function getNavigationLabel(): string
    {
        return __('backoffice/cities_potential.navigation');
    }

    /** Total de tecnicos, para a percentagem de cobertura por cidade. */
    private function totalVendors(): int
    {
        return max(1, Vendor::query()->count());
    }

    protected function getTableQuery(): Builder
    {
        // Tecnico "ativo" = nao esta Offline (esta a receber pedidos).
        $active = DB::table('vendor_available_cities as vac')
            ->join('vendors as v', 'v.id', '=', 'vac.vendor_id')
            ->whereColumn('vac.city_id', 'cities.id')
            ->where('v.status', '!=', 'Offline')
            ->selectRaw('count(distinct v.id)');

        // Categorias com oferta na cidade (tipos de servico dos tecnicos ali).
        // O nome e JSON traduzivel; extrai a lingua do backoffice, com fallback.
        $locale = app()->getLocale();
        $name = "coalesce(".
            "json_unquote(json_extract(st.name, '$.\"{$locale}\"')), ".
            "json_unquote(json_extract(st.name, '$.\"en\"')), st.name)";
        $categories = DB::table('vendor_available_cities as vac2')
            ->join('services_type_vendor as stv', 'stv.vendor_id', '=', 'vac2.vendor_id')
            ->join('services_types as st', 'st.id', '=', 'stv.services_type_id')
            ->whereColumn('vac2.city_id', 'cities.id')
            ->whereNull('st.deleted_at')
            ->whereNull('stv.deleted_at')
            ->selectRaw("group_concat(distinct {$name} order by {$name} separator ', ')");

        return City::query()
            ->select('cities.*')
            ->withCount([
                'availableVendors as available_count',
                'preferredVendors as preferred_count',
            ])
            ->selectSub($active, 'active_count')
            ->selectSub($categories, 'categories');
    }

    public function table(Table $table): Table
    {
        $total = $this->totalVendors();

        return $table
            ->query($this->getTableQuery())
            ->columns([
                TextColumn::make('name')
                    ->label(__('backoffice/cities_potential.table.city'))
                    ->description(fn (City $r) => $r->district)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('available_count')
                    ->label(__('backoffice/cities_potential.table.available'))
                    ->badge()
                    ->color('warning')
                    ->sortable(),
                TextColumn::make('active_count')
                    ->label(__('backoffice/cities_potential.table.active'))
                    ->sortable(),
                TextColumn::make('coverage')
                    ->label(__('backoffice/cities_potential.table.coverage'))
                    ->state(fn (City $r) => round(($r->available_count / $total) * 100, 1).'%')
                    // Ordena pela percentagem = pela contagem (o total e fixo).
                    ->sortable(query: fn (Builder $q, string $dir) => $q->orderBy('available_count', $dir)),
                TextColumn::make('categories')
                    ->label(__('backoffice/cities_potential.table.categories'))
                    ->placeholder('—')
                    ->wrap()
                    ->limit(80),
            ])
            // Por omissao, maior potencial primeiro.
            ->defaultSort('available_count', 'desc');
    }
}
