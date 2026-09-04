<?php

namespace App\Services\Customer\Services;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Enums\Schedule\ScheduleDay;
use App\Enums\Services\AddressType;
use App\Enums\Services\ServiceStatus;
use App\Models\Address;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Vendor;
use App\Models\VendorScheduleSearch;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\Builder;
use Meilisearch\Endpoints\Indexes;

/**
 * Service for searching vendors available for scheduled services.
 *
 * Ranking criteria (in order of importance):
 * 1. Distance from schedule address - closer is better
 * 2. Rating for the requested service type - higher is better
 * 3. Auto-accept enabled - vendors with auto-accept rank higher
 * 4. Has availability in the next 15 days
 * 5. Currently online - online vendors rank higher
 */
class ScheduleVendorSearchService
{
    private Address|AddressCoordinatesDTO $address;

    private ServicesType $servicesType;

    private int $limit = 20;

    private int $daysAhead = 15;

    private ?int $serviceDurationMinutes = null;

    /**
     * Search for vendors available for scheduling in the next 15 days.
     *
     * @param  Address  $address  The customer's address for the service
     * @param  ServicesType  $servicesType  The type of service requested
     */
    public function search(Address|AddressCoordinatesDTO $address, ServicesType $servicesType, bool $isTestCustomer = false): Collection
    {
        $this->address = $address;
        $this->servicesType = $servicesType;
        $this->serviceDurationMinutes = $servicesType->time ? (int) $servicesType->time : null;

        // O índice de pesquisa é um acelerador, não a única forma de responder.
        // Quando falha (índice por criar, definições por aplicar, Meilisearch em
        // baixo) o pedido rebentava com 500 e o cliente ficava sem conseguir
        // agendar de todo — que é o que está a acontecer em produção. A pesquisa
        // passa a cair para a base de dados, com os mesmos filtros.
        try {
            $vendorIds = $this->buildSearchQuery()->take($this->limit * 2)->keys();
        } catch (\Throwable $e) {
            // Registar a causa real: o 500 genérico não dizia o que falhou, e sem
            // isto continuaríamos a adivinhar.
            Log::warning('Schedule vendor search fell back to database', [
                'reason' => $e->getMessage(),
                'service_type_id' => $servicesType->id,
            ]);

            $vendorIds = $this->searchInDatabase($isTestCustomer);
        }

        // Fetch full vendor models with relationships
        $vendors = Vendor::with([
            'user',
            'servicesTypes',
            'operationAreas',
            'currentLocation',
            'scheduleAvailable.scheduleDay',
            'averageRating',
            'schedules',
        ])
            ->whereIn('id', $vendorIds)
            ->where('at_valid', true)
            ->whereHas('user', fn ($q) => $q->where('is_test', $isTestCustomer))
            ->get();

        // Filter vendors that have availability in the next 15 days.
        // Um técnico com agenda mal preenchida não pode deitar abaixo a procura
        // toda: fica de fora, com registo, e os outros seguem.
        $vendors = $vendors->filter(function (Vendor $vendor) {
            try {
                return $this->filterByAvailabilityNext15Days(collect([$vendor]))->isNotEmpty();
            } catch (\Throwable $e) {
                Log::warning('Vendor skipped while checking schedule availability', [
                    'vendor_id' => $vendor->id,
                    'reason' => $e->getMessage(),
                ]);

                return false;
            }
        })->values();

        // Maintain Meilisearch ordering
        $vendors = $vendors->sortBy(function ($vendor) use ($vendorIds) {
            return array_search($vendor->id, $vendorIds->toArray());
        })->values();

        return $vendors->take($this->limit);
    }

    /**
     * Set the maximum number of results to return.
     */
    public function setLimit(int $limit): self
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * Set the number of days ahead to check for availability.
     */
    public function setDaysAhead(int $days): self
    {
        $this->daysAhead = $days;

        return $this;
    }

