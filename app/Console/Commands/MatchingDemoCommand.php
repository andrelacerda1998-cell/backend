<?php

namespace App\Console\Commands;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\Location;
use App\Services\Matching\MatchingService;
use App\Services\Matching\RankedVendor;
use App\Services\Matching\VendorRankingService;
use App\Settings\MatchingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Passeio pelo fluxo de seleção com dados verosímeis — ver docs/matching.md.
 *
 * Corre DENTRO DE UMA TRANSAÇÃO que é sempre revertida no fim: serve para ver o
 * comportamento, não para deixar dados na base. Nada do que aparece aqui
 * sobrevive ao comando.
 */
class MatchingDemoCommand extends Command
{
    protected $signature = 'matching:demo';

    protected $description = 'Corre o fluxo de seleção de profissional com dados de exemplo (sem gravar nada)';

    private VendorRankingService $ranking;

    private MatchingService $matching;

    private MatchingSettings $settings;

    public function handle(
        VendorRankingService $ranking,
        MatchingService $matching,
        MatchingSettings $settings,
    ): int {
        $this->ranking = $ranking;
        $this->matching = $matching;
        $this->settings = $settings;

        DB::beginTransaction();

        try {
            $this->walkthrough();
        } finally {
            DB::rollBack();
            $this->newLine();
            $this->line('<fg=gray>Transação revertida — não ficou nada na base de dados.</>');
        }

        return self::SUCCESS;
    }

    private function walkthrough(): void
    {
        [$type, $customer, $address] = $this->scenario();

        $this->section('1. Elegíveis, ordenados');
        $ranked = $this->ranking->rank($type, $address, $customer, immediate: false);
        $this->table(
            ['#', 'profissional', 'avaliação', 'nº', 'faixa', 'cliente paga', 'técnico recebe', 'km'],
            $ranked->map(fn (RankedVendor $c) => [
                $c->rank,
                $c->vendor->user->name,
                $c->ratingAverage === null ? '—' : number_format($c->ratingAverage, 2, ',', ''),
                $c->ratingCount ?: '—',
                $c->ratingBand === null ? '—' : chr(65 + $c->ratingBand),
                $this->money($c->quotedAmount),
                $this->money($c->quotedAmountForVendor),
                number_format($c->distance, 1, ',', ''),
            ])->all()
        );
        $this->explain('Ordem: faixa de avaliação, depois preço, depois distância.');

        $this->section('2. Shortlist mostrada ao cliente');
        $short = $this->ranking->shortlist($ranked);
        foreach ($short as $c) {
            $flag = $c->isNewVendorSlot ? ' <fg=yellow>[vaga reservada a quem ainda não tem avaliações]</>' : '';
            $this->line(sprintf(
                '  %d. %-16s %s   %s%s',
                $c->rank,
                $c->vendor->user->name,
                $c->ratingAverage === null ? 'sem avaliações' : number_format($c->ratingAverage, 2, ',', '').' ★',
                $this->money($c->quotedAmount),
                $flag,
            ));
        }

        $this->section('3. Pedido agendado: notificar e receber respostas');
        $service = $this->service($type, $customer, $address);
        $wave = $this->matching->dispatchNextWave($service);
        $this->explain(sprintf('Notificados %d profissionais na 1.ª onda (wave_size = %d).', $wave->count(), $this->settings->wave_size));

        $accepted = 0;
        foreach ($wave as $candidate) {
            $name = $candidate->vendor->user->name;
            $ok = $this->matching->accept($candidate);

            if ($ok) {
                $accepted++;
                $this->line("  <fg=green>✓</> {$name} aceitou  <fg=gray>(".$accepted.'/'.$this->settings->shortlist_size.')</>');

                continue;
            }

            $this->line("  <fg=red>✗</> {$name} tentou aceitar — <fg=yellow>pedido já preenchido</>");
        }
        $this->explain('Ao terceiro sim o pedido fecha. Quem responde depois não aceita uma vaga que já não existe.');

        $this->section('4. O cliente escolhe');
        $selectable = $this->matching->selectableFor($service);
        $this->explain(sprintf('%d profissionais para escolher.', $selectable->count()));

        $chosen = $selectable->first();
        $this->line(sprintf(
            '  Escolhido: <options=bold>%s</> por %s',
            $chosen->vendor->user->name,
            $this->money($chosen->quoted_amount),
        ));
        $this->matching->select($chosen);

        $this->section('5. Estado do serviço depois da escolha');
        $service->refresh();
        $this->table(['campo', 'valor'], [
            ['estado', $service->status->value],
            ['profissional', $service->vendor?->user->name ?? '—'],
            ['cliente paga', $this->money($service->amount)],
            ['técnico recebe', $this->money($service->amount_for_vendor)],
            ['distância', $service->distance.' km'],
        ]);
        $this->explain('Preço congelado no candidato: o que o cliente viu é o que lhe é cobrado.');
        $this->explain('AwaitingPayment — o checkout ainda não existe. É o próximo passo do plano.');

        $this->section('6. O que ficou registado de cada candidato');
        $this->table(
            ['profissional', 'estado', 'posição'],
            $service->candidates()->with('vendor.user')->orderBy('rank')->get()
                ->map(fn ($c) => [
                    $c->vendor->user->name,
                    $this->label($c->status),
                    $c->rank,
                ])->all()
        );
        $this->explain('Os perdedores ficam gravados: sem isso não há como responder a "porque é que não fiquei com este serviço?".');
    }

