<?php

namespace Tests\Feature\Backoffice;

use App\Filament\Resources\GeneralSettings\OperationAreaResource\Pages\ListOperationAreas;
use App\Models\GeneralSettings\OperationArea;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use SolutionForest\FilamentTranslateField\Forms\Component\Translate;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Os campos traduzíveis do backoffice têm de dizer QUAL o idioma que se está a
 * editar.
 *
 * O componente de tradução desenha duas abas discretas por cima dos campos e a
 * primeira já vem escolhida. Quando as duas línguas têm o mesmo texto, nada no
 * ecrã diz qual delas está à frente — foi assim que a categoria LIMPEZAS ficou
 * com "LIMPEZAS" também em inglês, sem ninguém reparar durante meses.
 *
 * Estes testes prendem o comportamento: se alguém desligar a configuração
 * global em FilamentServiceProvider, a armadilha volta em silêncio e só se dá
 * por ela quando outra tradução se perder.
 */
class IdiomaExplicitoNosCamposTraduziveisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
        Role::findOrCreate('admin');

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    public function test_o_rotulo_do_campo_diz_o_idioma_nos_dois_separadores(): void
    {
        $area = OperationArea::factory()->create([
            'name' => ['en' => 'CLEANING', 'pt-pt' => 'LIMPEZAS'],
        ]);

        Livewire::test(ListOperationAreas::class)
            ->mountTableAction('edit', $area->getKey())
            ->assertSuccessful()
            // O que resolve o problema: o idioma encostado à caixa onde se escreve.
            ->assertSee('Nome (English)')
            ->assertSee('Nome (Português)');
    }

    public function test_as_abas_usam_o_nome_de_cada_lingua_e_nao_o_do_icu(): void
    {
        // locale_get_display_name('pt-pt', 'pt-pt') devolve "português (Portugal)",
        // que dentro de um rótulo já entre parênteses daria "Nome (português
        // (Portugal))". Daí os nomes à mão.
        $componente = Translate::make([TextInput::make('name')]);

        $this->assertSame('English', $componente->getLocaleLabel('en'));
        $this->assertSame('Português', $componente->getLocaleLabel('pt-pt'));
    }

    public function test_um_componente_sem_locales_explicitos_continua_a_ter_as_duas_abas(): void
    {
        // Sem esta rede, getLocales() cai no default do pacote — uma lista VAZIA.
        // Zero abas, e o campo desaparece do formulário sem erro nenhum.
        $componente = Translate::make([TextInput::make('name')]);

        $this->assertSame(['en', 'pt-pt'], $componente->getLocales());
    }
}
