<x-filament-panels::page>
    <form wire:submit="changePassword" class="max-w-2xl">
        <x-filament::section>
            {{ $this->form }}

            <x-slot name="footer">
                <x-filament::button type="submit" icon="heroicon-o-key">
                    Update Password
                </x-filament::button>
            </x-slot>
        </x-filament::section>
    </form>
</x-filament-panels::page>
