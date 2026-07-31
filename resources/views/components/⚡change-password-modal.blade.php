<x-filament-panels::page>

    <form wire:submit="changePassword">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit">
                Change Password
            </x-filament::button>
        </div>
    </form>

</x-filament-panels::page>

