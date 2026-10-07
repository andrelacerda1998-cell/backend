<?php

namespace App\Console\Commands;

use App\Models\GeneralSettings\City;
use Illuminate\Console\Command;
use Spatie\Geocoder\Facades\Geocoder;

/**
 * Preenche o centro (latitude/longitude) das cidades do catálogo.
 *
 * É o que deixa o matching saber se uma morada está "em Lisboa": pelo centro
 * e pelo raio da cidade, e não pelo nome — o catálogo mistura tamanhos e a
 * morada do serviço nem sempre usa o mesmo nome.
 *
 * Corre-se uma vez depois do deploy (precisa da chave da Google, que só existe
 * em produção) e sempre que se acrescentam cidades. Idempotente: só toca nas
 * que ainda não têm coordenadas, a não ser com --force.
 */
class GeocodeCitiesCommand extends Command
{
    protected $signature = 'cities:geocode
                            {--force : Volta a geocodificar também as que já têm coordenadas}
                            {--dry-run : Mostra o que faria, sem gravar}';

    protected $description = 'Preenche latitude/longitude das cidades a partir do nome e do distrito.';

    public function handle(): int
    {
        $cidades = City::query()
            ->when(! $this->option('force'), fn ($q) => $q->whereNull('latitude'))
            ->orderBy('name')
            ->get();

        $ok = 0;
        $falhas = [];

        foreach ($cidades as $cidade) {
            try {
                $r = Geocoder::setLanguage('pt')->getCoordinatesForAddress("{$cidade->name}, {$cidade->district}, Portugal");
            } catch (\Throwable $e) {
                $r = null;
            }

            $lat = $r['lat'] ?? null;
            $lng = $r['lng'] ?? null;

            // O Geocoder devolve 0,0 quando não encontra: isso é o Golfo da
            // Guiné, não uma cidade portuguesa.
            if (! $lat || ! $lng || (float) $lat === 0.0) {
                $falhas[] = "{$cidade->name} ({$cidade->district})";
                continue;
            }

            if (! $this->option('dry-run')) {
                $cidade->forceFill(['latitude' => $lat, 'longitude' => $lng])->save();
            }

            $ok++;
        }

        $this->info("Geocodificadas: {$ok}");

        if ($falhas) {
            $this->warn('Sem resultado (ficam a contar pelo nome): '.implode(', ', $falhas));
        }

        return self::SUCCESS;
    }
}
