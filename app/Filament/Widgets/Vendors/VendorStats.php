<?php

namespace App\Filament\Widgets\Vendors;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Filament\Widgets\Services\Concerns\ComparesPeriods;
use App\Models\Vendor;
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
     * A elegibilidade (Vendor::canAcceptService) tem 8 condições; 7 são SQL, mas
     * a verificação de documentos (all_documents_verified) exige avaliação em PHP.
     * Abordagem híbrida: pré-filtrar em SQL as condições baratas (sem falsos
     * negativos) para reduzir o conjunto, e só depois avaliar o accessor
     * can_accept_service nos candidatos — resultado exato (bate com o filtro da
     * tabela) e barato (o PHP só corre para poucos candidatos).
     */
    protected function eligibleVendorCount(): int
    {
        $candidates = $this->baseVendorQuery()
            ->whereNotNull('iban')
            ->where('invoice_workspace', '!=', '')
            ->where('at_valid', true)
            ->where('at_user', 'like', '%/%')
            ->whereDoesntHave('services', fn ($q) => $q
                ->whereIn('status', [
                    ServiceStatus::ACCEPTED,
                    ServiceStatus::FINISHED,
                    ServiceStatus::ARRIVED,
                ])
                ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PENDING])
                ->whereDoesntHave('schedule'))
            ->get();

        return $candidates->filter->can_accept_service->count();
    }
}
