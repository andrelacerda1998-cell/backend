<?php

namespace App\Models;

use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\Auth\ImpersonationCode;
use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\City;
use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\GeneralSettings\SurveyCity;
use App\Models\GeneralSettings\VendorServiceTypes;
use App\Models\Schedule\Schedule;
use App\Models\Schedule\ScheduleAvailable;
use App\Models\Vendor\Location;
use App\Models\Vendor\Ratings;
use App\Models\Vendor\VendorDocuments;
use App\Models\Vendor\VendorUnavailableDay;
use App\Observers\VendorObserver;
use Bavix\Wallet\Models\Transaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Contracts\Auditable;

#[ObservedBy(VendorObserver::class)]
class Vendor extends Model implements Auditable
{
    use HasFactory, \OwenIt\Auditing\Auditable, Searchable, SoftDeletes;

    protected $fillable = ['user_id', 'status', 'price_rate', 'username', 'invoice_workspace', 'auth_token', 'company_name', 'invoice_account_id', 'at_user', 'at_password', 'iban', 'notification_preferences'];

    protected $appends = ['can_accept_service', 'price_rate', 'full_name', 'invoice_workspace_ready'];

    protected $with = ['user', 'servicesTypes', 'operationAreas', 'currentLocation'];

    protected $casts = [
        'status' => StatusVendor::class,
        'auth_token' => 'encrypted',
        'at_password' => 'encrypted',
        'at_valid' => 'boolean',
        'at_validated_at' => 'datetime',
        'notification_preferences' => 'array',
    ];

    protected $hidden = [
        'auth_token',
        'invoice_account_id',
        'at_user',
        'at_password',
    ];

    protected $auditExclude = [
        'at_password',
    ];

    /** Tipos de notificação que o técnico pode ligar/desligar (ver NotificationSettingsController). */
    public const NOTIFICATION_PREFERENCE_KEYS = ['new_requests', 'schedule_reminders', 'messages', 'payments', 'news'];