    /** @return array{0: ServicesType, 1: User, 2: \App\DTO\Services\AddressCoordinatesDTO} */
    private function scenario(): array
    {
        $area = OperationArea::factory()->create(['name' => 'Canalização']);
        $type = ServicesType::factory()->create([
            'name' => 'Reparar uma torneira',
            'operation_area_id' => $area->id,
            'time' => 60,
        ]);

        $customer = User::factory()->create(['name' => 'Cliente Exemplo', 'is_test' => false]);

        // Baixa do Porto.
        $address = new \App\DTO\Services\AddressCoordinatesDTO(41.1478, -8.6110);

        // Casos escolhidos para cada critério aparecer a decidir alguma coisa.
        //
        // A tarifa vai em EUROS por hora: `Vendor::priceRate()` multiplica por
        // 100 ao gravar. Passar cêntimos aqui dá tarifas de 2.000 €/hora.
        $people = [
            //  nome            €/h   notas dadas pelos clientes         km
            ['Rui Ferreira',    20,   [5, 5, 5, 5, 5, 4],                 8.0],
            ['Ana Martins',     24,   [5, 5, 4, 5, 5, 5],                 1.2],
            ['Carlos Dias',     16,   [4, 4, 4, 5, 4, 4],                 2.0],
            ['Sofia Almeida',   15,   [3, 3, 4, 3, 3, 2],                 0.8],
            ['Nuno Pereira',    12,   [],                                 15.0],
        ];

        foreach ($people as [$name, $rate, $ratings, $km]) {
            $this->makeVendor($name, $rate, $ratings, $km, $area, $type);
        }

        return [$type->fresh(), $customer, $address];
    }

    private function makeVendor(string $name, int $ratePerHour, array $ratings, float $km, OperationArea $area, ServicesType $type): Vendor
    {
        $user = User::factory()->create(['name' => $name, 'is_test' => false]);

        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'price_rate' => $ratePerHour,
            'status' => StatusVendor::ONLINE,
        ]);

        $vendor->operationAreas()->attach($area->id);
        $vendor->servicesTypes()->attach($type->id);

        // ~0,009° de latitude por km, o suficiente para as distâncias saírem
        // próximas dos valores pretendidos.
        $lat = 41.1478 + ($km * 0.009);

        // GPS atual — é o que conta num pedido imediato.
        $vendor->currentLocation()->save(new Location([
            'latitude' => $lat,
            'longitude' => -8.6110,
        ]));

        // Morada de agenda — é esta que conta num pedido agendado. Sem ela, o
        // cálculo da distância rebenta e o profissional cai fora do ranking.
        \App\Models\Address::create([
            'user_id' => $user->id,
            'name' => 'Oficina',
            'street_name' => 'Rua de Exemplo',
            'street_number' => '1',
            'postal_code' => '4000-000',
            'city' => 'Porto',
            'municipality' => 'Porto',
            'state' => 'Porto',
            'country' => 'Portugal',
            'latitude' => $lat,
            'longitude' => -8.6110,
            'address_type' => \App\Enums\Services\AddressType::SCHEDULE_ADDRESS,
        ]);

        foreach ($ratings as $rating) {
            Service::factory()->create([
                'vendor_id' => $vendor->id,
                'customer_id' => User::factory()->create()->id,
                'services_type_id' => $type->id,
                'status' => ServiceStatus::CLOSED,
                'rating_by_customer' => $rating,
                // Ao contrário: a nota que ele deu ao cliente. Não pode contar.
                'rating_by_vendor' => 1,
            ]);
        }

        return $vendor->fresh();
    }

    private function service(ServicesType $type, User $customer, $address): Service
    {
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => null,
            'services_type_id' => $type->id,
            'status' => ServiceStatus::MATCHING,
            'amount' => null,
            'amount_for_vendor' => null,
            'address' => ['latitude' => $address->latitude, 'longitude' => $address->longitude, 'city' => 'Porto'],
        ]);

        return $service->fresh();
    }

    private function money(?int $cents): string
    {
        return $cents === null ? '—' : number_format($cents / 100, 2, ',', ' ').' €';
    }

    private function label(CandidateStatus $status): string
    {
        return match ($status) {
            CandidateStatus::SELECTED => '<fg=green>escolhido</>',
            CandidateStatus::LOST => '<fg=gray>perdeu</>',
            CandidateStatus::ACCEPTED => 'aceitou',
            CandidateStatus::DECLINED => 'recusou',
            CandidateStatus::EXPIRED => 'não respondeu',
            CandidateStatus::NOTIFIED => 'notificado',
            CandidateStatus::SHORTLISTED => 'na lista',
        };
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line("<options=bold;fg=cyan>{$title}</>");
    }

    private function explain(string $text): void
    {
        $this->line("<fg=gray>  → {$text}</>");
    }
}
