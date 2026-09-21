<?php

namespace App\Providers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Illuminate\Support\ServiceProvider;
use SolutionForest\FilamentTranslateField\Forms\Component\Translate;

class FilamentServiceProvider extends ServiceProvider
{
    /**
     * Os idiomas traduzíveis do backoffice, cada um escrito na sua própria
     * língua — é assim que o seletor de idioma do painel já os apresenta
     * (ver LanguageSwitch em AppServiceProvider), e um nome de língua não se
     * traduz consoante quem está a olhar.
     *
     * Escritos à mão em vez de vir de locale_get_display_name(), que devolve
     * "português (Portugal)": dentro de um rótulo já entre parênteses isso dá
     * "Nome (português (Portugal))".
     */
    private const IDIOMAS_TRADUZIVEIS = [
        'en' => 'English',
        'pt-pt' => 'Português',
    ];

    public function register(): void
    {
        Select::configureUsing(function (Select $component): void {
            $component->native(false);
        });
        DatePicker::configureUsing(function (DatePicker $component): void {
            $component->native(false);
        });
        DateTimePicker::configureUsing(function (DateTimePicker $component): void {
            $component->native(false);
        });
        TimePicker::configureUsing(function (TimePicker $component): void {
            $component->native(false);
        });
        MorphToSelect::configureUsing(function (MorphToSelect $component): void {
            $component->native(false);
            $component->searchable();
            $component->preload();
        });

        // Campos traduzíveis: dizer sempre QUAL o idioma que se está a editar.
        //
        // O componente desenha duas abas discretas por cima dos campos e a
        // primeira já vem escolhida. Quando as duas línguas têm o mesmo texto
        // não fica nada no ecrã a dizer qual delas está à frente: lê-se como um
        // cabeçalho decorativo. Foi assim que a categoria LIMPEZAS ficou com
        // "LIMPEZAS" também em inglês — quem a criou tinha o campo inglês à
        // frente e não havia forma de o saber.
        //
        // O idioma passa para o rótulo do próprio campo ("Nome (English)"),
        // encostado à caixa onde se escreve, que é onde os olhos estão.
        //
        // Aqui e não nos recursos: assim vale para os cinco que existem hoje e
        // para os que vierem, sem ninguém se lembrar de o repetir.
        Translate::configureUsing(function (Translate $component): void {
            // Rede de segurança: sem ->locales() o componente cai no default do
            // pacote, que é uma lista VAZIA — zero abas, e o campo desaparece do
            // formulário sem erro nenhum.
            $component->locales(array_keys(self::IDIOMAS_TRADUZIVEIS));
            $component->localeLabels(self::IDIOMAS_TRADUZIVEIS);
            $component->suffixLocaleLabel();
        });
    }

    public function boot(): void {}
}