    /**
     * O técnico quer receber push deste tipo de notificação?
     *
     * Por omissão devolve SEMPRE true: coluna a null, chave inexistente ou valor
     * desconhecido significam "recebe tudo". Nunca silenciamos por omissão —
     * um pedido silenciado é um pedido perdido.
     */
    public function shouldReceive(string $preference): bool
    {
        $prefs = $this->notification_preferences;

        if (! is_array($prefs) || ! array_key_exists($preference, $prefs)) {
            return true;
        }

        return filter_var($prefs[$preference], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function getNameAttribute(): string
    {
        return $this->user->full_name;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function servicesTypes(): BelongsToMany
    {
        return $this->belongsToMany(ServicesType::class)->using(VendorServiceTypes::class)->whereNull('services_types.deleted_at')->wherePivot('services_type_vendor.deleted_at', null);
    }

    public function canAcceptService(): Attribute
    {
        return Attribute::make(get: function () {
            return $this->user?->hasVerifiedPhoneNumber() &&
                $this->user?->hasVerifiedEmail() &&
                $this->all_documents_verified &&
                ! $this->openServices()->exists() &&
                $this->iban != null &&
                $this->invoice_workspace != null &&
                $this->at_valid &&
                str_contains($this->at_user, '/');
        })->shouldCache();
    }

    /**
     * Whether the billing workspace has been created for this vendor.
     *
     * The workspace itself is created by the Piquet team from the backoffice
     * (CompanySection -> create_invoice_workspace), so the vendor cannot act on
     * it. We expose a boolean — never the workspace id — so the app can tell the
     * vendor why they still cannot go online instead of leaving them stuck.
     */
    public function invoiceWorkspaceReady(): Attribute
    {
        return Attribute::make(get: fn () => $this->invoice_workspace != null)->shouldCache();
    }

    /**
     * Translated reasons for each failing condition of canAcceptService().
     * Must stay consistent with the checks in canAcceptService().
     */
    public function cannotAcceptServiceReasons(): Collection
    {
        $reasons = collect();

        if (! $this->user?->hasVerifiedPhoneNumber()) {
            $reasons->push(__('backoffice/vendor.infolist.eligibility.phone_not_verified'));
        }

        if (! $this->user?->hasVerifiedEmail()) {
            $reasons->push(__('backoffice/vendor.infolist.eligibility.email_not_verified'));
        }

        if (! $this->all_documents_verified) {
            $expiredNames = $this->documents()
                ->where('status', 'approved')
                ->whereNotNull('expiration_date')
                // Só está expirado a partir do dia SEGUINTE ao último dia de validade
                // (espelho exato de allDocumentsVerified(), que aceita >= hoje).
                ->whereDate('expiration_date', '<', now()->toDateString())
                ->get()
                ->map(fn ($document) => $document->type?->name)
                ->filter()
                ->unique();

            $pendingNames = $this->pending_documents->pluck('name')->filter()->unique();

            $missingNames = $this->missing_documents->pluck('name')->filter()
                ->diff($expiredNames)
                ->unique();

            $reason = __('backoffice/vendor.infolist.eligibility.documents_not_verified');

            if ($missingNames->isNotEmpty()) {
                $reason .= ' '.__('backoffice/vendor.infolist.eligibility.documents_missing', ['documents' => $missingNames->join(', ')]);
            }

            if ($pendingNames->isNotEmpty()) {
                $reason .= ' '.__('backoffice/vendor.infolist.eligibility.documents_pending', ['documents' => $pendingNames->join(', ')]);
            }

            if ($expiredNames->isNotEmpty()) {
                $reason .= ' '.__('backoffice/vendor.infolist.eligibility.documents_expired', ['documents' => $expiredNames->join(', ')]);
            }

            $reasons->push($reason);
        }

        if ($this->openServices()->exists()) {
            $reasons->push(__('backoffice/vendor.infolist.eligibility.open_service'));
        }

        if ($this->iban == null) {
            $reasons->push(__('backoffice/vendor.infolist.eligibility.no_iban'));
        }

        if ($this->invoice_workspace == null) {
            $reasons->push(__('backoffice/vendor.infolist.eligibility.no_workspace'));
        }

        if (! $this->at_valid) {
            $reasons->push(__('backoffice/vendor.infolist.eligibility.at_invalid'));
        }

        if (! str_contains($this->at_user ?? '', '/')) {
            $reasons->push(__('backoffice/vendor.infolist.eligibility.at_user_invalid'));
        }

        return $reasons;
    }

    public function fullName(): Attribute
    {
        return Attribute::make(get: function () {
            return $this->user?->full_name;
        });
    }

    public function openServices(): HasMany
    {
        return $this->services()->whereIn('status', [
            // ServiceStatus::PENDING,
            ServiceStatus::ACCEPTED,
            ServiceStatus::FINISHED,
            ServiceStatus::ARRIVED,
        ])
            ->whereDoesntHave('schedule')
            ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PENDING]);
    }

    public function addresses(): HasManyThrough
    {
        return $this->hasManyThrough(Address::class, User::class, 'id', 'user_id', 'user_id');
    }

    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(Transaction::class, User::class, 'id', 'payable_id', 'user_id');
    }

    public function impersonationCodes(): HasManyThrough
    {
        return $this->hasManyThrough(
            ImpersonationCode::class,
            User::class,
            'id',
            'user_id',
            'user_id',
        );
    }

    public function operationAreas(): BelongsToMany
    {
        return $this->belongsToMany(OperationArea::class, 'operation_area_vendors');
    }

    public function surveyCityVotes(): BelongsToMany
    {
        return $this->belongsToMany(SurveyCity::class, 'vendor_city_votes')->withTimestamps();
    }

    /** Dias de indisponibilidade pontual (folga, doença, férias). */
    public function unavailableDays(): HasMany
    {
        return $this->hasMany(VendorUnavailableDay::class);
    }

