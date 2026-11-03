{{-- Downloads of stored attachments. Names are sender input: escaped output only. --}}
@if ($attachments->isNotEmpty())
    <div class="grid gap-y-2">
        <div class="text-sm font-medium text-gray-950 dark:text-white">Anhänge</div>
        <ul class="grid gap-y-1 text-sm">
            @foreach ($attachments as $attachment)
                <li>
                    <x-filament::link
                        :href="route('intake.attachment', ['attachment' => $attachment->id])"
                        icon="heroicon-o-arrow-down-tray"
                        size="sm"
                    >
                        {{ $attachment->name }}
                    </x-filament::link>
                    <span class="text-gray-500 dark:text-gray-400">({{ \Illuminate\Support\Number::fileSize($attachment->size, precision: 1) }})</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