    /**
     * Get available hourly slots for a vendor in the next 15 days.
     * Only returns slots that are not already booked.
     */
    public function getAvailableSlots(Vendor $vendor, ?int $serviceDurationMinutes = null): Collection
    {
        $slots = collect();
        // Slots e horas de schedule são hora local de parede (Portugal). Ancorar today()/now() em
        // Europe/Lisbon para o filtro de slots passados e o limite do dia não desviarem em WET/WEST.
        $today = Carbon::today('Europe/Lisbon');
        $now = Carbon::now('Europe/Lisbon');
        $resolvedDurationMinutes = $serviceDurationMinutes ?? $this->serviceDurationMinutes;
        $requiredMinutes = $this->getRequiredMinutes($resolvedDurationMinutes);

        // Get all existing schedules for the vendor in the next 15 days
        $existingSchedules = $vendor->schedules()
            ->whereDate('scheduled_day', '>=', $today)
            ->whereDate('scheduled_day', '<=', $today->copy()->addDays($this->daysAhead))
            ->whereHas('service', function ($query) {
                $query->whereNotIn('status', [ServiceStatus::REFUSED, ServiceStatus::CANCELED, ServiceStatus::CANCELED_MBWAY, ServiceStatus::REFUSED_MBWAY, ServiceStatus::EXPIRED_MBWAY, ServiceStatus::ARCHIVED, ServiceStatus::PENDING_3DS]);
            })
            ->get();

        // Dias marcados como indisponíveis (folga, doença, férias). Sem isto o
        // técnico só podia desligar o dia da semana INTEIRO e para sempre, ou
        // recusar pedido a pedido — o que lhe estraga a taxa de aceitação por
        // algo que não é recusa. Carregado de uma vez, fora do ciclo.
        $unavailableDays = $vendor->unavailableDays()
            ->whereDate('day', '>=', $today)
            ->whereDate('day', '<=', $today->copy()->addDays($this->daysAhead))
            ->pluck('day')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->all();

        for ($i = 0; $i < $this->daysAhead; $i++) {
            $date = $today->copy()->addDays($i);
            $dayOfWeek = $this->carbonDayToScheduleDay($date);

            // Indisponibilidade pontual manda sobre a disponibilidade semanal.
            if (in_array($date->toDateString(), $unavailableDays, true)) {
                continue;
            }

            // Find availability for this day of week
            $availability = $vendor->scheduleAvailable
                ->first(fn ($sa) => $sa->scheduleDay?->day_name === $dayOfWeek && $sa->is_enabled);

            if (! $availability) {
                continue;
            }

            // Generate hourly slots
            $hourlySlots = $this->generateHourlySlots(
                $date,
                $availability->time_start,
                $availability->time_end,
                $availability->auto_accept,
                $dayOfWeek,
                $existingSchedules,
                $now,
                $requiredMinutes
            );

            $slots = $slots->merge($hourlySlots);
        }

        return $slots;
    }

    /**
     * Generate hourly slots for a given day, excluding booked ones.
     */
    private function generateHourlySlots(
        Carbon $date,
        string $timeStart,
        string $timeEnd,
        bool $autoAccept,
        string $dayName,
        $existingSchedules,
        Carbon $now,
        ?int $requiredMinutes
    ): Collection {
        $slots = collect();

        $availabilityStartTime = $date->copy()->setTimeFromTimeString($timeStart);
        $availabilityEndTime = $date->copy()->setTimeFromTimeString($timeEnd);
        $slotDurationMinutes = $requiredMinutes ?? 60;
        $slotStepMinutes = 30;

        // Get schedules for this specific date
        $daySchedules = $existingSchedules->filter(
            fn ($schedule) => Carbon::parse($schedule->scheduled_day, 'Europe/Lisbon')->isSameDay($date)
        );

        for ($slotStartTime = $availabilityStartTime->copy();
            $slotStartTime->lt($availabilityEndTime);
            $slotStartTime->addMinutes($slotStepMinutes)
        ) {
            $slotEndTime = $slotStartTime->copy()->addMinutes($slotDurationMinutes);
            $slotStart = $slotStartTime->format('H:i');
            $slotEnd = $slotStartTime->copy()->addMinutes($slotStepMinutes)->format('H:i');

            // Skip if this slot is in the past (for today)
            $isEnabled = true;
            if ($date->isToday() && $slotStartTime->lte($now)) {
                $isEnabled = false;
            }

            if ($requiredMinutes !== null && $slotEndTime->gt($availabilityEndTime)) {
                $isEnabled = false;
            }

            // Check if this slot overlaps with any existing schedule
            $isBooked = $daySchedules->contains(function ($schedule) use ($slotStartTime, $slotEndTime) {
                $scheduleStart = Carbon::parse($schedule->scheduled_day.' '.$schedule->scheduled_time_start, 'Europe/Lisbon');
                $scheduleEnd = Carbon::parse($schedule->scheduled_day.' '.$schedule->scheduled_time_end, 'Europe/Lisbon');
                if (! $schedule->is_pending) {
                    $scheduleEnd = $scheduleEnd->copy()->addMinutes(
                        (int) config('services.request.schedule_safety_margin_minutes', 60)
                    );
                }

                // Check for overlap
                return $slotStartTime < $scheduleEnd && $slotEndTime > $scheduleStart;
            });

            if (! $isBooked && $isEnabled) {
                $slots->push([
                    'date' => $date->format('Y-m-d'),
                    'day_name' => $dayName,
                    'time_start' => $slotStart,
                    'time_end' => $slotEnd,
                    'auto_accept' => $autoAccept,
                ]);
            }
        }

        return $slots;
    }