    /**
     * Tem este bloco livre na agenda?
     *
     * Duas perguntas:
     *  1. marcou este dia como indisponível? (folga pontual manda sobre tudo)
     *  2. já tem alguma coisa marcada que se sobreponha?
     *
     * Havia uma terceira — "trabalha a esta hora, neste dia da semana?", lida
     * do `schedule_available` — e saiu a 15/09/2026. O horário declarado é uma
     * PREVISÃO feita uma vez, no registo; o convite que o profissional recebe
     * traz o serviço, o valor, a morada e a hora, e o "Aceitar" é uma DECISÃO
     * sobre esse trabalho concreto. A previsão estava a vetar a decisão: quem
     * tivesse o sábado desligado em julho não era sequer convidado em setembro,
     * mesmo estando em casa sem nada para fazer. Quem não quer, recusa.
     *
     * O que continua a vetar é o que é facto e não palpite: férias marcadas, e
     * estar noutro sítio à mesma hora. Ninguém pode estar em dois sítios ao
     * mesmo tempo — isso não é preferência.
     *
     * Quem decide agora se recebe convites é o botão Online/Offline da Home da
     * app do profissional, que ele controla em dois toques.
     *
     * A margem de segurança (schedule_safety_margin_minutes) só se aplica a
     * marcações confirmadas: um agendamento ainda pendente não deve reservar
     * tempo de deslocação que talvez nunca seja preciso.
     */
    /**
     * As candidaturas deste profissional — ver docs/matching.md.
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(ServiceCandidate::class);
    }

    /**
     * Horários que ele próprio reservou ao dizer que tinha interesse.
     *
     * Dizer "tenho interesse" num agendado é assumir aquele horário enquanto o
     * cliente decide. Sem isto, ele continuava disponível: um segundo cliente
     * passava o portão, pagava, e ficavam dois pagamentos para a mesma hora da
     * mesma pessoa — confirmado por sonda na auditoria.
     *
     * A reserva dura o que a candidatura durar (`expires_at`: 180s no imediato,
     * 1200s no agendado) e cai sozinha quando ele deixa de estar em `accepted`
     * — porque recusou, porque o cliente escolheu outro, ou porque o prazo
     * passou. Não é preciso mecanismo novo a libertá-la.
     *
     * @return array<int, array{0: CarbonInterface, 1: CarbonInterface}>
     */
    private function heldSlots(CarbonInterface $day, ?int $ignoreServiceId = null): array
    {
        return $this->candidates()
            ->accepted()
            ->where('expires_at', '>', now())
            // O próprio pedido que está a ser avaliado não se bloqueia a si
            // mesmo: quando o cliente o escolhe, a reverificação pergunta se a
            // hora está livre — e a resposta não pode ser "não, por causa da
            // reserva que este mesmo pedido criou".
            ->when($ignoreServiceId, fn ($q) => $q->where('service_id', '!=', $ignoreServiceId))
            ->with('service')
            ->get()
            ->map(function (ServiceCandidate $candidate) {
                $service = $candidate->service;
                $intent = $service?->scheduleIntent();
                $minutes = $service?->durationMinutes();

                if (! $intent || ! $intent['scheduled_day'] || ! $intent['scheduled_time_start'] || ! $minutes) {
                    return null;
                }

                // O `scheduled_time_start` tanto vem como "14:30" como datetime
                // completo, conforme o caminho que gravou o pedido. Concatenar
                // às cegas dava "2026-09-29 2026-09-29 10:00:00" e rebentava.
                // Dia e hora parseados em separado, como no resto do código.
                $start = Carbon::parse($intent['scheduled_day'])
                    ->setTimeFrom(Carbon::parse($intent['scheduled_time_start']));

                return [$start, $start->copy()->addMinutes($minutes)];
            })
            ->filter()
            ->filter(fn (array $janela) => $janela[0]->isSameDay($day))
            ->values()
            ->all();
    }

