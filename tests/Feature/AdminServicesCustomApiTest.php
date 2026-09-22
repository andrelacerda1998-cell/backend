<?php

namespace Tests\Feature;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\OperationArea;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pedidos personalizados na API de admin.
 *
 * O backoffice mostrava seis pedidos personalizados INVENTADOS -- nomes,
 * descrições e tudo -- enquanto os reais existiam na base de dados como
 * serviços com `is_custom` e nunca chegavam à API. Estes testes fixam os
 * campos que fazem a diferença entre um ecrã verdadeiro e uma maquete.
 */
class AdminServicesCustomApiTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'services', 'operation_areas', 'service_operation_area', 'media',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    /**
     * INSERT direto: 'status' e 'payment_status' são os únicos NOT NULL sem
     * default em `services` (mesma lição de AdminVendorsApiTest::makeService()).
     */
    private function makeCustomService(string $descricao): int
    {
        return DB::table('services')->insertGetId([
            'status' => ServiceStatus::CLOSED->value,
            'payment_status' => PaymentStatus::PAID->value,
            'is_custom' => true,
            'custom_description' => $descricao,
            'custom_duration_minutes' => 90,
            'is_test' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_it_presents_the_customer_description_of_a_custom_request(): void
    {
        $texto = 'Instalar um suporte de TV numa parede de pladur, com cabos escondidos.';
        $this->makeCustomService($texto);

        $res = $this->withAuth()->getJson('/api/v1/admin/services')->assertOk();
        $item = collect($res->json('data.items'))->firstWhere('is_custom', true);

        $this->assertNotNull($item, 'o pedido personalizado devia vir na listagem');
        $this->assertSame($texto, $item['custom_description']);
        $this->assertSame(90, $item['custom_duration_minutes']);
    }

    /**
     * A contagem vai na LISTAGEM e os URLs só no detalhe: os URLs são assinados
     * e temporários, e gerar dezenas por página para imagens que ninguém abriu
     * seria trabalho deitado fora.
     */
    public function test_the_list_counts_customer_photos_without_generating_urls(): void
    {
        $this->makeCustomService('Sem fotografias.');

        $res = $this->withAuth()->getJson('/api/v1/admin/services')->assertOk();
        $item = collect($res->json('data.items'))->firstWhere('is_custom', true);

        $this->assertSame(0, $item['customer_photos_count']);
        $this->assertArrayNotHasKey('customer_photos', $item);
    }

    public function test_the_detail_carries_the_photo_list(): void
    {
        $id = $this->makeCustomService('Com detalhe.');

        $this->withAuth()
            ->getJson("/api/v1/admin/services/{$id}")
            ->assertOk()
            ->assertJsonPath('data.is_custom', true)
            // Vazio, mas presente: quem consome itera sempre, sem testar o tipo.
            ->assertJsonPath('data.customer_photos', []);
    }

    public function test_it_presents_the_categories_chosen_for_the_request(): void
    {
        $id = $this->makeCustomService('Montar um móvel e ligar uma tomada.');

        $area = new OperationArea();
        $area->setTranslations('name', ['en' => 'Eletricidade', 'pt-pt' => 'Eletricidade']);
        $area->save();
        DB::table('service_operation_area')->insert(['service_id' => $id, 'operation_area_id' => $area->id]);

        $this->withAuth()
            ->getJson("/api/v1/admin/services/{$id}")
            ->assertOk()
            ->assertJsonPath('data.custom_categories', ['Eletricidade']);
    }
}
