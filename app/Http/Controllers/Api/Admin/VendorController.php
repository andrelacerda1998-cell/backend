<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Enums\Services\AddressType;
use App\Exceptions\Api\Vendor\Invoicing\VendorInvalidAtCredentials;
use App\Filament\Infolists\Sections\CompanySection;
use App\Services\InvoiceXpress\SystemInvoiceService;
use App\Services\InvoiceXpress\ValidacaoDaAt;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\VendorDocuments;
use App\Services\Operacoes\CoberturaPelasCidades;
use App\Services\Operacoes\DesfechoDoPedido;
use App\Services\Operacoes\PodemTrabalhar;
use App\Support\PesquisaDePessoas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Técnicos/Vendors — migrado do Filament (App\Filament\Resources\VendorResource).
 *
 * Como a `admin.api` middleware usa um token estático partilhado (sem sessão/
 * utilizador autenticado), não há como replicar o ramo "developer vê também
 * is_test" do Filament -- aplica-se sempre `user.is_test = false`, o
 * comportamento por omissão para não-developers.
 *
 * Ações restritas a super-admin no Filament (editar dados do vendor -- IBAN,
 * preço, NIF --, eliminar, "Marcar como Test", "Alterar serviços") ficam de
 * fora desta fatia por decisão explícita.
 *
 * IMPORTANTE sobre suspend()/restore(): no Filament, só o super-admin pode
 * mutar um vendor (VendorResource::canEdit()/canDelete()) -- "o IBAN
 * redireciona payouts, exposição direta de dinheiro se um admin simples
 * editar". A `admin.api` não distingue papéis (token único partilhado por
 * todo o staff com acesso ao backoffice Next.js), por isso suspender/reativar
 * aqui NÃO replica essa restrição -- decisão explícita (soft-delete é
 * reversível e menos sensível que editar IBAN/preço, mas fica documentado
 * que qualquer membro do staff com acesso ao backoffice consegue suspender
 * um vendor, não só super-admins).
 */
class VendorController extends Controller
{
    /**
     * O estado de um técnico, numa palavra — para separar a lista do
     * backoffice em quem está a trabalhar, quem podia estar e quem ainda não
     * pode. Regra exata (pela ordem):
     *
     *  - `suspenso`: suspenso pelo backoffice (soft delete), esteja o
     *    interruptor Online como estiver;
     *  - `online`: o interruptor da app está em Online;
     *  - `pronto`: Offline, com todos os documentos obrigatórios aprovados e
     *    dentro da validade — foi aprovado, só não está ligado;
     *  - `em_aprovacao`: Offline e com documentos obrigatórios em falta, por
     *    rever, recusados ou caducados.
     *
     * "Pronto" fala da APROVAÇÃO, que é o que a equipa decide. Não promete que
     * ele já pode receber pedidos: IBAN, workspace de faturação e AT continuam
     * em `can_accept_service` e `account_blocker`, como até aqui.
     */
    private const ESTADOS = ['online', 'pronto', 'em_aprovacao', 'suspenso'];

    /**
     * GET /v1/admin/vendors/documents-summary — porque é que a documentação
     * está incompleta, repartido.
     *
     * "158 com documentação incompleta" não diz o que fazer: nunca submeteram,
     * foram recusados, ou têm um documento EXPIRADO? São três problemas
     * diferentes, e o terceiro é o mais caro -- são técnicos que já
     * trabalharam e pararam sem dar por isso.
     *
     * Agregado, e não campo por técnico na listagem: `all_documents_verified`
     * já faz uma consulta por documento obrigatório e por técnico; repetir
     * esse padrão numa página de 100 acrescentava centenas de consultas por
     * pedido. Aqui carrega-se tudo uma vez e conta-se em memória.
     */
    public function documentsSummary(): ApiSuccessResponse
    {
        $hoje = now()->toDateString();

        // `certifications` é um ATRIBUTO da OperationArea, não uma relação --
        // eager-load dele rebenta com "Call to undefined method
        // Attribute::addEagerConstraints()". Carregam-se só as relações.
        $vendors = $this->baseQuery()->with(['documents', 'operationAreas'])->get();

        $contagem = [
            'total' => $vendors->count(),
            'completos' => 0,
            'com_expirado' => 0,
            'com_recusado' => 0,
            'com_por_rever' => 0,
            'nunca_submeteram' => 0,
        ];

        /*
         * Os dois grupos accionáveis vão COM NOMES, e os outros não.
         *
         * Um expirado é alguém que já teve tudo aprovado -- provavelmente já
         * trabalhou -- e cujo documento caducou; um recusado submeteu e foi-lhe
         * dito que não. Os dois resolvem-se com um telefonema a UMA pessoa.
         *
         * Os "nunca submeteram" são 337: uma lista desse tamanho não é uma
         * lista de trabalho, é um relatório, e por isso fica só na contagem.
         */
        $expirados = [];
        $recusados = [];

        foreach ($vendors as $vendor) {
            $estados = [];
            $porDocumento = ['expirado' => [], 'recusado' => []];

            foreach ($vendor->required_documents as $obrigatorio) {
                $doVendor = $vendor->documents->where('document_id', $obrigatorio->id);

                /*
                 * `expiration_date` NÃO tem cast no modelo -- vem como string.
                 * Chamar-lhe `->toDateString()` rebenta. Compara-se texto com
                 * texto, que em `Y-m-d` ordena igual à data.
                 */
                $dia = fn ($d) => $d->expiration_date === null ? null : substr((string) $d->expiration_date, 0, 10);

                $valido = $doVendor->first(fn ($d) => $d->status === 'approved'
                    && ($dia($d) === null || $dia($d) >= $hoje));
                if ($valido) { $estados[] = 'ok'; continue; }

                if ($doVendor->firstWhere('status', 'pending')) { $estados[] = 'por_rever'; continue; }

                // Aprovado mas fora de validade: já esteve bom, deixou de estar.
                $expirado = $doVendor->first(fn ($d) => $d->status === 'approved'
                    && $dia($d) !== null && $dia($d) < $hoje);
                if ($expirado) {
                    $estados[] = 'expirado';
                    $porDocumento['expirado'][] = ['nome' => $obrigatorio->name, 'em' => $dia($expirado)];
                    continue;
                }

                $recusa = $doVendor->firstWhere('status', 'declined');
                if ($recusa) {
                    $estados[] = 'recusado';
                    $porDocumento['recusado'][] = ['nome' => $obrigatorio->name, 'motivo' => $recusa->reason];
                    continue;
                }

                $estados[] = 'em_falta';
            }

            /*
             * Cada técnico conta UMA vez, no problema mais grave que tem --
             * pela mesma razão que os degraus: somar por documento dava um
             * total maior do que o número de pessoas e ninguém saberia
             * quantas afinal eram.
             */
            // Mesma regra do `present()`: first_name/last_name são as colunas
            // reais -- `name`/`fullName` delegam para um `full_name` que não
            // existe no User.
            $u = $vendor->user;
            $nome = trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: null;

            if (in_array('expirado', $estados, true)) {
                $contagem['com_expirado']++;
                $expirados[] = [
                    'id' => $vendor->id,
                    'name' => $nome,
                    'documentos' => $porDocumento['expirado'],
                ];
            } elseif (in_array('recusado', $estados, true)) {
                $contagem['com_recusado']++;
                $recusados[] = [
                    'id' => $vendor->id,
                    'name' => $nome,
                    'documentos' => $porDocumento['recusado'],
                ];
            } elseif (in_array('por_rever', $estados, true)) {
                $contagem['com_por_rever']++;
            } elseif (in_array('em_falta', $estados, true)) {
                $contagem['nunca_submeteram']++;
            } else {
                // Só chega aqui quem tem tudo 'ok' -- ou quem não tem
                // documentos obrigatórios nenhuns, que conta como completo.
                $contagem['completos']++;
            }
        }

        // Os mais antigos primeiro: caducaram há mais tempo.
        usort($expirados, fn ($a, $b) => ($a['documentos'][0]['em'] ?? '') <=> ($b['documentos'][0]['em'] ?? ''));

        return ApiSuccessResponse::make([
            ...$contagem,
            'expirados' => $expirados,
            'recusados' => $recusados,
        ]);
    }