    public function hasFreeSlot(CarbonInterface $start, CarbonInterface $end, ?int $ignoreServiceId = null): bool
    {
        if ($this->isUnavailableOn($start)) {
            return false;
        }

        // Sem margem de segurança nas reservas: ainda ninguém pagou, e reservar
        // tempo de deslocação para um trabalho que talvez não aconteça tirava-lhe
        // convites a troco de nada. A margem entra quando a marcação é confirmada.
        foreach ($this->heldSlots($start, $ignoreServiceId) as [$reservaInicio, $reservaFim]) {
            if ($start->lt($reservaFim) && $end->gt($reservaInicio)) {
                return false;
            }
        }

        $margin = (int) config('services.request.schedule_safety_margin_minutes', 60);

        return ! $this->schedules()
            ->whereDate('scheduled_day', $start->toDateString())
            ->get()
            ->contains(function ($schedule) use ($start, $end, $margin) {
                $busyStart = Carbon::parse($schedule->scheduled_day.' '.$schedule->scheduled_time_start);
                $busyEnd = Carbon::parse($schedule->scheduled_day.' '.$schedule->scheduled_time_end);

                if (! $schedule->is_pending) {
                    $busyEnd = $busyEnd->copy()->addMinutes($margin);
                }

                return $start->lt($busyEnd) && $end->gt($busyStart);
            });
    }

    /** Está indisponível neste dia concreto, apesar da disponibilidade semanal? */
    public function isUnavailableOn(CarbonInterface|string $day): bool
    {
        $date = $day instanceof CarbonInterface ? $day->toDateString() : (string) $day;

        return $this->unavailableDays()->whereDate('day', $date)->exists();
    }

    public function allowedZones(): BelongsToMany
    {
        return $this->belongsToMany(AllowedZone::class, 'vendor_allowed_zones')->withTimestamps();
    }

