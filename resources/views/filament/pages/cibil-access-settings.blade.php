<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Save access
            </x-filament::button>
        </div>
    </form>

    @php($usersWithAccess = $this->usersWithAccess())

    <x-filament::section :heading="'Who has access now ('.$usersWithAccess->count().')'" description="Updated when you save.">
        @if ($usersWithAccess->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">Nobody yet.</p>
        @else
            <ul class="divide-y divide-gray-200 text-sm dark:divide-white/10">
                @foreach ($usersWithAccess as $row)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div>
                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</span>
                            <span class="text-gray-500 dark:text-gray-400">· {{ $row['emp_id'] ?? $row['email'] }}</span>
                        </div>
                        <x-filament::badge :color="$row['via'] === 'Admin' ? 'primary' : ($row['via'] === 'Individually' ? 'info' : 'gray')">
                            {{ $row['via'] }}
                        </x-filament::badge>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
