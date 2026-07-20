<tr>
    <td class="header" style="padding: 20px; text-align: center;">
        <a href="{{ $url }}" style="display: inline-block;">
            @if (trim($slot) === 'Laravel')
                <img src="{{ asset('images/Logo.png') }}" alt="Logo" style="width: 150px; margin-top: 20px;">
            @else
                {{ $slot }}
            @endif
        </a>
    </td>
</tr>
