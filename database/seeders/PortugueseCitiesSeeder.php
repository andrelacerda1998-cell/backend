<?php

namespace Database\Seeders;

use App\Models\GeneralSettings\City;
use Illuminate\Database\Seeder;

/**
 * Cidades (cidades com estatuto de cidade) de Portugal, por distrito/regiao.
 * Fonte a rever antes de producao -- e uma lista curada, nao gerada de um
 * registo oficial. Idempotente por (name, district).
 *
 * As SUGERIDAS aparecem em destaque no onboarding sem pesquisa; as restantes
 * so surgem pela pesquisa/autocomplete.
 */
class PortugueseCitiesSeeder extends Seeder
{
    /** As 25 que aparecem em destaque, na ordem do produto. */
    private const SUGGESTED = [
        'Lisboa', 'Amadora', 'Odivelas', 'Loures', 'Vila Franca de Xira',
        'Setúbal', 'Almada', 'Seixal', 'Barreiro', 'Montijo',
        'Porto', 'Vila Nova de Gaia', 'Matosinhos', 'Maia', 'Gondomar',
        'Braga', 'Guimarães', 'Vila Nova de Famalicão',
        'Aveiro', 'Coimbra', 'Leiria', 'Viseu', 'Santarém', 'Faro', 'Portimão',
    ];

    /** district => [cidades] */
    private const CITIES = [
        'Aveiro' => [
            'Águeda', 'Aveiro', 'Espinho', 'Ílhavo', 'Oliveira de Azeméis',
            'Oliveira do Bairro', 'Ovar', 'Santa Maria da Feira',
            'São João da Madeira', 'Vale de Cambra',
        ],
        'Beja' => ['Beja', 'Moura', 'Serpa'],
        'Braga' => [
            'Barcelos', 'Braga', 'Esposende', 'Fafe', 'Guimarães',
            'Vila Nova de Famalicão', 'Vizela',
        ],
        'Bragança' => ['Bragança', 'Mirandela'],
        'Castelo Branco' => ['Castelo Branco', 'Covilhã', 'Fundão'],
        'Coimbra' => ['Coimbra', 'Figueira da Foz', 'Lousã', 'Oliveira do Hospital'],
        'Évora' => ['Évora'],
        'Faro' => [
            'Albufeira', 'Faro', 'Lagoa', 'Lagos', 'Loulé', 'Olhão',
            'Portimão', 'Quarteira', 'Silves', 'Tavira', 'Vila Real de Santo António',
        ],
        'Guarda' => ['Guarda', 'Seia'],
        'Leiria' => [
            'Alcobaça', 'Batalha', 'Caldas da Rainha', 'Leiria',
            'Marinha Grande', 'Peniche', 'Pombal', 'Porto de Mós',
        ],
        'Lisboa' => [
            'Agualva-Cacém', 'Alverca do Ribatejo', 'Amadora', 'Cascais',
            'Lisboa', 'Loures', 'Mafra', 'Odivelas', 'Oeiras',
            'Póvoa de Santa Iria', 'Queluz', 'Rio de Mouro', 'Sintra',
            'Torres Vedras', 'Vila Franca de Xira',
        ],
        'Portalegre' => ['Elvas', 'Ponte de Sor', 'Portalegre'],
        'Porto' => [
            'Amarante', 'Ermesinde', 'Felgueiras', 'Gondomar', 'Lousada',
            'Maia', 'Matosinhos', 'Paços de Ferreira', 'Paredes', 'Penafiel',
            'Porto', 'Póvoa de Varzim', 'Rio Tinto', 'Santo Tirso',
            'São Mamede de Infesta', 'Trofa', 'Valongo', 'Vila do Conde',
            'Vila Nova de Gaia',
        ],
        'Santarém' => [
            'Abrantes', 'Cartaxo', 'Entroncamento', 'Fátima', 'Ourém',
            'Rio Maior', 'Santarém', 'Tomar', 'Torres Novas',
        ],
        'Setúbal' => [
            'Alcácer do Sal', 'Almada', 'Amora', 'Barreiro', 'Costa da Caparica',
            'Moita', 'Montijo', 'Palmela', 'Santiago do Cacém', 'Seixal',
            'Sesimbra', 'Setúbal', 'Sines',
        ],
        'Viana do Castelo' => [
            'Monção', 'Ponte de Lima', 'Valença', 'Viana do Castelo',
        ],
        'Vila Real' => ['Chaves', 'Peso da Régua', 'Vila Real'],
        'Viseu' => [
            'Lamego', 'Mangualde', 'Nelas', 'São Pedro do Sul', 'Tondela', 'Viseu',
        ],
        'Açores' => [
            'Angra do Heroísmo', 'Horta', 'Lagoa (Açores)', 'Madalena',
            'Ponta Delgada', 'Praia da Vitória', 'Ribeira Grande',
            'Santa Cruz da Graciosa', 'São Roque do Pico', 'Velas', 'Vila do Porto',
        ],
        'Madeira' => [
            'Câmara de Lobos', 'Caniço', 'Funchal', 'Machico', 'Santa Cruz',
        ],
    ];

    public function run(): void
    {
        $suggested = array_flip(self::SUGGESTED);

        foreach (self::CITIES as $district => $cities) {
            foreach ($cities as $name) {
                City::updateOrCreate(
                    ['name' => $name, 'district' => $district],
                    ['suggested' => isset($suggested[$name])],
                );
            }
        }
    }
}
