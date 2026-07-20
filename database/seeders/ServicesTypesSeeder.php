<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ServicesTypesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $servicesTypes = [
            [
                'id' => 1,
                'name' => json_encode([
                    'en' => 'Fix a leaky faucet',
                    'pt-pt' => 'Reparar uma torneira a pingar',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Fix a leaky faucet',
                    'pt-pt' => 'Reparar uma torneira a pingar',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 1,
            ],
            [
                'id' => 2,
                'name' => json_encode([
                    'en' => 'Install a new light fixture',
                    'pt-pt' => 'Instalar uma nova luminária',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Install a new light fixture',
                    'pt-pt' => 'Instalar uma nova luminária',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 1,
            ],
            [
                'id' => 3,
                'name' => json_encode([
                    'en' => 'Unclog a drain',
                    'pt-pt' => 'Desentupir um ralo',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Unclog a drain',
                    'pt-pt' => 'Desentupir um ralo',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 1,
            ],
            [
                'id' => 4,
                'name' => json_encode([
                    'en' => 'Replace a toilet',
                    'pt-pt' => 'Substituir uma sanita',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Replace a toilet',
                    'pt-pt' => 'Substituir uma sanita',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 1,
            ],
            [
                'id' => 5,
                'name' => json_encode([
                    'en' => 'Repair a leaky roof',
                    'pt-pt' => 'Reparar um telhado com vazamento',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Repair a leaky roof',
                    'pt-pt' => 'Reparar um telhado com vazamento',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 2,
            ],
            [
                'id' => 6,
                'name' => json_encode([
                    'en' => 'Install new siding',
                    'pt-pt' => 'Instalar um novo revestimento',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Install new siding',
                    'pt-pt' => 'Instalar um novo revestimento',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 2,
            ],
            [
                'id' => 7,
                'name' => json_encode([
                    'en' => 'Paint the exterior of a house',
                    'pt-pt' => 'Pintar o exterior de uma casa',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Paint the exterior of a house',
                    'pt-pt' => 'Pintar o exterior de uma casa',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 2,
            ],
            [
                'id' => 8,
                'name' => json_encode([
                    'en' => 'Build a deck',
                    'pt-pt' => 'Construir um deck',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Build a deck',
                    'pt-pt' => 'Construir um deck',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 2,
            ],
            [
                'id' => 9,
                'name' => json_encode([
                    'en' => 'Mow the lawn',
                    'pt-pt' => 'Cortar a grama',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Mow the lawn',
                    'pt-pt' => 'Cortar a grama',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 3,
            ],
            [
                'id' => 10,
                'name' => json_encode([
                    'en' => 'Trim the hedges',
                    'pt-pt' => 'Aparar as sebes',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Trim the hedges',
                    'pt-pt' => 'Aparar as sebes',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 3,
            ],
            [
                'id' => 11,
                'name' => json_encode([
                    'en' => 'Plant flowers',
                    'pt-pt' => 'Plantar flores',
                ], JSON_UNESCAPED_UNICODE),
                'description' => json_encode([
                    'en' => 'Plant flowers',
                    'pt-pt' => 'Plantar flores',
                ], JSON_UNESCAPED_UNICODE),
                'operation_area_id' => 3,
            ],
        ];

        if (DB::table('services_types')->count() === 0) {
            $records = collect($servicesTypes)->map(function ($record) {
                return [
                    'id' => $record['id'],
                    'name' => $record['name'],
                    'operation_area_id' => $record['operation_area_id'],
                ];
            })->toArray();

            DB::table('services_types')->insert($records);
        }
    }
}