    private function getRequiredMinutes(?int $serviceDurationMinutes): ?int
    {
        if (! $serviceDurationMinutes || $serviceDurationMinutes <= 0) {
            return null;
        }

        return $serviceDurationMinutes + (int) config('services.request.schedule_safety_margin_minutes', 60);
    }

    /**
     * A mesma pesquisa, feita na base de dados.
     *
     * Mais lenta do que o índice, mas responde: técnico válido, com morada de
     * agendamento, que faz este tipo de serviço, com agenda ligada num dos dias
     * relevantes e dentro do raio. A ordem é por distância, como no índice — o
     * resto do ranking (auto-aceitação, nota) fica para o índice, porque aqui
     * custaria mais do que vale.
     *
     * @return Collection<int, int>
     */
    private function searchInDatabase(bool $isTestCustomer): Collection
    {
        $latitude = (float) $this->address->latitude;
        $longitude = (float) $this->address->longitude;
        $radiusMeters = (int) config(
            'services.request.schedule_search_distance',
            config('services.request.new_service_search_distance', 50000),
        );

        $relevantDays = $this->getRelevantDaysOfWeek();

        // Haversine em SQL: a distância que decide o raio e a ordem.
        $distance = '(6371000 * acos(least(1, greatest(-1,'
            .' cos(radians(?)) * cos(radians(addresses.latitude))'
            .' * cos(radians(addresses.longitude) - radians(?))'
            .' + sin(radians(?)) * sin(radians(addresses.latitude))))))';

        return Vendor::query()
            ->select('vendors.id')
            // MIN + agrupar por técnico: quem tenha mais do que uma morada de
            // agendamento apareceria repetido, e o cliente via o mesmo nome duas vezes.
            ->selectRaw('MIN'.$distance.' as distance_meters', [$latitude, $longitude, $latitude])
            ->join('users', 'users.id', '=', 'vendors.user_id')
            ->join('addresses', function ($join) {
                $join->on('addresses.user_id', '=', 'users.id')
                    ->where('addresses.address_type', '=', AddressType::SCHEDULE_ADDRESS->value)
                    ->whereNotNull('addresses.latitude')
                    ->whereNotNull('addresses.longitude');
            })
            ->where('vendors.at_valid', true)
            ->where('users.is_test', $isTestCustomer)
            ->whereHas('servicesTypes', fn ($q) => $q->where('services_types.id', $this->servicesType->id))
            ->whereHas('scheduleAvailable', fn ($q) => $q
                ->where('schedule_available.is_enabled', true)
                ->whereHas('scheduleDay', fn ($d) => $d->whereIn('schedule_days.day_name', $relevantDays)))
            ->groupBy('vendors.id')
            ->havingRaw('distance_meters <= ?', [$radiusMeters])
            ->orderBy('distance_meters')
            ->limit($this->limit * 2)
            ->pluck('vendors.id');
    }

