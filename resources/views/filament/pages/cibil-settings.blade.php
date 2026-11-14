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

    <x-filament::section icon="heroicon-o-signal" heading="Provider connection" description="Read from the server's environment (config/cibil.php). Tokens are never shown.">
        <dl class="fynn-dl text-sm sm:grid-cols-2">
            <div class="fynn-dl-item">
                <dt>Bureau driver</dt>
                <dd class="flex flex-wrap items-center gap-2">
                    <span class="font-mono">{{ $status['driver'] }}</span>
                    <x-filament::badge :color="$status['live'] ? 'success' : 'gray'">
                        {{ $status['live'] ? 'Live Surepass' : 'Not live' }}
                    </x-filament::badge>
                </dd>
            </div>

            <div class="fynn-dl-item">
                <dt>PDF storage</dt>
                <dd>Private disk "{{ $status['pdf_disk'] }}", up to {{ $status['pdf_max_mb'] }} MB</dd>
            </div>
        </dl>

        @foreach ($status['bureaus'] as $bureau)
            <div class="mt-6 border-t border-(--fynn-border-soft) pt-5">
                <h3 class="fynn-subheading">{{ $bureau['label'] }}</h3>

                <dl class="fynn-dl text-sm sm:grid-cols-2">
                    <div class="fynn-dl-item">
                        <dt>Surepass token</dt>
                        <dd>
                            <x-filament::badge :color="$bureau['token_configured'] ? 'success' : 'danger'">
                                {{ $bureau['token_configured'] ? 'Configured' : 'Not configured' }}
                            </x-filament::badge>
                        </dd>
                    </div>

                    <div class="fynn-dl-item">
                        <dt>Base URL</dt>
                        <dd class="flex flex-wrap items-center gap-2">
                            <span class="font-mono break-all">{{ $bureau['base_url'] ?? '—' }}</span>
                            @unless ($bureau['base_url_valid'])
                                <x-filament::badge color="danger">Must be https</x-filament::badge>
                            @endunless
                        </dd>
                    </div>

                    <div class="fynn-dl-item">
                        <dt>Timeout</dt>
                        <dd>{{ $bureau['timeout'] }} seconds (connect {{ $bureau['connect_timeout'] }} s)</dd>
                    </div>

                    <div class="fynn-dl-item">
                        <dt>PDF links fetched from</dt>
                        <dd class="font-mono break-all">
                            {{ $bureau['pdf_hosts'] === [] ? 'Any https host (no allowlist)' : implode(', ', $bureau['pdf_hosts']) }}
                        </dd>
                    </div>

                    <div class="fynn-dl-item">
                        <dt>Last answered request</dt>
                        <dd>{{ $bureau['last_success']?->completed_at?->format('d M Y, h:i A') ?? 'None yet' }}</dd>
                    </div>

                    <div class="fynn-dl-item">
                        <dt>Last failed request</dt>
                        <dd>
                            @if ($bureau['last_failure'])
                                {{ $bureau['last_failure']->completed_at?->format('d M Y, h:i A') ?? $bureau['last_failure']->created_at?->format('d M Y, h:i A') }}
                                <span class="font-medium text-gray-500 dark:text-gray-400">· {{ $bureau['last_failure']->failure_category?->label() ?? $bureau['last_failure']->status->label() }}</span>
                            @else
                                None
                            @endif
                        </dd>
                    </div>

                    @foreach ($bureau['endpoints'] as $type => $path)
                        <div class="fynn-dl-item">
                            <dt>{{ \App\Enums\CibilRequestType::tryFrom($type)?->label() ?? $type }} endpoint</dt>
                            <dd class="font-mono break-all">
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
