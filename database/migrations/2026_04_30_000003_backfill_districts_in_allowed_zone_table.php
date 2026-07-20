<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $cityDistrictMap = [
        // Grande Lisboa
        'Lisboa'                       => 'Grande Lisboa',
        'Cascais'                      => 'Grande Lisboa',
        'Oeiras'                       => 'Grande Lisboa',
        'Sintra'                       => 'Grande Lisboa',
        'Almada'                       => 'Grande Lisboa',
        'Loures'                       => 'Grande Lisboa',
        'Amadora'                      => 'Grande Lisboa',
        'Odivelas'                     => 'Grande Lisboa',
        'Seixal'                       => 'Grande Lisboa',
        'Setúbal'                      => 'Grande Lisboa',
        'Barreiro'                     => 'Grande Lisboa',
        'Vila Franca de Xira'          => 'Grande Lisboa',
        'Mafra'                        => 'Grande Lisboa',

        // Porto e Norte
        'Porto'                        => 'Porto e Norte',
        'Matosinhos'                   => 'Porto e Norte',
        'Gaia'                         => 'Porto e Norte',
        'Vila Nova de Gaia'            => 'Porto e Norte',
        'Braga'                        => 'Porto e Norte',
        'Guimarães'                    => 'Porto e Norte',
        'Gondomar'                     => 'Porto e Norte',
        'Maia'                         => 'Porto e Norte',
        'Valongo'                      => 'Porto e Norte',
        'Barcelos'                     => 'Porto e Norte',
        'Vila do Conde'                => 'Porto e Norte',
        'Póvoa de Varzim'              => 'Porto e Norte',
        'Viana do Castelo'             => 'Porto e Norte',

        // Algarve
        'Faro'                         => 'Algarve',
        'Albufeira'                    => 'Algarve',
        'Portimão'                     => 'Algarve',
        'Loulé'                        => 'Algarve',
        'Lagos'                        => 'Algarve',
        'Silves'                       => 'Algarve',
        'Tavira'                       => 'Algarve',
        'Olhão'                        => 'Algarve',
        'Vila Real de Santo António'   => 'Algarve',

        // Centro
        'Coimbra'                      => 'Centro',
        'Aveiro'                       => 'Centro',
        'Viseu'                        => 'Centro',
        'Leiria'                       => 'Centro',
        'Caldas da Rainha'             => 'Centro',
        'Figueira da Foz'              => 'Centro',
        'Covilhã'                      => 'Centro',
        'Guarda'                       => 'Centro',

        // Alentejo
        'Évora'                        => 'Alentejo',
        'Beja'                         => 'Alentejo',
        'Portalegre'                   => 'Alentejo',
        'Santarém'                     => 'Alentejo',
        'Sines'                        => 'Alentejo',
    ];

    public function up(): void
    {
        foreach ($this->cityDistrictMap as $city => $district) {
            DB::table('allowed_zone')
                ->whereRaw('LOWER(city) = LOWER(?)', [$city])
                ->whereNull('district')
                ->update(['district' => $district]);
        }
    }

    public function down(): void
    {
        DB::table('allowed_zone')->update(['district' => null]);
    }
};
