<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O profissional avalia o cliente, com estrelas e com palavras.
 *
 * Havia `rating_comment_by_customer` mas não o par dele: o técnico só podia dar
 * estrelas. "3 estrelas" não distingue um cliente que não estava em casa de um
 * que discutiu o preço à porta -- e é essa diferença que serve a quem tem de
 * decidir se faz alguma coisa com a nota.
 */
class TecnicoAvaliaOClienteTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types', 'operation_areas',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
    }

    private function servicoDe(Vendor $vendor): Service
    {
        $cliente = User::factory()->create();

        $s = new Service();
        $s->forceFill([
            'customer_id' => $cliente->id,
            'vendor_id' => $vendor->id,
            'quantity' => 1,
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'distance' => 2,
            'amount' => 4500,
            'credit_used' => 0,
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
        ])->save();

        return $s->fresh();
    }

    private function tecnico(): Vendor
    {
        $user = User::factory()->create();

        return Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id]);
    }

    private function avaliar(Vendor $v, Service $s, array $dados)
    {
        return $this->actingAs($v->user, 'api')
            ->putJson("/api/v1/vendor/services/{$s->id}/rate", $dados);
    }

    public function test_grava_a_nota_e_o_comentario(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoDe($v);

        $this->avaliar($v, $s, ['rate' => 4, 'comment' => 'Não estava em casa à hora combinada.'])
            ->assertOk();

        $s->refresh();
        $this->assertSame(4, (int) $s->rating_by_vendor);
        $this->assertSame('Não estava em casa à hora combinada.', $s->rating_comment_by_vendor);
    }

    /** Comentar é opcional dentro de avaliar, que já é opcional. */
    public function test_a_nota_sozinha_chega(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoDe($v);

        $this->avaliar($v, $s, ['rate' => 5])->assertOk();

        $s->refresh();
        $this->assertSame(5, (int) $s->rating_by_vendor);
        $this->assertNull($s->rating_comment_by_vendor);
    }

    /** Um comentário sem estrela não é uma avaliação. */
    public function test_recusa_comentario_sem_nota(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoDe($v);

        $this->avaliar($v, $s, ['comment' => 'Correu mal.'])->assertStatus(422);

        $this->assertNull($s->fresh()->rating_by_vendor);
    }

    /** O teto existe porque isto vai ser lido por pessoas. */
    public function test_recusa_um_comentario_enorme(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoDe($v);

        $this->avaliar($v, $s, ['rate' => 3, 'comment' => str_repeat('a', 1001)])
            ->assertStatus(422);
    }

    /** Avaliar duas vezes não sobrescreve a primeira. */
    public function test_nao_deixa_avaliar_duas_vezes(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoDe($v);

        $this->avaliar($v, $s, ['rate' => 5, 'comment' => 'Primeira'])->assertOk();
        $this->avaliar($v, $s, ['rate' => 1, 'comment' => 'Segunda'])->assertStatus(409);

        $s->refresh();
        $this->assertSame(5, (int) $s->rating_by_vendor);
        $this->assertSame('Primeira', $s->rating_comment_by_vendor);
    }

    /** O serviço de outro profissional não é dele para avaliar. */
    public function test_nao_avalia_o_servico_de_outro(): void
    {
        $meu = $this->tecnico();
        $outro = $this->tecnico();
        $s = $this->servicoDe($outro);

        $this->avaliar($meu, $s, ['rate' => 1])->assertStatus(404);

        $this->assertNull($s->fresh()->rating_by_vendor);
    }
}
