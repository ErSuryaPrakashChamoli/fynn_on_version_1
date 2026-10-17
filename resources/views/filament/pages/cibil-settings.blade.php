<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" icon="heroicon-o-check">
                Save settings
            </x-filament::button>
        </div>
    </form>

    @php($status = $this->connectionStatus())

    <x-filament::section heading="Provider connection" description="Read from the server's environment (config/cibil.php). Tokens are never shown.">
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-gray-500 dark:text-gray-400">Bureau driver</dt>
                <dd class="flex items-center gap-2 text-gray-950 dark:text-white">
                    <span class="font-mono">{{ $status['driver'] }}</span>
                    <x-filament::badge :color="$status['live'] ? 'success' : 'gray'">
                        {{ $status['live'] ? 'Live Surepass' : 'Not live' }}
                    </x-filament::badge>
                </dd>
            </div>

            <div>
                <dt class="text-gray-500 dark:text-gray-400">PDF storage</dt>
                <dd class="text-gray-950 dark:text-white">Private disk "{{ $status['pdf_disk'] }}", up to {{ $status['pdf_max_mb'] }} MB</dd>
            </div>
        </dl>

        @foreach ($status['bureaus'] as $bureau)
            <div class="mt-6 border-t border-gray-200 pt-4 dark:border-white/10">
                <h3 class="mb-3 text-sm font-semibold text-gray-950 dark:text-white">{{ $bureau['label'] }}</h3>

                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Surepass token</dt>
                        <dd>
                            <x-filament::badge :color="$bureau['token_configured'] ? 'success' : 'danger'">
                                {{ $bureau['token_configured'] ? 'Configured' : 'Not configured' }}
                            </x-filament::badge>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Base URL</dt>
                        <dd class="flex items-center gap-2 text-gray-950 dark:text-white">
                            <span class="font-mono break-all">{{ $bureau['base_url'] ?? '—' }}</span>
                            @unless ($bureau['base_url_valid'])
                                <x-filament::badge color="danger">Must be https</x-filament::badge>
                            @endunless
                        </dd>
                    </div>

                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Timeout</dt>
                        <dd class="text-gray-950 dark:text-white">{{ $bureau['timeout'] }} seconds</dd>
                    </div>

                    @foreach ($bureau['endpoints'] as $type => $path)
                        <div>
                            <dt class="text-gray-500 dark:text-gray-400">{{ \App\Enums\CibilRequestType::tryFrom($type)?->label() ?? $type }} endpoint</dt>
                            <dd class="font-mono break-all text-gray-950 dark:text-white">
                                @if (filled($path))
                                    POST {{ $path }}
                                @else
                                    <x-filament::badge color="danger">Not configured</x-filament::badge>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
