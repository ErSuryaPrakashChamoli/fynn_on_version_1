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

    <x-filament::section icon="heroicon-o-user-group" :heading="'Who has access now ('.$usersWithAccess->count().')'" description="Updated when you save.">
        @if ($usersWithAccess->isEmpty())
            <div class="fynn-empty fynn-empty--compact">
                <span class="fynn-empty-icon"><x-filament::icon icon="heroicon-o-user-minus" /></span>
                <span>Nobody yet.</span>
            </div>
        @else
            <ul class="fynn-list text-sm">
                @foreach ($usersWithAccess as $row)
                    <li class="fynn-list-row flex-wrap">
                        <div class="fynn-list-main">
                            <span class="fynn-avatar fynn-avatar--sm" aria-hidden="true">{{ \Illuminate\Support\Str::of($row['name'])->explode(' ')->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') }}</span>
                            <div class="min-w-0">
                                <span class="fynn-list-title">{{ $row['name'] }}</span>
                                <span class="fynn-list-meta">· {{ $row['emp_id'] ?? $row['email'] }}</span>
                                @if ($row['extras'] !== [])
                                    <span class="fynn-list-meta">· also {{ implode(', ', $row['extras']) }}</span>
                                @endif
                            </div>
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