    private function buildSearchQuery(): Builder
    {
        $address = $this->address;
        $servicesType = $this->servicesType;
        $relevantDays = $this->getRelevantDaysOfWeek();

        return VendorScheduleSearch::search('', function (Indexes $meilisearch, string $query, array $options) use ($address, $servicesType, $relevantDays) {
            $latitude = $address->latitude;
            $longitude = $address->longitude;

            // Build filters
            $filters = [];

            // Must have schedule address with valid coordinates
            $filters[] = 'has_schedule_address = true';

            // Filter by distance from customer address
            $filters[] = $this->buildGeoFilter($latitude, $longitude);

            // Filter by service type
            $filters[] = $this->buildServiceTypeFilter($servicesType->id);

            // Must have availability on at least one of the relevant days
            $filters[] = $this->buildDaysAvailabilityFilter($relevantDays);

            $options['filter'] = implode(' AND ', $filters);

            // Sort criteria (order matters for ranking):
            // 1. Distance (ascending - closer is better)
            // 2. Has auto-accept (descending - true first)
            // 3. Is online (descending - online first)
            // 4. Average rating (descending - higher is better)
            $options['sort'] = [
                sprintf('_geoPoint(%F, %F):asc', $latitude, $longitude),
                'has_auto_accept:desc',
                'is_online:desc',
                'average_rating:desc',
            ];

            return $meilisearch->search($query, $options);
        });
    }

    /**
     * Get the unique days of the week for the next 15 days.
     */
    private function getRelevantDaysOfWeek(): array
    {
        $days = [];
        $today = Carbon::today('Europe/Lisbon');

        for ($i = 0; $i < $this->daysAhead; $i++) {
            $date = $today->copy()->addDays($i);
            $dayOfWeek = $this->carbonDayToScheduleDay($date);
            $days[$dayOfWeek] = true;
        }

        return array_keys($days);
    }

    /**
     * Build filter for vendors available on specific days of the week.
     */
    private function buildDaysAvailabilityFilter(array $days): string
    {
        $dayFilters = array_map(
            fn ($day) => sprintf('schedule_availability.day = "%s"', $day),
            $days
        );

        return '('.implode(' OR ', $dayFilters).')';
    }

    private function buildGeoFilter(float $latitude, float $longitude): string
    {
        $distance = config('services.request.schedule_search_distance',
            config('services.request.new_service_search_distance', 50000)
        );

        return sprintf('_geoRadius(%F, %F, %d)', $latitude, $longitude, $distance);
    }

    private function buildServiceTypeFilter(int $serviceTypeId): string
    {
        return sprintf('services_types.id = %d', $serviceTypeId);
    }

    /**
     * Filter vendors that have at least one available slot in the next 15 days.
     */
    private function filterByAvailabilityNext15Days(Collection $vendors): Collection
    {
        return $vendors->filter(function (Vendor $vendor) {
            $slots = $this->getAvailableSlots($vendor);

            return $slots->isNotEmpty();
        });
    }

    /**
     * Convert Carbon day of week to ScheduleDay enum value.
     */
    private function carbonDayToScheduleDay(Carbon $date): string
    {
        return match ($date->dayOfWeek) {
            Carbon::MONDAY => ScheduleDay::MONDAY->value,
            Carbon::TUESDAY => ScheduleDay::TUESDAY->value,
            Carbon::WEDNESDAY => ScheduleDay::WEDNESDAY->value,
            Carbon::THURSDAY => ScheduleDay::THURSDAY->value,
            Carbon::FRIDAY => ScheduleDay::FRIDAY->value,
            Carbon::SATURDAY => ScheduleDay::SATURDAY->value,
            Carbon::SUNDAY => ScheduleDay::SUNDAY->value,
            default => ScheduleDay::MONDAY->value,
        };
    }

    /**
     * Filter vendors that can accept services.
     */
    public static function filterByCanAcceptService(Collection $vendors, ?bool $canAccept = null): Collection
    {
        if ($canAccept === null) {
            return $vendors;
        }

        return $vendors->filter(fn ($vendor) => $vendor->can_accept_service === $canAccept);
    }
}
