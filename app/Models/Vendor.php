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

    protected $appends = ['can_accept_service', 'price_rate', 'full_name', 'invoice_workspace_ready', 'at_em_dia'];

    protected $with = ['user', 'servicesTypes', 'operationAreas', 'currentLocation'];

    protected $casts = [
        'status' => StatusVendor::class,
        'auth_token' => 'encrypted',
        'at_password' => 'encrypted',
        'at_valid' => 'boolean',
        'at_validated_at' => 'datetime',
        'at_deadline_started_at' => 'datetime',
        'at_forfeited_at' => 'datetime',
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
                // A AT SO A PARTIR DO QUARTO SERVICO — ver `atRequired()`.
                //
                // A ordem importa: `at_ready` primeiro, para quem ja a deu nao
                // pagar a contagem de servicos. O `||` do PHP faz curto-circuito.
                ($this->at_ready || ! $this->at_required);
        })->shouldCache();
    }

    /**
     * Quantos serviços o profissional leva até ao fim antes de a AT ser exigida.
     *
     * Três, por decisão do André (30/09/2026). O acesso à AT obriga-o a sair da
     * app, entrar no Portal das Finanças e criar um subutilizador — é o passo de
     * maior fricção do registo inteiro, e estava a travar gente ANTES de ela ter
     * ganho um único euro. Deixa de ser um portão à entrada e passa a ser uma
     * condição para continuar.
     *
     * NÃO se aplica aos outros documentos. Cartão de Cidadão, Registo Criminal e
     * Declaração de Início de Atividade continuam obrigatórios desde o dia zero:
     * são de identidade e idoneidade, e sem eles não se manda ninguém a casa de
     * um cliente. O acesso à AT é de FATURAÇÃO — só é preciso quando há mesmo o
     * que faturar.
     */
    public const SERVICOS_ANTES_DA_AT = 3;

    /**
     * FIABILIDADE — as regras que o técnico vê na app.
     *
     * Cancelar um serviço depois de o aceitar deixa um cliente sem ninguém,
     * muitas vezes já à espera em casa. Ao terceiro no mesmo mês, o técnico
     * fica 48 horas sem receber convites. Não é multa: é uma pausa, e a regra
     * é dita antes de ele cancelar (ver `reliabilitySummary`).
     *
     * O mês é o do calendário, em Lisboa: "este mês" é o que o técnico entende
     * sem fazer contas, e é o que a app lhe mostra.
     */
    public const CANCELAMENTOS_ANTES_DA_PAUSA = 3;

    public const HORAS_DE_PAUSA = 48;

    /**
     * Cada falta nesta janela faz descer uma faixa no ranking (até ao máximo
     * de faixas que existem). Noventa dias: tempo de a falta pesar, sem a
     * carregar para sempre.
     */
    public const DIAS_DAS_FALTAS_NO_RANKING = 90;

    private const FUSO = 'Europe/Lisbon';

    /**
     * Cancelamentos e faltas de vários técnicos, numa consulta cada.
     *
     * O ranking avalia dezenas de técnicos por pedido: perguntar a cada um
     * separadamente seria uma consulta por técnico em cada onda.
     *
     * @param  int[]  $vendorIds
     * @return array<int, array{cancelamentos: int, ultimo_cancelamento: ?CarbonInterface, faltas: int}>
     */
    public static function fiabilidadeDe(array $vendorIds): array
    {
        if (empty($vendorIds)) {
            return [];
        }

        $inicioDoMes = now(self::FUSO)->startOfMonth()->utc();

        $cancelamentos = Service::query()
            ->selectRaw('vendor_id, COUNT(*) as total, MAX(vendor_canceled_at) as ultimo')
            ->whereIn('vendor_id', $vendorIds)
            ->where('vendor_canceled_at', '>=', $inicioDoMes)
            ->groupBy('vendor_id')
            ->get()
            ->keyBy('vendor_id');

        $faltas = Service::query()
            ->selectRaw('vendor_id, COUNT(*) as total')
            ->whereIn('vendor_id', $vendorIds)
            ->where('vendor_no_show_at', '>=', now()->subDays(self::DIAS_DAS_FALTAS_NO_RANKING))
            ->groupBy('vendor_id')
            ->pluck('total', 'vendor_id');

        $resultado = [];

        foreach ($vendorIds as $id) {
            $c = $cancelamentos->get($id);
            $resultado[$id] = [
                'cancelamentos' => (int) ($c->total ?? 0),
                'ultimo_cancelamento' => $c?->ultimo ? Carbon::parse($c->ultimo) : null,
                'faltas' => (int) ($faltas[$id] ?? 0),
            ];
        }

        return $resultado;
    }

    /**
     * Até quando está sem convites, ou null se não está.
     *
     * Conta a partir do ÚLTIMO cancelamento do mês: um quarto cancelamento
     * durante a pausa prolonga-a, em vez de não ter consequência nenhuma.
     */
    public static function pausaAte(array $fiabilidade): ?CarbonInterface
    {
        if (($fiabilidade['cancelamentos'] ?? 0) < self::CANCELAMENTOS_ANTES_DA_PAUSA) {
            return null;
        }

        $fim = $fiabilidade['ultimo_cancelamento']?->copy()->addHours(self::HORAS_DE_PAUSA);

        return $fim && $fim->isFuture() ? $fim : null;
    }

    public function invitesPausedUntil(): ?CarbonInterface
    {
        return self::pausaAte(self::fiabilidadeDe([$this->id])[$this->id]);
    }

    /** O que a app mostra ao técnico: onde está, e qual é a regra. */
    public function reliabilitySummary(): array
    {
        $f = self::fiabilidadeDe([$this->id])[$this->id];

        return [
            'cancellations_this_month' => $f['cancelamentos'],
            'cancellations_limit' => self::CANCELAMENTOS_ANTES_DA_PAUSA,
            'pause_hours' => self::HORAS_DE_PAUSA,
            'invites_paused_until' => self::pausaAte($f)?->toIso8601String(),
            'no_shows_recent' => $f['faltas'],
            'no_shows_window_days' => self::DIAS_DAS_FALTAS_NO_RANKING,
        ];
    }

    /**
     * Serviços que o profissional levou até ao fim.
     *
     * Conta o trabalho FEITO, não o dinheiro recebido: `ClosedPendingPayment` é
     * um serviço executado à espera de cobrança, e `Archived` é um fechado que o
     * backoffice arrumou depois. Excluí-los deixaria o técnico a trabalhar de
     * graça para lá dos três por uma razão administrativa que não é dele.
     */
    public function completedServices(): HasMany
    {
        // A lista dos estados vive no enum, e não aqui. Estava escrita à mão
        // nos dois sítios que a usam, e os dois divergiram: o backoffice
        // contava só `CLOSED`. Ver `ServiceStatus::concluidos()`.
        return $this->services()->whereIn('status', ServiceStatus::concluidos());
    }

    /** O acesso à AT está dado E validado. */
    /**
     * A AT está em dia: ou foi entregue, ou ainda não é exigida.
     *
     * É a MESMA condição que o `canAcceptService` usa (`at_ready ||
     * ! at_required`), num sítio só e com nome próprio.
     *
     * Existe porque esta regra estava copiada em SQL em cinco sítios -- duas
     * contagens e três consultas de procura -- e quando mudou a 30/09 só umas
     * foram atualizadas. O resultado foi o backoffice a dizer 41 elegíveis e
     * o novo a dizer 69, e técnicos a contarem como disponíveis sem o cliente
     * os conseguir encontrar.
     *
     * Vai nos `$appends`, portanto entra no índice de pesquisa pelo
     * `toSearchableArray()` -- é assim que o Meilisearch pode filtrar por ela
     * em vez de por `at_valid`.
     */
    public function atEmDia(): Attribute
    {
        return Attribute::make(get: fn () => $this->at_ready || ! $this->at_required)->shouldCache();
    }

    /**
     * A mesma regra, em SQL, para quem filtra em consulta.
     *
     * `whereHas(..., '<', N)` gera uma subconsulta de contagem, por isso
     * apanha também quem tem ZERO serviços concluídos -- que são justamente os
     * que a regra quer deixar entrar.
     */
    public function scopeAtEmDia($query)
    {
        return $query->where(function ($q) {
            $q->where(function ($jaDeu) {
                $jaDeu->where('at_valid', true)->where('at_user', 'like', '%/%');
            })->orWhereHas('completedServices', null, '<', self::SERVICOS_ANTES_DA_AT);
        });
    }

    public function atReady(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) $this->at_valid && str_contains($this->at_user ?? '', '/')
        )->shouldCache();
    }

    /**
     * A AT já é exigida — os três primeiros serviços acabaram.
     *
     * Ao TERCEIRO concluído passa a ser: o quarto pedido já não chega a quem não
     * a tiver dado.
     */
    public function atRequired(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->completedServices()->count() >= self::SERVICOS_ANTES_DA_AT
        )->shouldCache();
    }

    /** Quantos serviços ainda pode fazer antes de a AT o travar. 0 = já trava. */
    public function servicesUntilAtRequired(): Attribute
    {
        return Attribute::make(
            get: fn () => max(0, self::SERVICOS_ANTES_DA_AT - $this->completedServices()->count())
        )->shouldCache();
    }

    /**
     * O que impede a Piquet de transferir o dinheiro que já é dele. `null` = pode pagar.
     *
     * O trabalho conta, o cliente é cobrado e o técnico VÊ o dinheiro no saldo
     * — só não o recebe enquanto isto não estiver resolvido. A regra é uma:
     * **não se transfere dinheiro por trabalho que não se consegue faturar**.
     *
     * Três razões, por ordem de quem se resolve primeiro:
     *
     *  - `iban_missing` — não há para onde transferir. Literal.
     *  - `fiscal_address_missing` — sem morada fiscal a conta de faturação não
     *    se cria (rebenta no InvoiceXpress), e sem ela não há fatura.
     *  - `at_user_missing` — sem o subutilizador não se comunica a fatura à AT.
     *    Só a partir do 3.º serviço concluído (ver `atRequired()`).
     *
     * O QUE NÃO ENTRA, de propósito: `documents_pending` e `contact_unverified`.
     * Estão no `invoicingBlocker()` porque travam o técnico de TRABALHAR, mas não
     * travam a fatura de trabalho já feito. Reter o dinheiro de alguém porque o
     * cartão de cidadão está a ser revalidado seria castigá-lo financeiramente
     * por uma coisa que não impede pagar-lhe. Se um dia isto for "simplificado"
     * para `invoicingBlocker() !== null`, é este parágrafo que se está a apagar.
     */
    /**
     * Dinheiro GANHO A TRABALHAR que ainda não lhe foi transferido, em cêntimos.
     *
     * A carteira é um número só, mas nem tudo o que lá está é salário: há crédito
     * promocional de boas-vindas, que não é pagamento de trabalho nenhum. O que
     * os distingue é o `meta` do depósito -- o `settle()` grava
     * `class => Service::class` em cada crédito de serviço (ver
     * `Service::getMetaProduct()`), e o crédito promocional não.
     *
     * Subtrai o que já saiu porque os levantamentos zeram a carteira INTEIRA, o
     * que inclui a parte promocional. Sem a subtração, um técnico já pago
     * continuava com "ganhos por pagar" para sempre, e o crédito promocional
     * seguinte ficava travado por dinheiro que ele já tinha recebido.
     */
    public function ganhosPorPagar(): Attribute
    {
        return Attribute::make(get: function () {
            /*
             * Pela CARTEIRA dele, não pela relação `transactions()`.
             *
             * Essa relação liga users.id = transactions.payable_id e não filtra a
             * carteira. A comissão da Piquet vai para a carteira do SISTEMA no
             * mesmo fecho de serviço, e sempre que os dois donos partilhem o id
             * entrava aqui dinheiro que não é dele -- media-se 10000 onde o
             * técnico recebeu 7500. Apanhado por um teste que esperava 7500.
             */
            $carteira = $this->user?->wallet?->getKey();

            if (! $carteira) {
                return 0;
            }

            $deServicos = (int) Transaction::where('wallet_id', $carteira)
                ->where('type', 'deposit')
                ->where('confirmed', true)
                ->whereJsonContains('meta->class', Service::class)
                ->sum('amount');

            $jaTransferido = (int) abs((int) Transaction::where('wallet_id', $carteira)
                ->where('type', 'withdraw')
                ->where('confirmed', true)
                ->sum('amount'));

            return max(0, $deServicos - $jaTransferido);
        })->shouldCache();
    }

    public function payoutBlocker(): ?string
    {
        /*
         * Sem dinheiro GANHO por pagar, não há nada a reter.
         *
         * A regra toda existe para não transferir dinheiro por trabalho que não
         * se consegue faturar. Crédito promocional não é trabalho e não precisa
         * de fatura -- travá-lo era aplicar uma regra de faturação a uma coisa
         * que não se fatura.
         *
         * Medido em produção a 30/09: dos 25 técnicos com saldo, 8 tinham
         * exactamente 20,00 EUR e ZERO serviços concluídos. Eram os 8 que esta
         * saída antecipada liberta.
         */
        if ($this->ganhos_por_pagar <= 0) {
            return null;
        }

        if (! $this->iban) {
            return 'iban_missing';
        }

        if (! $this->addresses()->where('address_type', AddressType::FISCAL_ADDRESS)->exists()) {
            return 'fiscal_address_missing';
        }

        // A AT só a partir do quarto serviço — ver `atRequired()`.
        if ($this->at_required && ! $this->at_ready) {
            return 'at_user_missing';
        }

        return null;
    }

    /** A versão dos termos que este técnico aceitou, ou `null`. */
    public function versaoDosTermosAceite(): ?string
    {
        return TermsAcceptance::where('user_id', $this->user_id)
            ->where('document', TermsAcceptance::DOCUMENTO_PRESTADORES)
            ->orderByDesc('accepted_at')
            ->value('version');
    }

    /** Aceitou a versão que está em vigor? */
    public function aceitouOsTermosEmVigor(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->versaoDosTermosAceite() === config('legal.provider_terms.version')
        )->shouldCache();
    }

    /** Atalho booleano do `payoutBlocker()`, para quem só precisa de sim/não. */
    public function payoutBlocked(): Attribute
    {
        return Attribute::make(get: fn () => $this->payoutBlocker() !== null)->shouldCache();
    }

    /**
     * Quanto é que está retido por causa da AT, em cêntimos.
     *
     * É o saldo todo: a carteira do técnico só guarda a parte dele, e se o
     * pagamento está travado está travado por inteiro. 0 quando não há nada
     * retido -- seja porque não há bloqueio, seja porque a carteira está a zero.
     */
    public function payoutOnHoldAmount(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->payout_blocked
                ? max(0, (int) ($this->user?->wallet?->balance ?? 0))
                : 0
        );
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

        // A AT so conta como razao depois dos tres primeiros servicos. Antes
        // disso nao e um impedimento, e listar-lha no backoffice fazia parecer
        // que o processo dele estava parado quando nao estava.
        if ($this->at_required) {
            if (! $this->at_valid) {
                $reasons->push(__('backoffice/vendor.infolist.eligibility.at_invalid'));
            }

            if (! str_contains($this->at_user ?? '', '/')) {
                $reasons->push(__('backoffice/vendor.infolist.eligibility.at_user_invalid'));
            }
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
            // Mesma regra do portao: sem ela, quem ainda esta nos tres
            // primeiros servicos podia aceitar pedidos mas nao aparecia na
            // pesquisa — elegivel e invisivel ao mesmo tempo.
            ($this->at_ready || ! $this->at_required);
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
                        /*
                         * O motivo de recusa DESTE técnico, e não de outro
                         * qualquer.
                         *
                         * Faltava o `where('vendor_id')`. A consulta apanhava
                         * a recusa mais recente de QUALQUER técnico para
                         * aquele tipo de documento -- e isto vai para a app
                         * do próprio, no GET /me. Um técnico que nunca
                         * submeteu nada via o motivo escrito à mão a outra
                         * pessoa ("foto ilegível", "documento de terceiro"),
                         * como se fosse sobre ele.
                         */
                        'reason' => VendorDocuments::where('vendor_id', $this->id)
                            ->where('document_id', $document->id)
                            ->where('status', 'declined')
                            ->latest()
                            ->value('reason'),
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

    /**
     * O que falta a este técnico para poder trabalhar e ser faturado.
     *
     * Devolve um CÓDIGO, não uma frase. A regra -- e sobretudo a ORDEM em que
     * as coisas são pedidas -- vive aqui, num sítio só; quem mostra escreve
     * para o seu público. O backoffice diz "os documentos DO TÉCNICO ainda não
     * foram verificados"; a app dele tem de dizer "os TEUS documentos estão a
     * ser verificados". A mesma frase nos dois sítios estaria errada num deles.
     *
     * A ordem não é arbitrária: é a ordem pela qual as coisas se resolvem. Não
     * vale a pena pedir o IBAN a quem ainda nem confirmou o telemóvel.
     *
     * `null` = está tudo pronto.
     */
    public function invoicingBlocker(): ?string
    {
        if (! $this->user?->hasVerifiedEmail() && ! $this->user?->hasVerifiedPhoneNumber()) {
            return 'contact_unverified';
        }

        if (! $this->all_documents_verified) {
            return 'documents_pending';
        }

        if (! $this->iban) {
            return 'iban_missing';
        }

        if (! $this->addresses()->where('address_type', AddressType::FISCAL_ADDRESS)->exists()) {
            return 'fiscal_address_missing';
        }

        // Ultimo da lista de proposito: e o unico que aparece DEPOIS de o
        // tecnico ja ter trabalhado. Os outros sao de entrada.
        if ($this->at_required && ! $this->at_ready) {
            return 'at_user_missing';
        }

        return null;
    }
}