    private function baseQuery()
    {
        return Vendor::query()
            ->whereHas('user', fn ($q) => $q->where('is_test', false));
    }

    public function index(Request $request): ApiSuccessResponse
    {
        $request->validate([
            'estado' => ['nullable', Rule::in(self::ESTADOS)],
            'categoria' => ['nullable', 'integer'],
        ], [
            'estado.in' => 'O estado tem de ser um de: '.implode(', ', self::ESTADOS).'.',
        ]);

        $perPage = min((int) $request->integer('per_page', 20), 100);
        $search = trim((string) $request->string('search'));
        $suspendedOnly = $request->boolean('suspended');
        $estado = $request->string('estado')->toString();

        $query = $this->comResumo($this->baseQuery());

        if ($suspendedOnly || $estado === 'suspenso') {
            $query->onlyTrashed();
        } elseif ($request->boolean('incluir_suspensos')) {
            $query->withTrashed();
        }

        if (in_array($estado, ['online', 'pronto', 'em_aprovacao'], true)) {
            // Um suspenso é sempre "suspenso", mesmo com `incluir_suspensos`.
            $query->whereNull('vendors.deleted_at');

            if ($estado === 'online') {
                $query->where('vendors.status', StatusVendor::ONLINE);
            } else {
                $query->where(fn ($q) => $q
                    ->whereNull('vendors.status')
                    ->orWhere('vendors.status', '!=', StatusVendor::ONLINE));

                [$documentosEmDia, $bindings] = Vendor::sqlDocumentosEmDia();
                $estado === 'pronto'
                    ? $query->whereRaw("({$documentosEmDia})", $bindings)
                    : $query->whereRaw("NOT ({$documentosEmDia})", $bindings);
            }
        }

        if ($search !== '') {
            // Nome completo, telefone em qualquer formato, email e NIF -- a
            // mesma regra da lista de clientes (ver PesquisaDePessoas).
            $query->whereHas('user', fn ($q) => PesquisaDePessoas::aplicar($q, $search));
        }

        /*
         * Onde trabalha. Há duas fontes, e contam as duas:
         *
         *  - as cidades que ele escolheu na app (`vendor_available_cities`) --
         *    é por elas que o matching decide a quem chega um pedido;
         *  - as zonas do questionário antigo (`vendor_allowed_zones`), que é o
         *    que os gráficos de "técnicos por localização" já contam.
         *
         * Comparação pelo nome exato (a colação da base ignora maiúsculas e
         * acentos). A morada FISCAL não conta: é onde ele passa faturas, não
         * onde trabalha.
         */
        if ($cidade = trim($request->string('cidade')->toString())) {
            $query->where(fn ($q) => $q
                ->whereHas('availableCities', fn ($c) => $c->where('cities.name', $cidade))
                ->orWhereHas('allowedZones', fn ($z) => $z->where('allowed_zone.city', $cidade)));
        }

        /*
         * A categoria (área de operação) em que trabalha. Pelos tipos de serviço
         * que faz -- é por eles que o matching o escolhe -- e também pela
         * ligação direta à área, que existe em poucos mas existe.
         */
        if ($request->filled('categoria')) {
            $area = $request->integer('categoria');
            $query->where(fn ($q) => $q
                ->whereHas('servicesTypes', fn ($t) => $t->where('services_types.operation_area_id', $area))
                ->orWhereHas('operationAreas', fn ($a) => $a->where('operation_areas.id', $area)));
        }

        // Documentos obrigatórios em falta, por rever, recusados ou caducados.
        if ($request->boolean('documentos_em_falta')) {
            [$documentosEmDia, $bindings] = Vendor::sqlDocumentosEmDia();
            $query->whereRaw("NOT ({$documentosEmDia})", $bindings);
        }

        /*
         * Os Online primeiro e, dentro de cada grupo, quem esteve ativo há
         * menos tempo: a última localização que a app mandou (só a manda
         * aberta) ou o último serviço que ficou com ele, o que for mais recente.
         */
        if ($request->string('sort')->toString() === 'online') {
            $query->orderByRaw('(vendors.status = ?) DESC', [StatusVendor::ONLINE->value])
                ->orderByRaw("GREATEST(COALESCE(last_location_at, '1000-01-01 00:00:00'), COALESCE(last_service_at, '1000-01-01 00:00:00')) DESC")
                ->orderByDesc('vendors.id');
        }

        // Eager-load do que o present() lê: sem isto, cada linha da página
        // dispara uma query por relação (documentos e morada fiscal incluídos)
        // e uma listagem de 100 técnicos passava das 300 queries.
        $vendors = $query->with(['user', 'operationAreas', 'servicesTypes', 'addresses', 'documents'])
            ->orderByDesc('created_at')->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($vendors->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $vendors->currentPage(),
                'last_page' => $vendors->lastPage(),
                'per_page' => $vendors->perPage(),
                'total' => $vendors->total(),
            ],
        ]);
    }

    /**
     * GET /v1/admin/vendors/{id} — um técnico, suspenso ou não, para a ficha
     * com endereço próprio (/tecnicos/94). Mesmo formato da listagem.
     */
    public function show(int $id): ApiSuccessResponse|ApiErrorResponse
    {
        $vendor = $this->comResumo($this->baseQuery()->withTrashed())
            ->with(['user', 'operationAreas', 'servicesTypes', 'addresses', 'documents'])
            ->find($id);

        if (! $vendor) {
            return new ApiErrorResponse(null, 'Técnico não encontrado.', 404);
        }

        return ApiSuccessResponse::make($this->present($vendor));
    }

    /**
     * Estados de um serviço que contam como "um serviço dele": ficou com o
     * trabalho e o trabalho seguiu (ou já acabou). Os pedidos em que foi
     * escolhido mas o pagamento falhou, ou que o cliente cancelou antes,
     * guardam o `vendor_id` mas não foram trabalho nenhum.
     *
     * @return array<int, ServiceStatus>
     */
    private static function servicosDele(): array
    {
        // Arquivado conta: com técnico, é um servido que se arrumou
        // (a mesma leitura do DesfechoDoPedido).
        return [...DesfechoDoPedido::COM_TECNICO, ServiceStatus::ARCHIVED];
    }

    /**
     * O resumo de cada técnico, calculado na MESMA consulta da página
     * (subconsultas), e não um pedido à base de dados por técnico:
     *
     *  - `documentos_em_dia`: a regra de Vendor::sqlDocumentosEmDia(), que é a
     *    que decide entre "pronto" e "em aprovação";
     *  - `services_30d_count` / `last_service_at`: os serviços que foram mesmo
     *    dele (ver servicosDele()) nos últimos 30 dias, e o mais recente;
     *  - `rating_avg` / `rating_count`: as notas que os clientes deram aos
     *    serviços dele que fecharam (as mesmas que a nota pública lê, sem o
     *    mínimo de três para a mostrar -- aqui é para a equipa);
     *  - `last_location_at`: a última localização que a app mandou.
     *
     * Os pedidos de teste não contam, como em toda a API de admin.
     */
    private function comResumo(Builder $query): Builder
    {
        [$documentosEmDia, $bindings] = Vendor::sqlDocumentosEmDia();

        $dele = fn ($s) => $s->where('services.is_test', false)->whereIn('services.status', self::servicosDele());
        $avaliados = fn ($s) => $s->where('services.is_test', false)
            ->where('services.status', ServiceStatus::CLOSED)
            ->whereNotNull('services.rating_by_customer');

        return $query
            ->select('vendors.*')
            ->selectRaw("CASE WHEN {$documentosEmDia} THEN 1 ELSE 0 END AS documentos_em_dia", $bindings)
            ->withCount([
                'services as services_30d_count' => fn ($s) => $dele($s)->where('services.created_at', '>=', now()->subDays(30)),
                'services as rating_count' => $avaliados,
            ])
            ->withMax(['services as last_service_at' => $dele], 'created_at')
            ->withAvg(['services as rating_avg' => $avaliados], 'rating_by_customer')
            ->withMax('currentLocation as last_location_at', 'updated_at');
    }

    /**
     * O técnico tal como a lista o mostra, com o resumo. As respostas de
     * suspender, reativar e criar o workspace devolvem a linha atualizada, e
     * sem isto chegavam com os contadores a zero e o estado por calcular.
     */
    private function apresentar(Vendor $vendor): array
    {
        $comResumo = $this->comResumo(Vendor::withTrashed()->whereKey($vendor->getKey()))
            ->with(['user', 'operationAreas', 'servicesTypes', 'addresses', 'documents'])
            ->first();

        return $this->present($comResumo ?? $vendor);
    }

    public function suspend(int $id): ApiSuccessResponse|ApiErrorResponse
    {
        $vendor = Vendor::withTrashed()->find($id);

        if (! $vendor) {
            return new ApiErrorResponse(null, 'Técnico não encontrado.', 404);
        }

        if ($vendor->trashed()) {
            return new ApiErrorResponse(null, 'Este técnico já está suspenso.', 409);
        }

        $vendor->delete();

        return ApiSuccessResponse::make($this->apresentar($vendor));
    }

    public function restore(int $id): ApiSuccessResponse|ApiErrorResponse
    {
        $vendor = Vendor::withTrashed()->find($id);

        if (! $vendor) {
            return new ApiErrorResponse(null, 'Técnico não encontrado.', 404);
        }

        if (! $vendor->trashed()) {
            return new ApiErrorResponse(null, 'Este técnico não está suspenso.', 409);
        }

        $vendor->restore();

        return ApiSuccessResponse::make($this->apresentar($vendor));
    }

    /**
     * Apaga um técnico DE VEZ. Não é o `suspend` -- não há volta.
     *
     * O `suspend` faz soft delete e o técnico pode ser reposto. Isto remove o
     * utilizador, e com ele o vendor (users→vendors é CASCADE) e tudo o que
     * pende dele: avaliações, documentos, candidaturas a pedidos, zonas,
     * cidades, tickets de suporte, localização, lembretes de onboarding.
     *
     * O QUE NÃO DESAPARECE, e é preciso saber: `services.vendor_id` é SET NULL
     * (a coluna do cliente chama-se `customer_id` e não é tocada aqui). Os
     * serviços que a pessoa executou ficam na base de dados sem dono. O GMV total continua certo; o GMV POR TÉCNICO
     * deixa de fechar, e as faturas já emitidas passam a apontar para um
     * técnico que não existe. Foi decisão do André a 21/09/2026, com este custo
     * explicitado -- fica escrito aqui para quem vier a seguir não pensar que
     * foi descuido.
     *
     * Há tabelas que BLOQUEIAM o apagamento (NO ACTION): `schedule`,
     * `schedule_available`, `payshop_payment_methods`,
     * `payshop_payments_orders`, `phone_number_validation_codes` e
     * `impersonation_codes`. Sem as limpar antes, o DELETE rebenta com erro de
     * chave estrangeira em vez de fazer o que se pediu. Vão todas dentro da
     * mesma transação: ou sai tudo, ou não sai nada.
     */
    public function forceDestroy(int $id): ApiSuccessResponse|ApiErrorResponse
    {
        $vendor = Vendor::withTrashed()->with('user')->find($id);

        if (! $vendor) {
            return new ApiErrorResponse(null, 'Técnico não encontrado.', 404);
        }

        $user = $vendor->user;

        if (! $user) {
            // Vendor órfão (utilizador já removido): apaga só o vendor.
            $vendor->forceDelete();

            return ApiSuccessResponse::make(['id' => $id, 'deleted' => true, 'orphan_services' => 0]);
        }

        // Quantos serviços ficam sem dono -- contado ANTES, porque depois do
        // SET NULL já não há como saber quais eram.
        $orfaos = Service::where('vendor_id', $vendor->id)->count();

        $nome = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        DB::transaction(function () use ($user, $vendor) {
            /*
              As tabelas NO ACTION, que bloqueiam o DELETE se lá houver linhas.
              Os nomes das colunas NÃO são todos `user_id` -- `schedule` liga
              pelo cliente em `customer_id` e pelo técnico em `vendor_id`, e
              `impersonation_codes` usa `generated_by_id`. Confirmado contra o
              information_schema; assumir `user_id` em todas dava erro de
              coluna inexistente em três delas.
            */
            DB::table('schedule')->where('vendor_id', $vendor->id)->delete();
            DB::table('schedule')->where('customer_id', $user->id)->delete();
            DB::table('schedule_available')->where('vendor_id', $vendor->id)->delete();
            DB::table('phone_number_validation_codes')->where('user_id', $user->id)->delete();
            DB::table('impersonation_codes')->where('generated_by_id', $user->id)->delete();
            DB::table('payshop_payments_orders')->where('user_id', $user->id)->delete();
            DB::table('payshop_payment_methods')->where('user_id', $user->id)->delete();

            // E o utilizador leva o resto atrás, por CASCADE (vendors incluído).
            $user->forceDelete();
        });

        Log::warning('[admin] técnico apagado em definitivo', [
            'vendor_id' => $id,
            'nome' => $nome,
            'servicos_orfaos' => $orfaos,
            'admin_id' => auth()->id(),
        ]);

        return ApiSuccessResponse::make([
            'id' => $id,
            'deleted' => true,
            // Quem chama mostra isto a quem carregou no botão: é o custo real
            // da operação e não devia ficar só no log do servidor.
            'orphan_services' => $orfaos,
        ]);
    }

    /**
     * Cria um técnico de teste (is_test=true) já pronto a ficar "Online" na
     * app-vendor de imediato -- ver App\Http\Controllers\Api\Vendor\Status\
     * StatusController: ficar Online exige email+telefone verificados,
     * IBAN, invoice_workspace, at_valid+at_user com "/", e TODOS os
     * documentos obrigatórios aprovados (Vendor::allDocumentsVerified()).
     * Sem isto, uma conta "de teste" só serviria para aparecer numa lista,
     * nunca para o pedido real do rpacheco (testar o mapa ao vivo a sério).
     *
     * Login é por email+password (a app-vendor NÃO usa SMS OTP -- isso é só
     * para clientes, ver App\Http\Controllers\Api\Auth\PhoneLoginVerifyController).
     * A password é gerada aqui e devolvida uma única vez; não fica
     * recuperável depois (só hash na BD).
     *
     * `is_test` está fora do $fillable de propósito (fecha o bypass público
     * -- ver VendorResource.php) -- por isso é atribuído diretamente, não
     * via create()/fill().
     *
     * invoice_workspace/invoice_account_id/auth_token usam os mesmos
     * valores fixos do sandbox InvoiceXpress que o comando artisan
     * `create:vendor-user` já usa para contas de teste manuais.
     */
    public function createTestAccount(Request $request): ApiSuccessResponse|ApiErrorResponse
    {
        $firstName = trim((string) $request->string('first_name'));
        $lastName = trim((string) $request->string('last_name'));
        $phone = trim((string) $request->string('phone_number'));
        $email = trim((string) $request->string('email'));

        if ($firstName === '' || $lastName === '' || $phone === '') {
            return new ApiErrorResponse(null, 'Nome e telefone são obrigatórios.', 422);
        }

        if (User::where('phone_number', $phone)->exists()) {
            return new ApiErrorResponse(null, 'Já existe uma conta com este número de telefone.', 409);
        }

        if ($email !== '' && User::where('email', $email)->exists()) {
            return new ApiErrorResponse(null, 'Já existe uma conta com este email.', 409);
        }

        $password = Str::password(12);

        $vendor = DB::transaction(function () use ($firstName, $lastName, $phone, $email, $password) {
            $user = new User([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email !== '' ? $email : 'teste.'.Str::random(8).'@piquet.pt',
                'phone_number' => $phone,
            ]);
            $user->password = Hash::make($password);
            $user->phone_number_verified_at = now();
            $user->email_verified_at = now();
            $user->is_test = true;
            $user->save();

            $vendor = $user->vendor()->create([
                'username' => 'teste_'.Str::slug("$firstName $lastName").'_'.$user->id,
                'iban' => 'PT50000201231234567890154',
                'invoice_workspace' => 'rwinteractive',
                'invoice_account_id' => 152518,
                'auth_token' => 'a22c3d79790ba5a70ae41f59a9968b669ad12d06',
                // at_valid vem default(true) da migration; só falta o "/" no at_user.
                'at_user' => '999999999/1',
            ]);

            // Aprova já todos os documentos obrigatórios -- sem isto,
            // allDocumentsVerified() fica false e o vendor nunca consegue
            // ficar Online (ver StatusController).
            foreach (Document::where('required', true)->get() as $doc) {
                VendorDocuments::create([
                    'vendor_id' => $vendor->id,
                    'document_id' => $doc->id,
                    'status' => 'approved',
                ]);
            }

            return $vendor;
        });

        return ApiSuccessResponse::make([
            'id' => $vendor->id,
            'name' => "$firstName $lastName",
            'email' => $vendor->user->email,
            'password' => $password,
            'phone_number' => $phone,
        ]);
    }

    /**
     * Indicadores da aba "Visão geral" -- só o que dá para calcular a partir
     * de dados reais. Substitui os "estados" fictícios do mock (aprovado/
     * disponivel/ativo/em_validacao/perfil_incompleto/suspenso), que não
     * existem no Laravel, por sinais reais equivalentes:
     * - "eligible" = App\Filament\Widgets\Vendors\VendorStats::eligibleVendorCount()
     *   (mesma lógica híbrida SQL+PHP, aqui sem o ramo "developer" porque a
     *   admin.api não distingue papéis) -- é o que o Filament chama
     *   "Podem aceitar serviço".
     * - "online" = StatusVendor::ONLINE (não "ativos nos últimos 30 dias",
     *   que não existe como conceito real).
     * - "docComplete" = Vendor::allDocumentsVerified (accessor real).
     * - "inValidation" = tem pelo menos um documento 'pending' em
     *   vendor_documents (aproximação -- não há um estado "em validação"
     *   dedicado, só o estado por documento).
     * - "avgApprovalTime" fica de fora de propósito: vendor_documents só tem
     *   created_at/updated_at, sem um campo "revisto em" -- sem sinal fiável
     *   (decisão do utilizador, mesmo princípio de "vazio em vez de inventar"
     *   já aplicado em CustomerController::bySource()/retention()).
     */
    public function metrics(): ApiSuccessResponse
    {
        $registered = $this->baseQuery()->count();
        $newThisMonth = $this->baseQuery()->where('created_at', '>=', now()->startOfMonth())->count();
        $online = $this->baseQuery()->where('status', StatusVendor::ONLINE)->count();

        $eligibleIds = PodemTrabalhar::ids();
        $eligible = $eligibleIds->count();

        /*
         * «Sem serviços» = dos ELEGÍVEIS, quantos nunca levaram nenhum ao fim.
         * O denominador são os elegíveis e não os registados -- perguntar
         * quantos dos 461 nunca trabalharam responde-se sozinho.
         *
         * Contava só `CLOSED`. A regra da AT, no mesmo código, conta
         * `CLOSED + CLOSED_PENDING_PAYMENT + ARCHIVED`: quem tivesse serviços
         * apenas nesses dois estados aparecia aqui como «nunca fez nenhum» e
         * já tinha a AT exigida por «já fez três». Agora ambos leem a mesma
         * lista, em `ServiceStatus::concluidos()`.
         */
        $withClosedService = Service::query()
            ->whereIn('vendor_id', $eligibleIds)
            ->whereIn('status', ServiceStatus::concluidos())
            ->where('is_test', false)
            ->distinct()
            ->pluck('vendor_id');
        $noServices = $eligibleIds->count() - $withClosedService->count();

        $vendors = $this->baseQuery()->get();
        $docComplete = $vendors->filter(fn (Vendor $v) => $v->all_documents_verified)->count();

        $inValidation = VendorDocuments::query()
            ->where('status', 'pending')
            ->whereHas('vendor.user', fn ($q) => $q->where('is_test', false))
            ->distinct('vendor_id')
            ->count('vendor_id');

        // Tempo médio (dias) entre o registo do vendor e o seu primeiro
        // serviço (qualquer estado -- "primeira atividade", não "primeiro
        // serviço concluído"), só sobre quem já tem pelo menos um serviço.
        $firstServiceByVendor = Service::query()
            ->whereIn('vendor_id', $vendors->pluck('id'))
            ->where('is_test', false)
            ->orderBy('created_at')
            ->get(['vendor_id', 'created_at'])
            ->groupBy('vendor_id')
            ->map(fn ($g) => $g->first()->created_at);
        $vendorsById = $vendors->keyBy('id');
        $daysToFirstService = $firstServiceByVendor->map(function ($firstServiceAt, $vendorId) use ($vendorsById) {
            $vendor = $vendorsById->get($vendorId);

            return $vendor ? $vendor->created_at->diffInDays($firstServiceAt) : null;
        })->filter(fn ($d) => $d !== null);

        return ApiSuccessResponse::make([
            'registered' => $registered,
            'newThisMonth' => $newThisMonth,
            'eligible' => $eligible,
            'online' => $online,
            'docComplete' => $docComplete,
            'inValidation' => $inValidation,
            'noServices' => $noServices,
            'approvalRate' => $registered > 0 ? round($eligible / $registered * 100, 1) : 0,
            'profileCompletionRate' => $registered > 0 ? round($docComplete / $registered * 100, 1) : 0,
            'avgTimeToFirstService' => $daysToFirstService->count() > 0 ? round($daysToFirstService->avg(), 1) : 0,
        ]);
    }

    /**
     * Mapa ao vivo -- só informativo, sem qualquer efeito no fluxo de
     * pedidos/matching (esse continua inteiramente na app). Mostra só
     * técnicos com status=Online E localização atualizada recentemente: a
     * app-vendor só envia GPS (PUT /vendor/location/update, a cada ~7.5s)
     * enquanto o técnico está "Online" ou com um serviço aceite (ver
     * LocationContext.tsx / home/index.tsx da app-vendor) -- por isso
     * "Online" sem localização recente é sinal de app em segundo plano/sem
     * rede, não presença real, e fica de fora para não mostrar um pin preso
     * num sítio antigo.
     *
     * `include_test=1` inclui vendors de utilizadores marcados como teste
     * (excluídos por omissão, como em toda a `baseQuery()`) -- só para dar
     * ao staff uma forma de validar o mapa sem depender de um técnico real
     * estar online; o valor por omissão continua a mostrar só dados reais.
     */
    public function liveLocations(Request $request): ApiSuccessResponse
    {
        $includeTest = $request->boolean('include_test');

        $query = $includeTest ? Vendor::query() : $this->baseQuery();

        $vendors = $query
            ->where('status', StatusVendor::ONLINE)
            ->whereHas('currentLocation', fn ($q) => $q->where('updated_at', '>=', now()->subMinutes(10)))
            ->with(['user', 'currentLocation', 'servicesTypes'])
            ->get();

        return ApiSuccessResponse::make(
            $vendors->map($this->presentLiveLocation(...))->all()
        );
    }

    private function presentLiveLocation(Vendor $vendor): array
    {
        $user = $vendor->user;
        $location = $vendor->currentLocation;

        return [
            'id' => $vendor->id,
            'name' => $user ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null) : null,
            'is_test' => (bool) ($user->is_test ?? false),
            'latitude' => $location ? (float) $location->latitude : null,
            'longitude' => $location ? (float) $location->longitude : null,
            'updated_at' => $location?->updated_at?->toIso8601String(),
            'categories' => $vendor->servicesTypes->pluck('name')->all(),
        ];
    }

    /**
     * Técnicos por categoria (área de operação, ex.: "Canalização").
     *
     * `value` são os REGISTADOS na categoria, em qualquer estado: pelos tipos
     * de serviço que fazem (é por eles que o matching os escolhe) ou pela
     * ligação direta à área, que existe em poucos -- a mesma regra do filtro
     * `categoria` da lista. Contava só a ligação direta: quem faz
     * desentupimentos não contava em Canalização.
     *
     * `podem_trabalhar` são os que o matching pode convidar hoje para um pedido
     * da categoria: fazem um tipo de serviço dela e cumprem o
     * `can_accept_service` (PodemTrabalhar). Falta-lhes só estar Online; os que
     * estão veem-se em Pedidos › Ao vivo.
     *
     * `podem_trabalhar_com_at` são os desses que têm a AT validada; os outros
     * só têm a AT em falta e trabalham até ao terceiro serviço
     * (SERVICOS_ANTES_DA_AT). Os prontos são a soma dos dois, e é assim que o
     * backoffice os mostra. "Validada" é a regra da lista de técnicos do
     * backoffice: a flag E a data. A flag sozinha vem ligada de origem no
     * registo, e a data sozinha é só a última vez que funcionou.
     *
     * NOTA: "operation_areas" são CATEGORIAS/OFÍCIOS, não zonas geográficas.
     * A geografia está nas cidades que cada técnico escolhe
     * (CoberturaPelasCidades).
     */
    public function byCategory(): ApiSuccessResponse
    {
        $podem = PodemTrabalhar::ids()->flip();
        $registados = [];
        $prontos = [];
        $comAt = [];

        $this->baseQuery()->with(['operationAreas', 'servicesTypes'])->get()
            ->each(function (Vendor $vendor) use ($podem, &$registados, &$prontos, &$comAt) {
                $doMatching = $vendor->servicesTypes->pluck('operation_area_id')->filter()
                    ->map(fn ($id) => (int) $id)->unique();
                $diretas = $vendor->operationAreas->pluck('id')->map(fn ($id) => (int) $id);

                foreach ($doMatching->merge($diretas)->unique() as $area) {
                    $registados[$area] = ($registados[$area] ?? 0) + 1;
                }

                if ($podem->has($vendor->id)) {
                    $atValidada = $vendor->at_valid && $vendor->at_validated_at !== null;

                    foreach ($doMatching as $area) {
                        $prontos[$area] = ($prontos[$area] ?? 0) + 1;

                        if ($atValidada) {
                            $comAt[$area] = ($comAt[$area] ?? 0) + 1;
                        }
                    }
                }
            });

        // As áreas apagadas ficam de fora (o SoftDeletes do modelo tira-as).
        $areas = OperationArea::query()->whereIn('id', array_keys($registados))->ordered()->get();

        return ApiSuccessResponse::make($areas->map(fn (OperationArea $area) => [
            'name' => $area->name,
            'value' => $registados[$area->id],
            'podem_trabalhar' => $prontos[$area->id] ?? 0,
            'podem_trabalhar_com_at' => $comAt[$area->id] ?? 0,
        ])->values()->all());
    }

    /**
     * Técnicos por localização -- conta cada vendor nas zonas de cobertura
     * que declarou (App\Models\GeneralSettings\AllowedZone), não a morada
     * fiscal/de agendamentos. Um vendor com várias zonas conta em cada uma.
     */
    public function byLocation(): ApiSuccessResponse
    {
        $counts = [];
        $this->baseQuery()->with('allowedZones')->get()->each(function (Vendor $vendor) use (&$counts) {
            foreach ($vendor->allowedZones as $zone) {
                $counts[$zone->city] = ($counts[$zone->city] ?? 0) + 1;
            }
        });

        return ApiSuccessResponse::make(
            collect($counts)->map(fn ($value, $name) => ['name' => $name, 'value' => $value])
                ->sortByDesc('value')->values()->all()
        );
    }

    /**
     * Top técnicos por receita gerada para a Piquet (amount - amount_for_vendor,
     * a comissão), só sobre serviços concluídos reais. amountReceived é o
     * valor pago ao próprio técnico (amount_for_vendor). Nem "amount" nem
     * "amount_for_vendor" têm um Attribute mutator (ao contrário de
     * price_rate) -- são colunas em cêntimos simples, SUM() direto é seguro.
     */
    public function top(Request $request): ApiSuccessResponse
    {
        $limit = min((int) $request->integer('limit', 10), 50);
        $vendorIds = $this->baseQuery()->pluck('id');

        $rows = Service::query()
            ->whereIn('vendor_id', $vendorIds)
            ->where('status', ServiceStatus::CLOSED)
            ->where('is_test', false)
            ->selectRaw('vendor_id, COUNT(*) as services_completed, SUM(amount_for_vendor) as amount_received, SUM(amount - amount_for_vendor) as amount_generated, AVG(rating_by_customer) as avg_rating')
            ->groupBy('vendor_id')
            ->orderByDesc('amount_generated')
            ->limit($limit)
            ->get();

        $vendors = Vendor::whereIn('id', $rows->pluck('vendor_id'))->get()->keyBy('id');

        return ApiSuccessResponse::make($rows->map(function ($row) use ($vendors) {
            $vendor = $vendors->get($row->vendor_id);
            $user = $vendor?->user;

            return [
                'id' => $row->vendor_id,
                'name' => $user ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null) : null,
                'servicesCompleted' => (int) $row->services_completed,
                'averageRating' => $row->avg_rating !== null ? round((float) $row->avg_rating, 2) : 0,
                'piquetRevenue' => round(((int) $row->amount_generated) / 100, 2),
                'amountReceived' => round(((int) $row->amount_received) / 100, 2),
            ];
        })->values()->all());
    }

    /**
     * Procura vs oferta por cidade, limitado às 20 cidades com mais procura
     * para o heatmap não ficar ilegível.
     *
     * - procura: pedidos de serviço reais nessa cidade (Service.address->city).
     * - oferta: quem marcou a cidade no questionário antigo da app
     *   (`vendor_allowed_zones`). A app já não o mostra; fica para o backoffice
     *   que ainda o lê.
     * - oferta_cidades: quem recebe um pedido no centro da cidade, pelas
     *   cidades que escolheu na app -- a regra do matching
     *   (CoberturaPelasCidades). `contagem` diz se foi pelo raio ou só pelo nome.
     * - podem_trabalhar: desses, os que cumprem o `can_accept_service`.
     */
    public function coverage(): ApiSuccessResponse
    {
        $supplyByZoneId = DB::table('vendor_allowed_zones')
            ->join('vendors', 'vendors.id', '=', 'vendor_allowed_zones.vendor_id')
            ->join('users', 'users.id', '=', 'vendors.user_id')
            ->where('users.is_test', false)
            ->whereNull('vendors.deleted_at')
            ->selectRaw('vendor_allowed_zones.allowed_zone_id, COUNT(DISTINCT vendor_allowed_zones.vendor_id) as supply')
            ->groupBy('vendor_allowed_zones.allowed_zone_id')
            ->pluck('supply', 'allowed_zone_id');

        $supplyByCity = [];
        AllowedZone::select('id', 'city')->get()->each(function ($zone) use (&$supplyByCity, $supplyByZoneId) {
            $supplyByCity[$zone->city] = ($supplyByCity[$zone->city] ?? 0) + (int) ($supplyByZoneId[$zone->id] ?? 0);
        });

        $demandByCity = Service::query()
            ->where('is_test', false)
            ->whereNotNull('address')
            ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(address, \'$.city\')) as city, COUNT(*) as total')
            ->groupBy('city')
            ->pluck('total', 'city');

        $cities = collect($supplyByCity)->keys()->merge($demandByCity->keys())->unique()->filter();

        $rows = $cities->map(function ($city) use ($supplyByCity, $demandByCity) {
            $supply = $supplyByCity[$city] ?? 0;
            $demand = (int) ($demandByCity[$city] ?? 0);

            return [
                'name' => $city,
                'procura' => $demand,
                'oferta' => $supply,
                'ratio' => $supply > 0 ? round($demand / $supply, 2) : $demand,
            ];
        })->sortByDesc('procura')->take(20)->values();

        $nasCidades = new CoberturaPelasCidades;
        $podem = PodemTrabalhar::ids()->flip();

        return ApiSuccessResponse::make($rows->map(function (array $row) use ($nasCidades, $podem) {
            $quem = $nasCidades->naCidade((string) $row['name']);

            return $row + [
                'oferta_cidades' => count($quem['tecnicos']),
                'podem_trabalhar' => count(array_filter($quem['tecnicos'], fn (int $id) => $podem->has($id))),
                'contagem' => $quem['contagem'],
            ];
        })->all());
    }

    /**
     * POST /v1/admin/vendors/{vendor}/invoice-workspace
     *
     * Cria o workspace de faturação no InvoiceXpress. Sem ele a Piquet não
     * consegue emitir fatura em nome do técnico no fim do serviço -- é o
     * mesmo que a ação "Criar workspace de faturação" do Filament faz
     * (App\Filament\Infolists\Sections\CompanySection).
     *
     * As condições (email/telefone verificado, documentos aprovados, IBAN,
     * morada fiscal, workspace ainda não criado) são as MESMAS do Filament, e
     * vêm da mesma função -- duplicá-las aqui era garantir que divergiam.
     */
    public function createInvoiceWorkspace(Vendor $vendor): ApiSuccessResponse|ApiErrorResponse
    {
        if ($razao = CompanySection::getWorkspaceDisabledReason($vendor)) {
            return new ApiErrorResponse(null, $razao, 409);
        }

        try {
            (new SystemInvoiceService)->createWorkspace($vendor);
        } catch (\Throwable $e) {
            report($e);

            return new ApiErrorResponse(null, 'Não foi possível criar o workspace: '.$e->getMessage(), 502);
        }

        return ApiSuccessResponse::make($this->apresentar($vendor));
    }

    /**
     * PUT /v1/admin/vendors/{vendor}/at-validation  {valid: bool}
     *
     * `valid: true` é o "Validar AT" do Filament: testa as credenciais no
     * InvoiceXpress e grava o resultado (ValidacaoDaAt, as mesmas condições e
     * os mesmos passos). Não é um carimbo: se o InvoiceXpress recusar, a AT
     * fica por validar e a resposta diz porquê.
     *
     * `valid: false` retira a validação. A data da última validação fica: o
     * Filament mostra-a como "Última vez OK", e é verdade. Depois do terceiro
     * serviço, um técnico sem a AT validada deixa de receber pedidos.
     *
     * O backoffice tinha os botões a chamar esta rota antes de ela existir, e
     * davam sempre erro.
     */
    public function atValidation(Request $request, Vendor $vendor): ApiSuccessResponse|ApiErrorResponse
    {
        $request->validate(['valid' => ['required', 'boolean']]);

        if (! $request->boolean('valid')) {
            $vendor->at_valid = false;
            $vendor->save();

            return ApiSuccessResponse::make($this->apresentar($vendor));
        }

        if ($razao = ValidacaoDaAt::bloqueio($vendor)) {
            return new ApiErrorResponse(null, $razao, 409);
        }

        try {
            ValidacaoDaAt::validar($vendor);
        } catch (VendorInvalidAtCredentials) {
            return new ApiErrorResponse(
                null,
                'O InvoiceXpress recusou as credenciais da AT. Confirma com o técnico o subutilizador e a palavra-passe.',
                422,
            );
        } catch (\Exception $e) {
            report($e);

            return new ApiErrorResponse(null, 'Não foi possível validar a AT: '.$e->getMessage(), 502);
        }

        return ApiSuccessResponse::make($this->apresentar($vendor));
    }

    private function present(Vendor $vendor): array
    {
        // NÃO usar vendor->name / vendor->fullName / vendor->full_name -- todos
        // delegam em user->full_name, que não existe em lado nenhum do model
        // User (ver nota nos outros controllers de vendor/customer sobre
        // User::setNameAttribute() nunca gravar 'name'). first_name/last_name
        // do user são as colunas reais.
        $user = $vendor->user;
        $fiscal = $vendor->addresses->firstWhere('address_type', AddressType::FISCAL_ADDRESS);

        return [
            'id' => $vendor->id,
            'name' => $user ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null) : null,
            'nif' => $user?->nif,
            'phone_number' => $user?->phone_number,
            // price_rate vem em cêntimos na coluna; o accessor priceRate() do
            // model devolve uma string formatada (com separador de milhares) --
            // getRawOriginal() para converter para euros sem passar por ele.
            'price_rate' => $vendor->getRawOriginal('price_rate') !== null
                ? round(((int) $vendor->getRawOriginal('price_rate')) / 100, 2)
                : null,
            'operation_areas' => $vendor->operationAreas->pluck('name')->all(),
            /*
             * O que o técnico faz, segundo o próprio matching.
             *
             * `operation_areas` já cá estava e contém nomes de ofícios, mas na
             * prática está quase sempre vazio: numa leitura de 100 técnicos em
             * 18/09/2026, um único tinha valor. Quem decide se um técnico serve
             * para um pedido é o VendorRankingService, e esse filtra por
             * `servicesTypes` -- é este o campo que diz mesmo o que ele faz.
             *
             * Sem ele exposto aqui, quem consome a API de admin não consegue
             * responder a "quem faz canalização" sem ir à base de dados.
             */
            'services_types' => $vendor->servicesTypes->pluck('name')->all(),
            'can_accept_service' => (bool) $vendor->can_accept_service,
            /*
             * Os documentos obrigatórios estão todos aprovados.
             *
             * Existia só numa contagem agregada (metrics.docComplete) e não
             * por técnico. Sem isto, quem consome a API tinha de o DEDUZIR do
             * `account_blocker` -- e esse devolve só o primeiro problema, por
             * isso quem tem os contactos por verificar ficava por classificar.
             *
             * O backoffice andou a mostrar "o perfil completo pode estar até
             * 220 abaixo do real" quando a margem verdadeira eram 6. Um número
             * derivado obriga a anunciar uma incerteza, e a incerteza anunciada
             * assusta mais do que o erro que esconde.
             */
            'all_documents_verified' => (bool) $vendor->all_documents_verified,
            'at_valid' => (bool) $vendor->at_valid,
            'at_validated_at' => $vendor->at_validated_at?->toIso8601String(),
            // Dados da empresa -- os mesmos do CompanySection do Filament. Sem
            // eles o backoffice não consegue dizer porque é que um técnico não
            // pode receber faturas em nome dele.
            'at_user' => $vendor->at_user,
            'company_name' => $vendor->company_name,
            'iban' => $vendor->iban,
            'invoice_workspace' => $vendor->invoice_workspace ?: null,
            // Porque é que o botão "Criar workspace" está bloqueado (null = pode
            // criar). Vem calculado do servidor de propósito: as condições
            // dependem de relações (documentos, morada fiscal) que não são
            // enviadas na listagem, logo o cliente não as podia avaliar.
            'invoice_workspace_blocker' => CompanySection::getWorkspaceDisabledReason($vendor),
            /*
             * O MESMO bloqueio, em codigo em vez de frase.
             *
             * A frase acima e para mostrar ao lado de um botao: esta escrita na
             * terceira pessoa e inclui o caso "ja existe workspace", que e
             * assunto de administracao e nao do tecnico. Um codigo agrupa-se, e
             * e o que o backoffice precisa para responder a "quantos tecnicos
             * estao parados por falta de IBAN?".
             *
             * E o mesmo valor que o GET /me devolve a app do tecnico, da mesma
             * fonte (Vendor::invoicingBlocker), para os dois lados nunca
             * divergirem: null quer dizer que esta tudo pronto.
             */
            'account_blocker' => $vendor->invoicingBlocker(),
            // Morada FISCAL (a que vai na fatura), não a morada de serviço --
            // é a que o CompanySection exige para criar o workspace.
            'billing_address' => $fiscal
                ? trim(($fiscal->street_name ?? '').' '.($fiscal->street_number ?? ''))
                : null,
            'postal_code' => $fiscal?->postal_code,
            'city' => $fiscal?->city,
            'status' => $vendor->status?->value,
            'suspended_at' => $vendor->deleted_at?->toIso8601String(),
            'created_at' => $vendor->created_at?->toIso8601String(),
            // O resumo (ver comResumo e a regra do estado em ESTADOS).
            'estado' => $this->estadoDe($vendor),
            'services_30d_count' => (int) ($vendor->services_30d_count ?? 0),
            'last_service_at' => $this->iso($vendor->last_service_at ?? null),
            // Quando esteve ativo pela última vez: a última localização ou o
            // último serviço, o mais recente. É o que ordena `sort=online`.
            'last_active_at' => $this->iso(collect([$vendor->last_location_at ?? null, $vendor->last_service_at ?? null])
                ->filter()
                ->map(fn ($v) => Carbon::parse($v, 'UTC'))
                ->max()),
            'rating_avg' => isset($vendor->rating_avg) ? round((float) $vendor->rating_avg, 2) : null,
            'rating_count' => (int) ($vendor->rating_count ?? 0),
        ];
    }

    private function estadoDe(Vendor $vendor): string
    {
        if ($vendor->trashed()) {
            return 'suspenso';
        }

        if ($vendor->status === StatusVendor::ONLINE) {
            return 'online';
        }

        // Pela MESMA regra em SQL com que a lista filtra (ver comResumo): o
        // estado que se mostra é sempre o estado por que se filtrou.
        $emDia = $vendor->getAttribute('documentos_em_dia');
        $emDia = $emDia === null ? $vendor->all_documents_verified : (bool) $emDia;

        return $emDia ? 'pronto' : 'em_aprovacao';
    }

    /** Uma data da base (UTC) em ISO 8601, ou null. */
    private function iso(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return ($valor instanceof \DateTimeInterface ? Carbon::instance($valor) : Carbon::parse($valor, 'UTC'))->toIso8601String();
    }
}
