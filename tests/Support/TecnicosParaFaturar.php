<?php

namespace Tests\Support;

use App\Enums\Services\AddressType;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Address;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/**
 * Técnicos, serviços fechados e créditos na carteira, para os testes da
 * faturação em atraso (FaturasEmAtraso) e do "só se paga o que está faturado".
 */
trait TecnicosParaFaturar
{
    /** As tabelas que estes testes sujam (DatabaseTruncation). */
    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'schedule_available',
        'services', 'services_types', 'operation_areas', 'addresses', 'documents',
        'transactions', 'transfers', 'media', 'notifications',
    ];

    protected function prepararFaturacao(): void
    {
        // Criar um Vendor dispara observers que tentam indexar no Meilisearch.
        config(['scout.driver' => 'null']);

        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake(); // fechar um serviço manda o CreateInvoiceJob para a fila
        Storage::fake(config('media-library.disk_name'));

        // O DatabaseTruncation limpa ANTES de cada teste: o último da pasta
        // deixava os serviços para quem corre a seguir, e há testes com
        // RefreshDatabase que contam serviços (PedidoPersonalizadoTest). Limpar
        // também à saída.
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());

        // Depois de 30/09/2026, quando estas faturas passaram a poder atrasar-se
        // (Service::FATURAS_EM_ATRASO_DESDE), seja qual for o relógio da máquina.
        $this->travelTo('2026-10-13 12:00:00');
    }

    protected function comAuthDeAdmin(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    /**
     * Um técnico com tudo: IBAN, morada fiscal, AT validada e conta de
     * faturação. Os testes tiram-lhe o que precisam de tirar.
     */
    protected function tecnico(array $atributos = []): Vendor
    {
        $user = User::factory()->create(['first_name' => 'Rui', 'last_name' => 'Tavares']);

        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'iban' => 'PT50000201231234567890154',
            'at_user' => '123456789/1',
            'at_valid' => true,
            'invoice_workspace' => 'oficina-teste',
            'auth_token' => 'chave-de-teste',
            ...$atributos,
        ]);

        Address::forceCreate([
            'user_id' => $vendor->user_id,
            'address_type' => AddressType::FISCAL_ADDRESS,
            'name' => 'Rua de Teste 1, Porto',
            'address_name' => 'Escritorio',
            'street_name' => 'Rua de Teste',
            'street_number' => '1',
            'additional_info' => '',
            'postal_code' => '4000-001',
            'city' => 'Porto',
            'municipality' => 'Porto',
            'state' => 'Porto',
            'country' => 'Portugal',
            'latitude' => 41.1579,
            'longitude' => -8.6291,
            'main_address' => true,
        ]);

        return $vendor->fresh();
    }

    /** Sem AT: nunca deu o subutilizador. */
    protected function tecnicoSemAt(array $atributos = []): Vendor
    {
        return $this->tecnico(['at_user' => null, ...$atributos]);
    }

    /**
     * Um serviço fechado, com o dinheiro dele creditado na carteira do técnico
     * como o `settle()` credita (com o id do serviço no `meta`).
     */
    protected function servicoFechado(Vendor $vendor, int $paraOTecnico = 7500, array $atributos = []): Service
    {
        $servico = Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::CLOSED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => (int) round($paraOTecnico / 0.75),
            'amount_for_vendor' => $paraOTecnico,
            ...$atributos,
        ]);

        $vendor->user->wallet->deposit($paraOTecnico, $servico->getMetaProduct());

        return $servico;
    }

    /** A fatura do serviço saiu: o PDF está guardado. */
    protected function comFatura(Service $servico): Service
    {
        $servico->addMediaFromString('%PDF-1.4 fatura')
            ->usingFileName('fatura.pdf')
            ->toMediaCollection(Service::COLECAO_DA_FATURA);

        return $servico;
    }

    /** Um depósito sem serviço por trás: conta como crédito promocional. */
    protected function creditoPromocional(Vendor $vendor, int $centimos): void
    {
        $vendor->user->wallet->deposit($centimos);
    }

    /** Um pagamento feito ANTES desta regra: pagava o saldo todo, sem a marca. */
    protected function pagamentoDeAntesDaRegra(Vendor $vendor, int $centimos): void
    {
        $vendor->user->wallet->withdraw($centimos, [
            'type' => 'Debit',
            'description' => 'Transfer to account',
            'admin_description' => 'Transfer to account',
            'class' => Vendor::class,
            'id' => $vendor->getKey(),
        ]);
    }
}
