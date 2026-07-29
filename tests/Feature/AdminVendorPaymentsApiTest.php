<?php

namespace Tests\Feature;

use App\Mail\Vendor\PaymentSentMail;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Vendor\PaymentSentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminVendorPaymentsApiTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertJsonPath('data.items.0.balance', 150.0)
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
            ->assertJsonPath('data.amount_paid', 150.0);

        $this->assertEquals(0, (int) $vendor->user->wallet->fresh()->balance);
        Mail::assertSent(PaymentSentMail::class);
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
