<section>
    <table class="w-full">
        <thead>
            <tr>
                <th class="w-1/6">{{__('backoffice/vendor.infolist.history_table.document')}}</th>
                <th class="w-1/6">{{__('backoffice/vendor.infolist.history_table.status')}}</th>
                <th class="w-1/6">{{__('backoffice/vendor.infolist.history_table.reason')}}</th>
                <th class="w-1/6">{{__('backoffice/vendor.infolist.history_table.sent')}}</th>
                <th class="w-1/6">{{__('backoffice/vendor.infolist.history_table.updated')}}</th>
                <th class="w-1/6">{{__('backoffice/vendor.infolist.history_table.file')}}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($documents as $document)
                <tr>
                    <td class="text-center">{{ $document->type->name }}</td>
                    <td class="text-center">{{ ucwords($document->status) }}</td>
                    <td class="text-center line-clamp-1" title="{{ $document->reason }}">{{ $document->reason ?? '-' }}</td>
                    <td class="text-center">{{ $document->created_at ? \Carbon\Carbon::parse($document->created_at)->format('d/m/Y') : '-' }}</td>
                    <td class="text-center">{{ $document->updated_at ? \Carbon\Carbon::parse($document->updated_at)->format('d/m/Y') : '-' }}</td>
                    <td class="text-center"><a href="{{ $document->getFirstMediaUrl($document->type) ?? '-' }}" target="_blank" download class="hover:underline hover:underline-offset-4">Download</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</section>
