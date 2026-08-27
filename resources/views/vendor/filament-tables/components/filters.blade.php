@php
    use App\Models\Employee;
    use App\Models\User;
    use Filament\Tables\Enums\FiltersResetActionPosition;

    /*
    |--------------------------------------------------------------------------
    | Scoped "Reset" removal
    |--------------------------------------------------------------------------
    |
    | Filament has no per-table way to hide the filters panel's "Reset"
    | link — it's unconditionally rendered by this shared component for
    | every filterable table in the app. The Employees and Users lists
    | (People & Access) each carry a single default-on toggle filter
    | scoped to the global month selector; "Reset" is redundant there
    | (flipping the toggle back does the same thing), so it's hidden here
    | — scoped to just those two tables via the underlying model, not
    | app-wide, since every other table's Reset link is unaffected.
    |
    */

    $livewire = $applyAction->getLivewire();

    $scopedModel = ($livewire && method_exists($livewire, 'getTable'))
        ? $livewire->getTable()->getModel()
        : null;

    $hidesResetAction = in_array($scopedModel, [Employee::class, User::class], true);
@endphp

@props([
    'applyAction',
    'form',
    'headingTag' => 'h3',
    'resetActionPosition' => FiltersResetActionPosition::Header,
])

<div {{ $attributes->class(['fi-ta-filters']) }}>
    <div class="fi-ta-filters-header">
        <{{ $headingTag }} class="fi-ta-filters-heading">
            {{ __('filament-tables::table.filters.heading') }}
        </{{ $headingTag }}>

        @if (($resetActionPosition === FiltersResetActionPosition::Header) && (! $hidesResetAction))
            <div>
                <x-filament::link
                    :attributes="
                        \Filament\Support\prepare_inherited_attributes(
                            new \Filament\Support\View\ComponentAttributeBag([
                                'color' => 'danger',
                                'tag' => 'button',
                                'wire:click' => 'resetTableFiltersForm',
                                'wire:loading.remove.delay.' . config('filament.livewire_loading_delay', 'default') => '',
                                'wire:target' => 'resetTableFiltersForm',
                            ])
                        )
                    "
                >
                    {{ __('filament-tables::table.filters.actions.reset.label') }}
                </x-filament::link>
            </div>
        @endif
    </div>

    {{ $form }}

    @if ($applyAction->isVisible() || (($resetActionPosition === FiltersResetActionPosition::Footer) && (! $hidesResetAction)))
        <div class="fi-ta-filters-actions-ctn">
            @if ($applyAction->isVisible())
                {{ $applyAction }}
            @endif

            @if (($resetActionPosition === FiltersResetActionPosition::Footer) && (! $hidesResetAction))
                <x-filament::button
                    color="danger"
                    wire:click="resetTableFiltersForm"
                >
                    {{ __('filament-tables::table.filters.actions.reset.label') }}
                </x-filament::button>
            @endif
        </div>
    @endif
</div>
