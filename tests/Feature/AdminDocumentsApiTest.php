<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\Document;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class AdminDocumentsApiTest extends TestCase
{
    // DatabaseTruncation por defeito (ver nota extensa em AdminVendorsApiTest).
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['documents'];

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function makeDocument(string $name = 'Cartão de Cidadão', bool $required = true): Document
    {
        $document = new Document();
        $document->required = $required;
        $document->setTranslations('name', ['en' => $name, 'pt-pt' => $name]);
        $document->save();

        return $document;
    }

    public function test_it_lists_documents(): void
    {
        $document = $this->makeDocument('Cartão de Cidadão', true);

        $this->withAuth()
            ->getJson('/api/v1/admin/documents')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $document->id)
            ->assertJsonPath('data.items.0.name', 'Cartão de Cidadão')
            ->assertJsonPath('data.items.0.required', true);
    }

    public function test_it_searches_documents_by_name(): void
    {
        $target = $this->makeDocument('Registo Criminal');
        $this->makeDocument('Certificado profissional');

        $this->withAuth()
            ->getJson('/api/v1/admin/documents?search=criminal')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->id);
    }

    public function test_it_creates_a_document(): void
    {
        $response = $this->withAuth()->postJson('/api/v1/admin/documents', [
            'name' => 'Seguro de responsabilidade civil',
            'description' => 'Obrigatório para categorias de risco',
            'required' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Seguro de responsabilidade civil')
            ->assertJsonPath('data.description', 'Obrigatório para categorias de risco')
            ->assertJsonPath('data.required', false);

        $this->assertDatabaseCount('documents', 1);
    }

    public function test_it_creates_a_document_without_description(): void
    {
        $this->withAuth()
            ->postJson('/api/v1/admin/documents', ['name' => 'Comprovativo de IBAN', 'required' => true])
            ->assertCreated()
            ->assertJsonPath('data.description', null);
    }

    public function test_it_validates_name_required_on_create(): void
    {
        $this->withAuth()
            ->postJson('/api/v1/admin/documents', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_it_updates_a_document(): void
    {
        $document = $this->makeDocument('Certificado profissional', false);

        $this->withAuth()
            ->putJson("/api/v1/admin/documents/{$document->id}", ['required' => true])
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.name', 'Certificado profissional')
            ->assertJsonPath('data.required', true);
    }

    public function test_it_returns_404_for_an_unknown_document(): void
    {
        $this->withAuth()
            ->putJson('/api/v1/admin/documents/999999', ['name' => 'X'])
            ->assertNotFound();
    }
}
