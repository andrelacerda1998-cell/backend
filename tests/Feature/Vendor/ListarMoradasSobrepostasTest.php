<?php

namespace Tests\Feature\Vendor;

use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\AuditableObserver;
use Tests\TestCase;

/**
 * O comando que procura moradas reescritas por cima de outra.
 *
 * O que se prova aqui e que ele APANHA o caso — um comando que imprime
 * "nenhuma encontrada" corre sempre, e num sitio onde nao houve sobreposicoes
 * nao ha maneira de distinguir "nao ha" de "nao procura".
 *
 * A sobreposicao e reproduzida como a producao a fazia: `updateOrCreate([])`
 * sobre a morada existente, que e o que os controladores faziam antes de
 * backend#63.
 */
class ListarMoradasSobrepostasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A auditoria vem desligada em consola (`audit.console` = false) e os
     * testes correm em consola — sem isto a tabela `audits` fica vazia e o
     * comando nao teria nada para encontrar. Em producao as escritas vem de
     * pedidos HTTP, que sao auditados.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['audit.console' => true]);

        // Ligar a config nao chega: o observer da auditoria e registado no
        // BOOT do modelo, e so se ela ja estiver ligada nesse instante. Quando
        // este setUp corre, o `Address` ja bootou sem ele.
        //
        // Registado a mao, e uma vez por teste: cada teste arranca com um
        // dispatcher novo, por isso nao ha registo a dobrar. (Reiniciar o
        // modelo com `clearBootedModels` nao serve — e global, e faz rebootar
        // todos os outros, com os observers deles a duplicar.)
        Address::observe(AuditableObserver::class);
    }

    private function morada(Vendor $vendor, AddressType $tipo, string $rua = 'Rua Antiga'): Address
    {
        return Address::create([
            'user_id' => $vendor->user_id,
            'address_type' => $tipo,
            'address_name' => 'Morada',
            'name' => $rua.' 1, Almada',
            'street_name' => $rua,
            'street_number' => '1',
            'postal_code' => '2800-000',
            'city' => 'Almada',
            'municipality' => 'Almada',
            'state' => 'Setubal',
            'country' => 'Portugal',
            'latitude' => 38.66,
            'longitude' => -9.07,
        ]);
    }

    /** Exatamente o que os controladores faziam antes da correccao. */
    private function sobrepor(Vendor $vendor, AddressType $novoTipo): void
    {
        $vendor->addresses()->updateOrCreate([], [
            'user_id' => $vendor->user_id,
            'address_type' => $novoTipo,
            'address_name' => 'Morada',
            'name' => 'Rua Nova 9, Lisboa',
            'street_name' => 'Rua Nova',
            'street_number' => '9',
            'postal_code' => '1000-001',
            'city' => 'Lisboa',
            'municipality' => 'Lisboa',
            'state' => 'Lisboa',
            'country' => 'Portugal',
            'latitude' => 38.7,
            'longitude' => -9.14,
        ]);
    }

    public function test_apanha_uma_morada_de_agendamento_reescrita_pela_fiscal(): void
    {
        $vendor = Vendor::factory()->create();
        $this->morada($vendor, AddressType::SCHEDULE_ADDRESS);

        $this->sobrepor($vendor, AddressType::FISCAL_ADDRESS);

        $this->artisan('vendors:overwritten-addresses')
            ->expectsOutputToContain('schedule_address -> fiscal_address')
            ->expectsOutputToContain('Rua Antiga 1, Almada')
            ->expectsOutputToContain('RECUPERAVEL')
            ->assertSuccessful();
    }

    /**
     * Quem voltou a preencher o tipo perdido nao precisa de recuperacao — e
     * listar toda a gente como "recuperavel" tornaria a lista inutil.
     */
    public function test_nao_marca_como_recuperavel_quem_ja_voltou_a_preencher(): void
    {
        $vendor = Vendor::factory()->create();
        $this->morada($vendor, AddressType::SCHEDULE_ADDRESS);
        $this->sobrepor($vendor, AddressType::FISCAL_ADDRESS);

        // O tecnico voltou a por a de agendamento, noutra linha.
        $this->morada($vendor, AddressType::SCHEDULE_ADDRESS, 'Rua Reposta');

        $this->artisan('vendors:overwritten-addresses')
            ->expectsOutputToContain('hoje ja tem esse tipo preenchido')
            ->doesntExpectOutputToContain('RECUPERAVEL')
            ->assertSuccessful();
    }

    /** Uma edicao normal da morada nao e uma sobreposicao. */
    public function test_editar_a_mesma_morada_nao_conta(): void
    {
        $vendor = Vendor::factory()->create();
        $morada = $this->morada($vendor, AddressType::FISCAL_ADDRESS);

        $morada->update(['street_name' => 'Rua Corrigida', 'name' => 'Rua Corrigida 1, Almada']);

        $this->artisan('vendors:overwritten-addresses')
            ->expectsOutputToContain('Nenhuma morada sobreposta')
            ->assertSuccessful();
    }

    /** E nao escreve nada: e uma listagem. */
    public function test_nao_altera_nada(): void
    {
        $vendor = Vendor::factory()->create();
        $this->morada($vendor, AddressType::SCHEDULE_ADDRESS);
        $this->sobrepor($vendor, AddressType::FISCAL_ADDRESS);

        $antes = Address::withTrashed()->get()->map->only(['id', 'address_type', 'street_name'])->toArray();

        $this->artisan('vendors:overwritten-addresses')->assertSuccessful();

        $depois = Address::withTrashed()->get()->map->only(['id', 'address_type', 'street_name'])->toArray();

        $this->assertSame($antes, $depois);
    }
}
