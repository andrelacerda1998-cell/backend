<?php

namespace Tests\Feature;

use App\Mail\Vendor\PaymentSentMail;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Vendor\PaymentSentNotification;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminVendorPaymentsApiTest extends TestCase
{
    // NÃO RefreshDatabase aqui: bavix/laravel-wallet só grava o saldo real na
    // coluna `wallets.balance` quando a transação da BD chega mesmo ao nível 0
    // (TransactionCommittingListener/TransactionCommittedListener, ver
    // vendor/bavix/laravel-wallet/src/Internal/Listeners) -- o RefreshDatabase
    // embrulha cada teste numa transação que nunca é commitada a sério, por
    // isso deposit()/withdraw() nunca chegam a refletir-se na coluna e uma
    // query SQL direta (o whereHas('user.wallet', ...) do controller, igual
    // ao que o Filament já fazia) via nada. DatabaseTruncation corre em modo
    // autocommit (limpa as tabelas por TRUNCATE entre testes em vez de
    // rollback), o que deixa os listeners do bavix disparar como em produção.
    use DatabaseTruncation;

    // Só as tabelas que este teste realmente escreve -- por omissão o
    // DatabaseTruncation limpa TODAS as tabelas, o que apagaria dados de
    // referência semeados uma única vez no arranque da suite (ex.: genders,
    // documents) e nunca mais repostos, partindo outros testes que corram
    // depois desta classe.
    protected array $tablesToTruncate = ['users', 'vendors', 'wallets', 'transactions', 'transfers', 'schedule_available'];

    protected function setUp(): void
    {
        parent::setUp();

        // Ver AdminVendorDocumentsApiTest -- criar um Vendor dispara uma cadeia de
        // observers (VendorObserver → ScheduleAvailable → ScheduleAvailableObserver)
        // que tenta indexar no Meilisearch.
        config(['scout.driver' => 'null']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function makeVendorWithBalance(int $cents = 0): Vendor
    {
        $user = User::factory()->create(['first_name' => 'Carlos', 'last_name' => 'Mendes']);
        $vendor = Vendor::create([
            'user_id' => $user->id,
            'username' => 'carlos_'.$user->id,
            'iban' => 'PT50000201231234567890154',
        ]);
        if ($cents > 0) {
            $user->wallet->deposit($cents);
        }

        return $vendor;
    }

    public function test_it_lists_only_vendors_with_a_positive_balance(): void
    {
        $withBalance = $this->makeVendorWithBalance(15000); // 150,00€
        $this->makeVendorWithBalance(0);

        $this->withAuth()
            ->getJson('/api/v1/admin/vendor-payments')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $withBalance->id)
            ->assertJsonPath('data.items.0.vendor_name', 'Carlos Mendes')
            // json_encode(150.0) sai "150" (sem parte decimal) -- comparar com o
            // int 150, não 150.0, para bater certo com o assertJsonPath (===).
            ->assertJsonPath('data.items.0.balance', 150)
            ->assertJsonPath('data.items.0.iban', 'PT50 0002 0123 1234 5678 9015 4');
    }

    public function test_it_pays_a_vendor_and_zeroes_the_balance(): void
    {
        Mail::fake();
        Notification::fake();
        $vendor = $this->makeVendorWithBalance(15000);

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertOk()
            ->assertJsonPath('data.amount_paid', 150); // ver nota acima sobre json_encode(float)

        $this->assertEquals(0, (int) $vendor->user->wallet->fresh()->balance);
        // PaymentSentMail implementa ShouldQueue -- Mail::to(...)->send() com um
        // mailable ShouldQueue é automaticamente encaminhado para a queue pelo
        // Laravel, por isso é assertQueued() e não assertSent().
        Mail::assertQueued(PaymentSentMail::class);
        Notification::assertSentTo($vendor->user, PaymentSentNotification::class);
    }

    public function test_it_rejects_paying_a_vendor_with_no_balance(): void
    {
        $vendor = $this->makeVendorWithBalance(0);

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")
            ->assertStatus(409);
    }

    public function test_it_paid_vendor_no_longer_appears_in_the_list(): void
    {
        Mail::fake();
        Notification::fake();
        $vendor = $this->makeVendorWithBalance(15000);

        $this->withAuth()->putJson("/api/v1/admin/vendor-payments/{$vendor->id}/pay")->assertOk();

        $this->withAuth()
            ->getJson('/api/v1/admin/vendor-payments')
            ->assertOk()
            ->assertJsonCount(0, 'data.items');
    }
}
