<?php

namespace App\Filament\Widgets\Customers;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Filament\Widgets\Services\Concerns\ComparesPeriods;
use App\Models\User;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class CustomerStats extends BaseWidget
{
    use ComparesPeriods;

    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        // Chave de cache separada consoante utilizadores de teste sejam contados (developer) ou não.
        $scope = auth()->user()?->hasRole('developer') ? 'with_test' : 'no_test';
        $ttl = now()->addMinutes(10);

        $total = Cache::remember(
            "customers.stats.total.$scope",
            $ttl,
            fn () => $this->baseCustomerQuery()->count()
        );

        $eligible = Cache::remember(
            "customers.stats.eligible.$scope",
            $ttl,
            fn () => $this->eligibleCustomerQuery()->count()
        );

        $newThisMonth = Cache::remember(
            "customers.stats.new_this_month.$scope",
            $ttl,
            fn () => $this->baseCustomerQuery()
                ->where('created_at', '>=', now()->startOfMonth())
                ->count()
        );

        $newLastMonth = Cache::remember(
            "customers.stats.new_last_month.$scope",
            $ttl,
            fn () => $this->baseCustomerQuery()
                ->whereBetween('created_at', [
                    now()->subMonthNoOverflow()->startOfMonth(),
                    now()->subMonthNoOverflow()->endOfMonth(),
                ])
                ->count()
        );

        $percent = $total > 0 ? (int) round($eligible / $total * 100) : 0;

        return [
            Stat::make(__('backoffice/customer.stats.total'), $total)
                ->description(__('backoffice/customer.stats.total_description'))
                ->descriptionIcon('heroicon-m-users', IconPosition::Before)
                ->color('gray'),

            Stat::make(__('backoffice/customer.stats.eligible'), $eligible)
                ->description(__('backoffice/customer.stats.eligible_description', ['percent' => $percent]))
                ->descriptionIcon('heroicon-m-check-circle', IconPosition::Before)
                ->color('success'),

            $this->applyComparison(
                Stat::make(__('backoffice/customer.stats.new_this_month'), $newThisMonth)
                    ->descriptionIcon('heroicon-m-user-plus', IconPosition::Before)
                    ->color('info'),
                $newThisMonth,
                $newLastMonth,
                'previous_month'
            ),
        ];
    }

    /**
     * Base "cliente" — espelha os filtros da listagem em CustomerResource
     * (sem vendor, sem roles de admin, e is_test escondido exceto para developer)
     * para que os totais batam certo com a tabela.
     */
    protected function baseCustomerQuery(): Builder
    {
        return User::query()
            ->whereDoesntHave('vendor')
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['admin', 'super-admin', 'dev']))
            ->when(! auth()->user()?->hasRole('developer'), fn ($q) => $q->where('is_test', false));
    }

    /**
     * Clientes elegíveis para solicitar serviço, expresso em SQL (sem carregar modelos em PHP
     * nem usar o accessor can_request_service). Espelha User::canRequestService()/openServices():
     * tem endereço principal E não tem serviço aberto.
     */
    protected function eligibleCustomerQuery(): Builder
    {
        return $this->baseCustomerQuery()
            ->whereHas('addresses', fn ($q) => $q->where('main_address', true))
            ->whereDoesntHave('services', fn ($q) => $q
                ->whereIn('status', [
                    ServiceStatus::PENDING,
                    ServiceStatus::ACCEPTED,
                    ServiceStatus::FINISHED,
                    ServiceStatus::ARRIVED,
                ])
                ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PENDING])
                ->whereDoesntHave('schedule'));
    }
}
