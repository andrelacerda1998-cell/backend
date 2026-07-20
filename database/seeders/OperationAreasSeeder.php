<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OperationAreasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $operationAreas = [
            [
                'id' => 1,
                'name' => json_encode([
                    'en' => 'Plumbing',
                    'pt-pt' => 'Canalização',
                ], JSON_UNESCAPED_UNICODE)
            ],
            [
                'id' => 2,
                'name' => json_encode([
                    'en' => 'Electrician',
                    'pt-pt' => 'Eletricista',
                ], JSON_UNESCAPED_UNICODE)
            ],
            [
                'id' => 3,
                'name' => json_encode([
                    'en' => 'Cleaning',
                    'pt-pt' => 'Limpeza',
                ], JSON_UNESCAPED_UNICODE)
            ],
            [
                'id' => 4,
                'name' => json_encode([
                    'en' => 'Gardening',
                    'pt-pt' => 'Jardinagem',
                ], JSON_UNESCAPED_UNICODE)
            ],
            [
                'id' => 5,
                'name' => json_encode([
                    'en' => 'General Maintenance',
                    'pt-pt' => 'Manutenção Geral',
                ], JSON_UNESCAPED_UNICODE)
            ],
            [
                'id' => 6,
                'name' => json_encode([
                    'en' => 'Painting',
                    'pt-pt' => 'Pintura',
                ], JSON_UNESCAPED_UNICODE)
            ],
            [
                'id' => 7,
                'name' => json_encode([
                    'en' => 'Reforms',
                    'pt-pt' => 'Reformas',
                ], JSON_UNESCAPED_UNICODE)
            ],
            [
                'id' => 8,
                'name' => json_encode([
                    'en' => 'Kitchen Services',
                    'pt-pt' => 'Serviços de Cozinha',
                ], JSON_UNESCAPED_UNICODE)
            ],
            [
                'id' => 9,
                'name' => json_encode([
                    'en' => 'Home Security',
                    'pt-pt' => 'Segurança Doméstica',
                ], JSON_UNESCAPED_UNICODE)
            ],
            [
                'id' => 10,
                'name' => json_encode([
                    'en' => 'Others',
                    'pt-pt' => 'Outros',
                ], JSON_UNESCAPED_UNICODE)
            ]
        ];

        if (DB::table('operation_areas')->count() === 0) {
            // Convert the name field to JSON before inserting
            $records = collect($operationAreas)->map(function ($record) {
                return [
                    'id' => $record['id'],
                    'name' => $record['name'],
                ];
            })->toArray();

            DB::table('operation_areas')->insert($records);
        }
    }
}