    /** Cidades onde o tecnico aceita prestar servico (todas). */
    public function availableCities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'vendor_available_cities')->withTimestamps();
    }

    /** Top 3 de cidades de maior interesse do tecnico (subconjunto das available). */
    public function preferredCities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'vendor_preferred_cities')
            ->withPivot('position')
            ->orderByPivot('position')
            ->withTimestamps();
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function currentLocation(): HasOne
    {
        return $this->hasOne(Location::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }

    public function scheduleAvailable(): HasMany
    {
        return $this->hasMany(ScheduleAvailable::class);
    }

    public function shouldBeSearchable(): bool
    {
        return $this->user?->hasVerifiedPhoneNumber() &&
            $this->user?->hasVerifiedEmail() &&
            $this->all_documents_verified &&
            $this->iban != null &&
            $this->invoice_workspace != null &&
            str_contains($this->at_user ?? '', '/');
    }

    public function toSearchableArray(): array
    {
        // NÃO se recalcula a nota aqui.
        //
        // Isto é uma serialização: responde a "como é que este profissional se
        // representa no índice de pesquisa". Chamava o updateRatting(), ou
        // seja, uma LEITURA que escrevia na base de dados — a mesma família do
        // `pending-schedules`, que marcava pedidos como aceites por alguém ter
        // aberto um ecrã.
        //
        // O efeito era duplo: uma reindexação (scout:import) disparava um
        // recálculo por cada profissional, e a nota certa passava a depender de
        // alguém, por acaso, reindexar — em vez de depender de haver uma
        // avaliação nova. O recálculo vive agora no ServiceObserver, onde a
        // nota muda de facto.
        $this->load('servicesTypes', 'averageRating');

        $attributes = $this->toArray();
        $attributes['_geo'] = [
            'lat' => $this->currentLocation?->latitude ?? 0,
            'lng' => $this->currentLocation?->longitude ?? 0,
        ];
        $attributes['geoTime'] = $this->currentLocation?->updated_at->timestamp;
        $attributes['services_types'] = $this->servicesTypes;
        $attributes['ratings'] = $this->averageRating;
        $attributes['status'] = $this->status->value;
        $attributes['is_test'] = $this->user?->is_test ?? false;

        return $attributes;
    }

    public function setServices(array $data): void
    {
        $tipos = collect($data)->pluck('services_type_id')->toArray();

        $this->servicesTypes()->sync($tipos);

        // As ÁREAS derivam dos tipos escolhidos.
        //
        // A tabela `vendor_ratings` guarda a nota por ÁREA, e o updateRatting()
        // percorre esta relação para a calcular. Só que nenhuma das duas apps a
        // escrevia — o registo e o ecrã de competências mandam apenas
        // `services_types[]`, e só o backoffice a preenchia à mão. A relação
        // ficava vazia, o ciclo não corria uma vez, e a tabela ficava vazia
        // com ela: todos os profissionais apareciam como "Novo na Piquet" nos
        // dois ecrãs onde o cliente decide.
        //
        // Os tipos são a única fonte que existe. Quem faz "Rotura de Cano"
        // trabalha em Canalização — não é preciso perguntar-lho outra vez.
        $areas = ServicesType::query()
            ->whereIn('id', $tipos)
            ->pluck('operation_area_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->operationAreas()->sync($areas);

        $this->load('servicesTypes', 'operationAreas');
        $this->updateRatting();
        $this->searchable();
    }

    /**
     * Quantas avaliações são precisas para a nota ser mostrada ao cliente.
     *
     * Decisão do André. Abaixo disto o profissional aparece como "Novo na
     * Piquet" — a nota existe e conta-se, mas não se publica um número que
     * ainda não significa nada.
     */
    public const MIN_AVALIACOES_PARA_MOSTRAR = 3;

    /**
     * Recalcula a avaliação deste profissional, por área de operação.
     *
     * Lê `rating_by_customer` — a nota que o CLIENTE deu ao PROFISSIONAL. Até
     * aqui lia `rating_by_vendor`, que é o contrário: a nota que o profissional
     * dá ao cliente. A tabela media a simpatia dele para com quem o contrata,
     * e era isso que aparecia ao cliente na hora de escolher.
     *
     * Sem avaliações grava NULL. Antes gravava 5 estrelas com uma avaliação
     * fictícia, o que mostrava ao cliente uma nota perfeita que ninguém deu —
     * e punha quem nunca trabalhou à frente de quem tem historial.
     *
     * `total_ratings` conta avaliações, não serviços fechados. Contar serviços
     * dizia "40 avaliações" a quem tinha 40 serviços e duas notas.
     */
    public function updateRatting()
    {
        $this->operationAreas->each(function ($operationArea) {
            $servicesTypes = ServicesType::where('operation_area_id', $operationArea->id)->pluck('id');

            $rated = $this->services()
                ->where('status', ServiceStatus::CLOSED)
                ->whereIn('services_type_id', $servicesTypes)
                ->whereNotNull('rating_by_customer');

            $totalRatings = (clone $rated)->count();

            // Abaixo do mínimo a nota fica NULL — e o cliente lê "Novo na
            // Piquet", que é a verdade. Uma média de duas avaliações não diz
            // nada sobre ninguém, e uma delas fraca condenava alguém antes de
            // ter tido hipótese de mostrar trabalho.
            $average = $totalRatings >= self::MIN_AVALIACOES_PARA_MOSTRAR
                ? round((float) (clone $rated)->avg('rating_by_customer'), 2)
                : null;

            Ratings::updateOrCreate([
                'vendor_id' => $this->id,
                'operation_area_id' => $operationArea->id,
            ], [
                'average_rating' => $average,
                'total_ratings' => $totalRatings,
            ]);
        });
    }

    public function calculateDistance(Address|array $address, ?float $latitude = null, ?float $longitude = null): float|int
    {
        if (! $latitude && ! $longitude) {
            $currentLocation = $this->currentLocation;

            $latitude = $currentLocation?->latitude;
            $longitude = $currentLocation?->longitude;
        }

        if (! $latitude || ! $longitude) {
            $fallback = $this->addresses()
                ->where('address_type', AddressType::SCHEDULE_ADDRESS)
                ->first()
                ?? $this->addresses()
                    ->where('address_type', AddressType::FISCAL_ADDRESS)
                    ->first();

            if (! $fallback) {
                throw new \Exception('Vendor location is not available');
            }

            $latitude = $fallback->latitude;
            $longitude = $fallback->longitude;
        }

        if ($address instanceof Address) {
            $address = $address->toArray();
        }

        return calculate_distance((float) $latitude, (float) $longitude, (float) $address['latitude'], (float) $address['longitude']);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VendorDocuments::class);
    }

    public function averageRating(): HasMany
    {
        return $this->hasMany(Ratings::class);
    }

    public function getLastCcAttribute()
    {
        return $this->documents->where('type', 'cc')->sortBy('updated_at')->last();
    }

    public function getLastCriminalRecordAttribute()
    {
        return $this->documents->where('type', 'criminal record')->sortBy('updated_at')->last();
    }

    /**
     * Regra de negócio: o documento é válido ATÉ AO ÚLTIMO DIA DE VALIDADE, INCLUSIVE.
     * vendor_documents.expiration_date é uma coluna `date` (sem hora), pelo que o MySQL
     * compara-a como 00:00:00 desse dia. Com `> now()` um documento que expira hoje
     * ficava inválido logo à meia-noite e o técnico perdia o dia inteiro a que tem
     * direito. Usamos whereDate(... '>=' hoje) para incluir o último dia.
     */
    public function allDocumentsVerified(): Attribute
    {
        return Attribute::make(get: function () {
            $hasAllFiles = true;

            $this->required_documents->each(function ($document) use (&$hasAllFiles) {
                $exists = $this->documents()
                    ->where('document_id', $document->id)
                    ->where('status', 'approved')
                    ->where(function ($query) {
                        $query->whereDate('expiration_date', '>=', now()->toDateString())
                            ->orWhereNull('expiration_date');
                    })
                    ->exists();

                if (! $exists) {
                    $hasAllFiles = false;
                }
            });

            return $hasAllFiles;
        })->shouldCache();
    }

    public function missingDocuments(): Attribute
    {
        return Attribute::make(get: function () {
            $missingFiles = collect();

            $this->required_documents->each(function ($document) use (&$missingFiles) {
                $hasValidDocument = $this->documents()
                    ->where('document_id', $document->id)
                    ->whereIn('status', ['approved', 'pending'])
                    ->where(function ($query) {
                        // Último dia de validade inclusive — ver allDocumentsVerified().
                        $query->whereDate('expiration_date', '>=', now()->toDateString())
                            ->orWhereNull('expiration_date');
                    })
                    ->exists();

                if (! $hasValidDocument) {
                    $missingFiles->add([
                        'id' => $document->id,
                        'name' => $document->name,
                        'reason' => VendorDocuments::where('document_id', $document->id)
                            ->where('status', 'declined')
                            ?->latest()
                            ?->value('reason') ?? null,
                    ]);
                }
            });

            return $missingFiles;
        });
    }

    public function pendingDocuments(): Attribute
    {
        return Attribute::make(get: function () {
            $pendingFiles = collect();

            $this->documents()
                ->where('status', 'pending')
                ->get()
                ->each(function ($document) use (&$pendingFiles) {
                    $pendingDocument = Document::where('id', $document->document_id)->first();
                    $pendingFiles->add([
                        'id' => $pendingDocument->id,
                        'name' => $pendingDocument->name,
                        'reason' => VendorDocuments::where('document_id', $document->document_id)
                            ->where('status', 'declined')
                            ?->latest()
                            ?->value('reason') ?? null,
                    ]);
                });

            return $pendingFiles;
        });
    }

    /**
     * Dias de antecedencia com que se avisa que um documento vai expirar.
     *
     * O mesmo numero que o ecra de Documentos usa em `is_expiring_soon`
     * (DocumentController@index): duas leituras diferentes da mesma regra
     * davam um aviso na Home que o ecra de Documentos nao confirmava.
     */
    public const DOCUMENT_EXPIRY_WARNING_DAYS = 30;

    /**
     * Documentos aprovados a chegar ao fim da validade — ou ja fora dela.
     *
     * A app tem o aviso desde sempre, mas lia um campo que ninguem enviava:
     * o tecnico so descobria o problema quando deixava de receber trabalho.
     * Vai no /me, e nao num pedido proprio, porque e a mesma informacao que
     * ja decide se ele pode aceitar servicos.
     *
     * Inclui os expirados (dias negativos) para o aviso poder mudar de tom
     * sem precisar de outra fonte.
     */
    public function expiringDocuments(): Attribute
    {
        return Attribute::make(get: function () {
            $limite = now()->startOfDay()->addDays(self::DOCUMENT_EXPIRY_WARNING_DAYS);

            return $this->documents()
                ->where('status', 'approved')
                ->whereNotNull('expiration_date')
                ->whereDate('expiration_date', '<=', $limite->toDateString())
                ->with('type')
                ->get()
                ->map(function (VendorDocuments $documento) {
                    $validade = Carbon::parse($documento->expiration_date)->startOfDay();
                    $dias = (int) now()->startOfDay()->diffInDays($validade, false);

                    return [
                        'id' => $documento->document_id,
                        'name' => $documento->type?->name,
                        'days_to_expire' => $dias,
                        // Expirado so a partir do dia SEGUINTE ao ultimo dia de
                        // validade — espelho de allDocumentsVerified().
                        'is_expired' => $dias < 0,
                    ];
                })
                ->filter(fn (array $documento) => $documento['name'] !== null)
                ->sortBy('days_to_expire')
                ->values();
        });
    }

    public function optionalDocuments(): Attribute
    {
        return Attribute::make(get: function () {
            $pendingFiles = collect();
            $this->unrequired_documents->each(function ($document) use (&$pendingFiles) {
                $validDocumentExists = $this->documents()
                    ->where('document_id', $document->id)
                    ->where('vendor_id', $this->id)
                    ->whereIn('status', ['approved', 'pending'])
                    ->where(function ($query) {
                        // Último dia de validade inclusive — ver allDocumentsVerified().
                        $query->whereDate('expiration_date', '>=', now()->toDateString())
                            ->orWhereNull('expiration_date');
                    })
                    ->exists();

                if (! $validDocumentExists) {
                    $pendingFiles->add($document);
                }
            });

            return $this->required_documents
                ->filter(function ($doc) use ($pendingFiles) {
                    return in_array($doc->id, $pendingFiles->toArray());
                })
                ->values();
        })->shouldCache();
    }

    public function priceRate(): Attribute
    {
        return Attribute::make(get: function ($value) {
            return number_format($value / 100, 2, '.', ',');
        }, set: fn (string $value) => [
            'price_rate' => (int) round(((float) str_replace(',', '', $value)) * 100),
        ])->shouldCache();
    }

    public function requiredDocuments(): Attribute
    {
        return Attribute::make(get: function () {
            $documents = collect();
            $documents = $documents->merge(Document::where('required', true)->get());
            $this->operationAreas->each(function ($operationArea) use (&$documents) {
                $documents = $documents->merge($operationArea->certifications);
            });

            return $documents = $documents->unique();
        });
    }

    public function unrequiredDocuments(): Attribute
    {
        return Attribute::make(get: function () {
            $documents = collect();
            $documents = $documents->merge(Document::where('required', false)->get());
            $this->operationAreas->each(function ($operationArea) use (&$documents) {
                $documents = $documents->merge($operationArea->certifications);
            });

            return $documents = $documents->unique();
        });
    }
}
