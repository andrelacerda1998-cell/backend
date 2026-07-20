<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            <img src="{{ asset('images/Logo.png') }}" alt="{{ config('app.name') }}" style="width: 150px; margin-top: 20px;" />
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {!! $slot !!}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {!! $subcopy !!}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            <span style="color: #FABB5A; font-size: 16px;">{{ config('app.name') }}</span><br>
            © {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
