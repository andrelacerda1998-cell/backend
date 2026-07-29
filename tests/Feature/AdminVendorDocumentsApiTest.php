<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\Document;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vendor\VendorDocuments;
use App\Notifications\Vendor\Documents\AcceptNotification;
use App\Notifications\Vendor\Documents\DenyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminVendorDocumentsApiTest extends TestCase
{
    use RefreshDatabase;

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    /**
     * Não há factories para Vendor/VendorDocuments/Document (só UserFactory
     * existe) — criados diretamente. Vendor::withoutSyncingToSearch() evita
     * que o VendorObserver tente indexar no Meilisearch durante os testes.
     */
    private function makeVendorDocument(string $status = 'pending'): VendorDocuments
    {
        $user = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Ferreira']);
        $vendor = Vendor::withoutSyncingToSearch(fn () => Vendor::create([
            'user_id' => $user->id,
            'username' => 'ana_'.$user->id,
        ]));
        $document = Document::create(['name' => 'Cartão de Cidadão']);

        return VendorDocuments::create([
            'vendor_id' => $vendor->id,
            'document_id' => $document->id,
            'status' => $status,
        ]);
    }

    public function test_it_lists_pending_documents_by_default(): void
    {
        $pending = $this->makeVendorDocument('pending');
        $this->makeVendorDocument('approved');

        $this->withAuth()
            ->getJson('/api/v1/admin/vendor-documents')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $pending->id)
            ->assertJsonPath('data.items.0.vendor_name', 'Ana Ferreira')
            ->assertJsonPath('data.items.0.document_type', 'Cartão de Cidadão')
            ->assertJsonPath('data.items.0.status', 'pending');
    }

    public function test_it_can_list_documents_by_other_statuses(): void
    {
        $this->makeVendorDocument('pending');
        $approved = $this->makeVendorDocument('approved');

        $this->withAuth()
            ->getJson('/api/v1/admin/vendor-documents?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $approved->id);
    }

    public function test_it_approves_a_pending_document_and_notifies_the_vendor(): void
    {
        Notification::fake();
        $doc = $this->makeVendorDocument('pending');

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-documents/{$doc->id}/approve", ['expiration_date' => '2027-01-01'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $fresh = $doc->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertSame('2027-01-01', $fresh->expiration_date);
        Notification::assertSentTo($doc->vendor->user, AcceptNotification::class);
    }

    public function test_it_declines_a_pending_document_with_a_reason_and_notifies_the_vendor(): void
    {
        Notification::fake();
        $doc = $this->makeVendorDocument('pending');

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-documents/{$doc->id}/decline", ['reason' => 'Documento ilegível.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');

        $fresh = $doc->fresh();
        $this->assertSame('declined', $fresh->status);
        $this->assertSame('Documento ilegível.', $fresh->reason);
        Notification::assertSentTo($doc->vendor->user, DenyNotification::class);
    }

    public function test_it_rejects_a_decline_without_a_reason(): void
    {
        $doc = $this->makeVendorDocument('pending');

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-documents/{$doc->id}/decline", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_it_rejects_reviewing_a_document_that_was_already_reviewed(): void
    {
        $doc = $this->makeVendorDocument('approved');

        $this->withAuth()
            ->putJson("/api/v1/admin/vendor-documents/{$doc->id}/approve", [])
            ->assertStatus(409);
    }
}
