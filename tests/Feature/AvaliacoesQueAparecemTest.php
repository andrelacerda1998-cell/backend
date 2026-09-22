<?php

namespace Tests\Feature;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * As avaliações que existiam e nunca apareciam.
 *
 * A tabela `vendor_ratings` guarda a nota por ÁREA, e o `updateRatting()`
 * percorria a relação de áreas do profissional para a calcular. Só que
 * nenhuma das duas apps escrevia essa relação — mandam apenas
 * `services_types[]`. A relação ficava vazia, o ciclo não corria uma única
 * vez, e a tabela ficava vazia com ela.
 *
 * Resultado nos dois ecrãs onde o cliente decide: toda a gente aparecia como
 * "Novo na Piquet", o selo "Melhor avaliação" era inalcançável, e a ordenação
 * por nota degenerava sempre para preço — com um rodapé a dizer "Avaliações
 * reais" por cima.
 */
class AvaliacoesQueAparecemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    /**
     * @return array{0: Vendor, 1: ServicesType}
     */
    private function tecnicoCom(int $quantasAvaliacoes, float $nota = 4.0): array
    {
        $area = OperationArea::factory()->create();
        $tipo = ServicesType::factory()->create(['operation_area_id' => $area->id]);
        $vendor = Vendor::factory()->create();

        $vendor->setServices([['services_type_id' => $tipo->id]]);

        for ($i = 0; $i < $quantasAvaliacoes; $i++) {
            Service::factory()->create([
                'customer_id' => User::factory(),
                'vendor_id' => $vendor->id,
                'services_type_id' => $tipo->id,
                'status' => ServiceStatus::CLOSED,
                'rating_by_customer' => $nota,
            ]);
        }

        $vendor->refresh()->updateRatting();

        return [$vendor, $tipo];
    }

    private function notaGuardada(Vendor $vendor, ServicesType $tipo): ?float
    {
        $linha = $vendor->averageRating()
            ->where('operation_area_id', $tipo->operation_area_id)
            ->first();

        return $linha?->average_rating === null ? null : (float) $linha->average_rating;
    }

    // --- a relação que ninguém escrevia -----------------------------------

    public function test_escolher_os_tipos_de_servico_passa_a_definir_as_areas(): void
    {
        [$vendor, $tipo] = $this->tecnicoCom(0);

        $this->assertTrue(
            $vendor->operationAreas->contains('id', $tipo->operation_area_id),
            'quem faz "Rotura de Cano" trabalha em Canalização — não é preciso perguntar outra vez',
        );
    }

    public function test_sem_a_relacao_nao_havia_linha_nenhuma_para_calcular(): void
    {
        [$vendor, $tipo] = $this->tecnicoCom(3);

        $this->assertNotNull(
            $vendor->averageRating()->where('operation_area_id', $tipo->operation_area_id)->first(),
            'a tabela por área tem de deixar de estar vazia',
        );
    }

    // --- o mínimo de avaliações -------------------------------------------

    public function test_com_duas_avaliacoes_ainda_nao_se_publica_nota(): void
    {
        [$vendor, $tipo] = $this->tecnicoCom(2, nota: 2.0);

        $this->assertNull(
            $this->notaGuardada($vendor, $tipo),
            'uma média de duas avaliações não diz nada sobre ninguém',
        );
    }

    public function test_a_terceira_avaliacao_e_que_publica_a_nota(): void
    {
        // `rating_by_customer` é tinyint: as estrelas são inteiras.
        [$vendor, $tipo] = $this->tecnicoCom(3, nota: 4);

        $this->assertSame(4.0, $this->notaGuardada($vendor, $tipo));
    }

    public function test_a_media_e_a_media_e_nao_a_ultima_nota(): void
    {
        $area = OperationArea::factory()->create();
        $tipo = ServicesType::factory()->create(['operation_area_id' => $area->id]);
        $vendor = Vendor::factory()->create();
        $vendor->setServices([['services_type_id' => $tipo->id]]);

        foreach ([5, 4, 3] as $estrelas) {
            Service::factory()->create([
                'customer_id' => User::factory(),
                'vendor_id' => $vendor->id,
                'services_type_id' => $tipo->id,
                'status' => ServiceStatus::CLOSED,
                'rating_by_customer' => $estrelas,
            ]);
        }

        $vendor->refresh()->updateRatting();

        $this->assertSame(4.0, $this->notaGuardada($vendor, $tipo));
    }

    public function test_as_avaliacoes_contam_se_todas_mesmo_antes_de_a_nota_ser_visivel(): void
    {
        [$vendor, $tipo] = $this->tecnicoCom(2);

        $linha = $vendor->averageRating()->where('operation_area_id', $tipo->operation_area_id)->first();

        $this->assertSame(2, (int) $linha->total_ratings, 'contam-se; só não se publicam');
    }

    // --- o que o cliente lê -----------------------------------------------

    /**
     * O cartão do profissional mostra "⭐ 4,5 (4 avaliações)" — a nota E
     * quantas a sustentam. Um "4,5" sozinho não diz se vem de três serviços ou
     * de trinta, e é essa diferença que faz o cliente confiar no número.
     *
     * O componente já sabia desenhar as duas coisas; o que faltava era a
     * contagem chegar-lhe. Dois payloads mandavam a nota sem ela.
     */
    public function test_a_contagem_vai_junto_com_a_nota(): void
    {
        [$vendor, $tipo] = $this->tecnicoCom(4, nota: 5);

        $linha = $vendor->averageRating()
            ->where('operation_area_id', $tipo->operation_area_id)
            ->first();

        $this->assertSame(5.0, (float) $linha->average_rating);
        $this->assertSame(
            4,
            (int) $linha->total_ratings,
            'a contagem é de AVALIAÇÕES, não de serviços fechados: quem tem 40 serviços e 4 notas tem 4',
        );
    }

    // --- a leitura que escrevia -------------------------------------------

    /**
     * `toSearchableArray()` chamava o `updateRatting()`: uma serialização para
     * o índice de pesquisa que escrevia na base de dados. Uma reindexação
     * disparava um recálculo por profissional, e a nota certa dependia de
     * alguém, por acaso, reindexar.
     */
    public function test_serializar_para_a_pesquisa_deixa_de_escrever_na_base(): void
    {
        [$vendor, $tipo] = $this->tecnicoCom(3, nota: 4.0);

        // Alguém mexe na nota por fora, sem passar pelo recálculo.
        $vendor->averageRating()
            ->where('operation_area_id', $tipo->operation_area_id)
            ->update(['average_rating' => 1.0]);

        $vendor->fresh()->toSearchableArray();

        $this->assertSame(
            1.0,
            $this->notaGuardada($vendor->fresh(), $tipo),
            'serializar é ler: não pode mexer no que está guardado',
        );
    }

    /**
     * E o recálculo passa a acontecer onde a nota muda de facto.
     */
    public function test_avaliar_um_servico_recalcula_a_nota(): void
    {
        [$vendor, $tipo] = $this->tecnicoCom(2, nota: 5.0);

        $this->assertNull($this->notaGuardada($vendor, $tipo), 'com duas, ainda não');

        $terceiro = Service::factory()->create([
            'customer_id' => User::factory(),
            'vendor_id' => $vendor->id,
            'services_type_id' => $tipo->id,
            'status' => ServiceStatus::CLOSED,
            'rating_by_customer' => null,
        ]);

        // Sem ninguém chamar o updateRatting() à mão: é o observer.
        $terceiro->update(['rating_by_customer' => 5.0]);

        $this->assertSame(
            5.0,
            $this->notaGuardada($vendor->fresh(), $tipo),
            'a terceira avaliação publica a nota, e é a avaliação que a dispara',
        );
    }
}
