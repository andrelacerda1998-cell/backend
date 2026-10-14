<?php

namespace App\Filament\Widgets\Vendors;

use App\Enums\Vendors\StatusVendor;
use App\Filament\Widgets\Services\Concerns\ComparesPeriods;
use App\Models\Vendor;
use App\Services\Operacoes\PodemTrabalhar;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class VendorStats extends BaseWidget
{
    use ComparesPeriods;

    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        // Chave de cache separada consoante utilizadores de teste sejam contados (developer) ou não.
        $scope = auth()->user()?->hasRole('developer') ? 'with_test' : 'no_test';
        $ttl = now()->addMinutes(10);

        $total = Cache::remember(
            "vendors.stats.total.$scope",
            $ttl,
            fn () => $this->baseVendorQuery()->count()
        );

        $eligible = Cache::remember(
            "vendors.stats.eligible.$scope",
            $ttl,
            fn () => $this->eligibleVendorCount()
        );

        $online = Cache::remember(
            "vendors.stats.online.$scope",
            $ttl,
            fn () => $this->baseVendorQuery()->where('status', StatusVendor::ONLINE)->count()
        );

        $newThisMonth = Cache::remember(
            "vendors.stats.new_this_month.$scope",
            $ttl,
            fn () => $this->baseVendorQuery()
                ->where('created_at', '>=', now()->startOfMonth())
                ->count()
        );

        $newLastMonth = Cache::remember(
            "vendors.stats.new_last_month.$scope",
            $ttl,
            fn () => $this->baseVendorQuery()
                ->whereBetween('created_at', [
                    now()->subMonthNoOverflow()->startOfMonth(),
                    now()->subMonthNoOverflow()->endOfMonth(),
                ])
                ->count()
        );

        $percent = $total > 0 ? (int) round($eligible / $total * 100) : 0;

        return [
            Stat::make(__('backoffice/vendor.stats.total'), $total)
                ->description(__('backoffice/vendor.stats.total_description'))
                ->descriptionIcon('heroicon-m-users', IconPosition::Before)
                ->color('gray'),

            Stat::make(__('backoffice/vendor.stats.eligible'), $eligible)
                ->description(__('backoffice/vendor.stats.eligible_description', ['percent' => $percent]))
                ->descriptionIcon('heroicon-m-check-circle', IconPosition::Before)
                ->color('success'),

            Stat::make(__('backoffice/vendor.stats.online'), $online)
                ->description(__('backoffice/vendor.stats.online_description'))
                ->descriptionIcon('heroicon-m-signal', IconPosition::Before)
                ->color($online > 0 ? 'success' : 'gray'),

            $this->applyComparison(
                Stat::make(__('backoffice/vendor.stats.new_this_month'), $newThisMonth)
                    ->descriptionIcon('heroicon-m-user-plus', IconPosition::Before)
                    ->color('info'),
                $newThisMonth,
                $newLastMonth,
                'previous_month'
            ),
        ];
    }

    /**
     * Base "profissional" — espelha os filtros da listagem em VendorResource
     * (só vendors com user; is_test escondido exceto para developer) para que
     * os totais batam certo com a tabela.
     */
    protected function baseVendorQuery(): Builder
    {
        return Vendor::query()
            ->whereHas('user')
            ->when(
                ! auth()->user()?->hasRole('developer'),
                fn ($q) => $q->whereHas('user', fn ($u) => $u->where('is_test', false))
            );
    }

    /**
     * Contagem de vendors que podem aceitar serviço.
     *
     * A regra é a do backoffice, em PodemTrabalhar. Esta cópia tinha o seu
     * próprio pré-filtro em SQL e ficou para trás duas vezes: a AT a 30/09
     * (41 elegíveis aqui, 69 no backoffice) e o IBAN e a conta de faturação a
     * 13/10, que continuava a exigir a quem ainda não os tem de dar.
     */
    protected function eligibleVendorCount(): int
    {
        return PodemTrabalhar::ids($this->baseVendorQuery())->count();
    }
}
