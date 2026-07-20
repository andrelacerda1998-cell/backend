<?php

namespace App\Filament\Components\Address;

use App\Filament\Components\Address\Concerns\CanFormatGoogleParams;
use App\Filament\Components\Address\Concerns\HasGooglePlaceApi;
use Closure;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Concerns;
use Filament\Forms\Set;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use SKAgarwal\GoogleApi\PlacesApi;

class GoogleCityAutocomplete extends Component
{
    use CanFormatGoogleParams;
    use Concerns\HasName;
    use HasGooglePlaceApi;

    protected string $view = 'filament-forms::components.fieldset';

    protected bool|Closure $isRequired = false;

    protected array $params = [
        'language' => 'pt',
        'types'    => '(regions)',
    ];

    protected string|Closure $autocompleteFieldColumnSpan = 'full';

    protected int|Closure $autocompleteSearchDebounce = 2000;

    final public function __construct(string $name)
    {
        $this->name($name);

        $this->googlePlaces = new PlacesApi(config('geocoder.key'));
    }

    public static function make(string $name): static
    {
        $static = app(static::class, ['name' => $name]);
        $static->configure();
        $static->columnSpanFull();

        return $static;
    }

    public function types(string $type): static
    {
        $this->params['types'] = $type;

        return $this;
    }

    public function getAutocompleteFieldColumnSpan(): ?string
    {
        return $this->evaluate($this->autocompleteFieldColumnSpan);
    }

    public function autocompleteSearchDebounce(int|Closure $autocompleteSearchDebounce = 2000): static
    {
        $this->autocompleteSearchDebounce = $autocompleteSearchDebounce;

        return $this;
    }

    public function getAutocompleteSearchDebounce(): ?int
    {
        return $this->evaluate($this->autocompleteSearchDebounce);
    }

    /**
     * @return array<Component>
     */
    public function getChildComponents(): array
    {
        return [
            Forms\Components\Grid::make(['default' => 1, 'sm' => 2])
                ->schema([
                    Forms\Components\Select::make('google_city_lookup')
                        ->native(false)
                        ->dehydrated(false)
                        ->allowHtml()
                        ->label('Google look-up')
                        ->live()
                        ->searchDebounce($this->getAutocompleteSearchDebounce())
                        ->searchingMessage('Searching...')
                        ->searchPrompt('Search for a city')
                        ->searchable()
                        ->columnSpan($this->getAutocompleteFieldColumnSpan())
                        ->hint(new HtmlString(Blade::render('<x-filament::loading-indicator class="h5 w-5" wire:loading wire:target="data.google_city_lookup" />')))
                        ->getSearchResultsUsing(function (string $search): array {
                            $result = $this->getPlaceAutocomplete($search);

                            if (! empty($result['predictions'])) {
                                return $result['predictions']
                                    ->mapWithKeys(fn (array $item) => [
                                        $item['place_id'] => $item['description'],
                                    ])
                                    ->toArray();
                            }

                            return [];
                        })
                        ->afterStateUpdated(function (?string $state, Set $set) {
                            if ($state === null) {
                                $set('city', null);
                                $set('district', null);
                                return;
                            }

                            $data   = $this->getPlace($state);
                            $fields = $this->getFormattedApiResults($data);

                            $city     = $fields['locality']['long_name']
                                ?? $fields['administrative_area_level_2']['long_name']
                                ?? '';

                            $district = $fields['administrative_area_level_1']['long_name']
                                ?? '';

                            $set('city', $city);
                            $set('district', $district);
                        }),

                    Forms\Components\TextInput::make('city')
                        ->label('Cidade / Concelho')
                        ->required(),

                    Forms\Components\TextInput::make('district')
                        ->label('Distrito / Região')
                        ->required(),
                ]),
        ];
    }
}
