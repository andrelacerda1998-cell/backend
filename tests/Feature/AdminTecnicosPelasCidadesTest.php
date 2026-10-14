<?php

namespace Tests\Feature;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\AllowedZone;
use App\Models\GeneralSettings\City;
use App\Models\GeneralSettings\Document;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Quantos técnicos tem cada zona, cada cidade e cada categoria -- contados como
 * o matching os escolhe.
 *
 * As Zonas e a Cobertura contavam o questionário antigo da app
 * (`vendor_allowed_zones`), que deixou de existir: diziam "ninguém" em zonas
 * onde havia técnicos. Contam agora as cidades que cada técnico escolhe na app,
 * com a regra do matching (City::contem: centro e raio, ou o nome), e quantos
 * desses podem trabalhar (`can_accept_service`).
 *
 * Os nomes das cidades e das categorias são inventados e as coordenadas ficam
 * no meio do Atlântico: o catálogo verdadeiro e o que outros testes deixam na
 * base de dados não tocam em nada disto.
 */
class AdminTecnicosPelasCidadesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Um documento obrigatório deixado por outro teste tirava a toda a gente
        // o `can_accept_service`. Dentro da transação: volta como estava.
        Document::query()->update(['required' => false]);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    /**
     * Um técnico. Pronto = pode trabalhar; sem o telemóvel confirmado não pode
     * (é o primeiro passo do `can_accept_service`).
     *
     * @param  list<City>  $cidades
     */
    private function tecnico(string $nome, array $cidades = [], bool $pronto = true, bool $teste = false): Vendor
    {
        [$primeiro, $apelido] = explode(' ', $nome);

        $user = User::factory()->create([
            'first_name' => $primeiro,
            'last_name' => $apelido,
            'is_test' => $teste,
            'phone_number_verified_at' => $pronto ? now() : null,
        ]);

        $vendor = Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id]);
        $vendor->availableCities()->attach(collect($cidades)->pluck('id')->all());

        return $vendor;
    }

    private function cidade(string $nome, ?float $latitude = null, ?float $longitude = null): City
    {
        return City::create([
            'name' => $nome,
            'district' => 'Teste',
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_km' => 15,
        ]);
    }

    /** Uma categoria com um tipo de serviço, que é por onde o matching escolhe. */
    private function tipoNaCategoria(string $categoria): ServicesType
    {
        $area = OperationArea::create(['name' => $categoria]);
        $tipo = new ServicesType;
        $tipo->operation_area_id = $area->id;
        $tipo->time = 60;
        $tipo->setTranslations('name', ['en' => "Tipo de {$categoria}", 'pt-pt' => "Tipo de {$categoria}"]);
        $tipo->save();

        return $tipo;
    }

    private function zonas(): array
    {
        $resposta = $this->withAuth()->getJson('/api/v1/admin/allowed-zones?per_page=200')->assertOk();

        return [collect($resposta->json('data.items'))->keyBy('city'), $resposta->json('data.meta')];
    }

    /**
     * O cenário do raio: uma zona com coordenadas, uma cidade vizinha a ~11 km
     * (dentro dos 15 km do raio) e uma a ~111 km.
     *
     * @return array<string, Vendor>
     */
    private function cenarioDoRaio(): array
    {
        $centro = $this->cidade('Vila Teste Centro', 30.0, -40.0);
        $vizinha = $this->cidade('Vila Teste Vizinha', 30.1, -40.0);
        $longe = $this->cidade('Vila Teste Longe', 31.0, -40.0);

        AllowedZone::create(['city' => 'Vila Teste Centro', 'district' => 'Teste']);

        $ana = $this->tecnico('Ana Antunes', [$vizinha]);
        $filipe = $this->tecnico('Filipe Faria', [$centro, $vizinha]);
        $bruno = $this->tecnico('Bruno Brito', [$centro], pronto: false);
        $this->tecnico('Carla Costa', [$longe]);
        $this->tecnico('Teste Interno', [$centro], teste: true);
        $this->tecnico('Duarte Dias', [$centro])->delete(); // suspenso

        return compact('ana', 'filipe', 'bruno');
    }

    public function test_a_zona_conta_quem_a_cobre_pelo_raio_das_cidades_escolhidas(): void
    {
        $this->cenarioDoRaio();
        // O questionário antigo continua a contar à parte, e não se mistura.
        $zona = AllowedZone::query()->where('city', 'Vila Teste Centro')->first();
        $this->tecnico('Quim Questionario')->allowedZones()->attach($zona->id);

        [$zonas] = $this->zonas();

        // A Ana escolheu a vizinha, cujo raio apanha o centro; o Filipe conta
        // uma vez com duas cidades; o Bruno conta mas não pode trabalhar. Fora:
        // a cidade longe, a conta de teste e o suspenso.
        $this->assertSame(3, $zonas['Vila Teste Centro']['tecnicos_nas_cidades']);
        $this->assertSame(2, $zonas['Vila Teste Centro']['podem_trabalhar']);
        $this->assertSame('raio', $zonas['Vila Teste Centro']['contagem']);
        $this->assertSame(1, $zonas['Vila Teste Centro']['vendors_count']);
    }

    /** Sem coordenadas (falta o `cities:geocode`), só conta o mesmo nome. */
    public function test_sem_coordenadas_a_zona_conta_so_pelo_nome(): void
    {
        $semCoordenadas = $this->cidade('Vila Teste Sem Mapa');
        $comCoordenadas = $this->cidade('Vila Teste Com Mapa', 30.0, -40.0);

        // Escrita como a equipa a escreve: maiúsculas e acentos não contam.
        AllowedZone::create(['city' => 'VILA TÉSTE SEM MAPA', 'district' => 'Teste']);

        $this->tecnico('Gil Gomes', [$semCoordenadas]);
        $this->tecnico('Hugo Henriques', [$comCoordenadas]);

        [$zonas] = $this->zonas();

        $this->assertSame(1, $zonas['VILA TÉSTE SEM MAPA']['tecnicos_nas_cidades']);
        $this->assertSame('nome', $zonas['VILA TÉSTE SEM MAPA']['contagem']);
    }

    /** Uma zona que não está no catálogo: nenhum técnico a pode escolher na app. */
    public function test_uma_zona_fora_do_catalogo_diz_que_ninguem_a_pode_escolher(): void
    {
        AllowedZone::create(['city' => 'Vila Teste Inexistente', 'district' => 'Teste']);

        [$zonas] = $this->zonas();

        $this->assertSame(0, $zonas['Vila Teste Inexistente']['tecnicos_nas_cidades']);
        $this->assertSame('fora_do_catalogo', $zonas['Vila Teste Inexistente']['contagem']);
    }

    /**
     * Quem não escolheu cidades não entra em zona nenhuma -- no matching não
     * tem restrição -- e é contado à parte. Contas de teste e suspensos, não.
     */
    public function test_quem_nao_escolheu_cidades_e_contado_a_parte(): void
    {
        [, $antes] = $this->zonas();

        $this->tecnico('Ines Isidro');
        $this->tecnico('Joao Jorge', pronto: false);
        $this->tecnico('Teste Semcidades', teste: true);
        $this->tecnico('Luis Lopes')->delete();
        $this->tecnico('Mara Matos', [$this->cidade('Vila Teste Escolhida')]);

        [, $depois] = $this->zonas();

        $this->assertSame($antes['sem_cidades']['tecnicos'] + 2, $depois['sem_cidades']['tecnicos']);
        $this->assertSame($antes['sem_cidades']['podem_trabalhar'] + 1, $depois['sem_cidades']['podem_trabalhar']);
    }

    /** A Cobertura lista quem é, com os que já podem trabalhar primeiro. */
    public function test_a_cobertura_lista_os_tecnicos_de_cada_zona_pelas_cidades(): void
    {
        ['ana' => $ana, 'filipe' => $filipe, 'bruno' => $bruno] = $this->cenarioDoRaio();

        $resposta = $this->withAuth()->getJson('/api/v1/admin/coverage')->assertOk();
        $zona = collect($resposta->json('data.open'))->firstWhere('city', 'Vila Teste Centro');

        $this->assertSame(
            [$ana->id, $filipe->id, $bruno->id],
            array_column($zona['nas_cidades']['tecnicos'], 'id'),
        );
        $this->assertSame([true, true, false], array_column($zona['nas_cidades']['tecnicos'], 'podem_trabalhar'));
        $this->assertSame('Ana Antunes', $zona['nas_cidades']['tecnicos'][0]['name']);
        $this->assertSame(2, $zona['nas_cidades']['podem_trabalhar']);
        $this->assertSame('raio', $zona['nas_cidades']['contagem']);
        // O questionário continua no sítio de sempre, para quem o lê.
        $this->assertSame([], $zona['technicians']);
        $this->assertArrayHasKey('sem_cidades', $resposta->json('data'));
    }

    /** Procura vs oferta: a oferta pelas cidades ao lado da do questionário. */
    public function test_procura_e_oferta_contam_a_oferta_pelas_cidades(): void
    {
        $this->cenarioDoRaio();

        foreach (range(1, 5) as $i) {
            DB::table('services')->insert([
                'status' => ServiceStatus::CLOSED->value,
                'payment_status' => PaymentStatus::PAID->value,
                'address' => json_encode(['city' => 'Vila Teste Centro']),
                'is_test' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $resposta = $this->withAuth()->getJson('/api/v1/admin/vendors/coverage')->assertOk();
        $linha = collect($resposta->json('data'))->firstWhere('name', 'Vila Teste Centro');

        $this->assertSame(5, $linha['procura']);
        $this->assertSame(0, $linha['oferta']);
        $this->assertSame(3, $linha['oferta_cidades']);
        $this->assertSame(2, $linha['podem_trabalhar']);
        $this->assertSame('raio', $linha['contagem']);
    }

    /**
     * Registados: pelos tipos de serviço OU pela ligação direta à categoria (a
     * regra do filtro da lista). Podem trabalhar: só pelos tipos, que é por
     * onde o matching escolhe, e só quem cumpre o `can_accept_service`.
     */
    public function test_por_categoria_conta_registados_e_quem_pode_trabalhar(): void
    {
        $tipo = $this->tipoNaCategoria('Categoria Teste Cidades');

        $this->tecnico('Mario Moura')->servicesTypes()->attach($tipo->id);
        $this->tecnico('Nuno Neves', pronto: false)->servicesTypes()->attach($tipo->id);
        $this->tecnico('Olga Oliveira')->operationAreas()->attach($tipo->operation_area_id);
        $ambos = $this->tecnico('Paulo Pires');
        $ambos->servicesTypes()->attach($tipo->id);
        $ambos->operationAreas()->attach($tipo->operation_area_id);
        $this->tecnico('Teste Categoria', teste: true)->servicesTypes()->attach($tipo->id);

        $apagada = OperationArea::create(['name' => 'Categoria Teste Apagada']);
        $this->tecnico('Rui Rocha')->operationAreas()->attach($apagada->id);
        $apagada->delete();

        $resposta = $this->withAuth()->getJson('/api/v1/admin/vendors/by-category')->assertOk();
        $porNome = collect($resposta->json('data'))->keyBy('name');

        $this->assertSame(4, $porNome['Categoria Teste Cidades']['value']);
        $this->assertSame(2, $porNome['Categoria Teste Cidades']['podem_trabalhar']);
        $this->assertArrayNotHasKey('Categoria Teste Apagada', $porNome->all());
    }

    /**
     * Os prontos dividem-se pela AT: validada (a flag E a data, a regra da
     * lista do backoffice) ou só em falta, para quem ainda não fez três
     * serviços. Quem tem a AT validada mas não pode trabalhar não conta.
     */
    public function test_por_categoria_diz_quantos_prontos_tem_a_at_validada(): void
    {
        $tipo = $this->tipoNaCategoria('Categoria Teste AT');

        $validada = $this->tecnico('Ana Abreu');
        $validada->forceFill(['at_valid' => true, 'at_validated_at' => now()])->save();

        // A flag vem ligada de origem no registo: sem a data, ninguém a validou.
        $deOrigem = $this->tecnico('Bruno Barros');
        $deOrigem->forceFill(['at_valid' => true, 'at_validated_at' => null])->save();

        // Retirada (ou recusada pelo InvoiceXpress): a data fica, a flag desliga-se.
        $retirada = $this->tecnico('Carla Cunha');
        $retirada->forceFill(['at_valid' => false, 'at_validated_at' => now()->subWeek()])->save();

        $travada = $this->tecnico('Duarte Dias', pronto: false);
        $travada->forceFill(['at_valid' => true, 'at_validated_at' => now()])->save();

        foreach ([$validada, $deOrigem, $retirada, $travada] as $tecnico) {
            $tecnico->servicesTypes()->attach($tipo->id);
        }

        $linha = collect($this->withAuth()->getJson('/api/v1/admin/vendors/by-category')->assertOk()->json('data'))
            ->firstWhere('name', 'Categoria Teste AT');

        $this->assertSame(4, $linha['value']);
        $this->assertSame(3, $linha['podem_trabalhar']);
        $this->assertSame(1, $linha['podem_trabalhar_com_at']);
    }
}
