<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreDocumentRequest;
use App\Http\Requests\Api\Admin\UpdateDocumentRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\Document;
use Illuminate\Http\Request;

/**
 * Documentos — equivalente ao Filament DocumentResource (documentos pedidos
 * aos técnicos no registo/KYC). Fatia "Lista + criar/editar, sem apagar",
 * mesmo padrão de Catálogo/Categorias/Zonas (decisão explícita, 2026-07-29).
 *
 * 'name'/'description' são traduzíveis no Filament (EN + PT-PT); esta fatia
 * usa um único campo por simplicidade, gravado nas duas línguas.
 */
class DocumentController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 100), 200);

        $query = Document::query()->orderBy('id');

        if ($search = $request->string('search')->trim()->value()) {
            // 'name' é uma coluna JSON nativa (não um VARCHAR com JSON lá
            // dentro, como em operation_areas/services_types) -- LIKE direto
            // sobre uma coluna JSON no MySQL compara em binário e ignora a
            // collation da ligação, ficando sensível a maiúsculas/minúsculas.
            // Força minúsculas dos dois lados para um match previsível.
            $query->whereRaw('LOWER(CAST(name AS CHAR)) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }

        $documents = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($documents->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $documents->currentPage(),
                'last_page' => $documents->lastPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
            ],
        ]);
    }

    public function store(StoreDocumentRequest $request): ApiSuccessResponse
    {
        $data = $request->validated();

        $document = new Document();
        $document->required = $data['required'] ?? false;
        $this->applyTranslatable($document, 'name', $data['name']);
        $this->applyTranslatable($document, 'description', $data['description'] ?? null);
        $document->save();

        return ApiSuccessResponse::make($this->present($document->fresh()), statusCode: 201);
    }

    public function update(UpdateDocumentRequest $request, Document $document): ApiSuccessResponse
    {
        $data = $request->validated();

        if (array_key_exists('required', $data)) {
            $document->required = $data['required'];
        }
        if (array_key_exists('name', $data)) {
            $this->applyTranslatable($document, 'name', $data['name']);
        }
        if (array_key_exists('description', $data)) {
            $this->applyTranslatable($document, 'description', $data['description']);
        }
        $document->save();

        return ApiSuccessResponse::make($this->present($document->fresh()));
    }

    /** Grava o mesmo valor nas duas línguas geridas pelo Filament (en, pt-pt). */
    private function applyTranslatable(Document $document, string $attribute, ?string $value): void
    {
        if ($value === null) {
            $document->setTranslations($attribute, []);

            return;
        }
        $document->setTranslations($attribute, ['en' => $value, 'pt-pt' => $value]);
    }

    private function present(Document $document): array
    {
        return [
            'id' => $document->id,
            'name' => $document->name,
            'description' => $document->description ?: null,
            'required' => (bool) $document->required,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
