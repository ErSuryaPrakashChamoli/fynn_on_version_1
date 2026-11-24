<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * The "Status: …" button beside a listing's export (My Customers, My
 * Leads): one dropdown item per status. Picking one stores it on the page's
 * public property, which the table's query scope reads, so the listing and
 * its export follow the status together with the topbar period. Hidden on
 * any other component that renders the same table without the property.
 *
 * Items are named "<property>_<status slug>", e.g. customerStatus_sfl or
 * leadStatus_call_back.
 */
class ListingStatusPicker
{
    /**
     * @param  string  $property  the page's public property holding the choice
     * @param  array<string, string>  $choices  status => label, the "all statuses" choice first
     */
    public static function make(string $property, array $choices): ActionGroup
    {
        $items = [];

        foreach ($choices as $status => $label) {
            $status = (string) $status;

            $items[] = Action::make($property.'_'.Str::slug($status, '_'))
                ->label($label)
                ->icon(fn (mixed $livewire): ?Heroicon => self::current($livewire, $property, $choices) === $status ? Heroicon::Check : null)
                ->action(function (mixed $livewire) use ($property, $status): void {
                    $livewire->{$property} = $status;
                    $livewire->resetPage();
                });
        }

        return ActionGroup::make($items)
            ->label(fn (mixed $livewire): string => 'Status: '.$choices[self::current($livewire, $property, $choices)])
            ->icon(Heroicon::OutlinedFunnel)
            ->color('gray')
            ->button()
            ->dropdownPlacement('bottom-end')
            ->visible(fn (mixed $livewire): bool => property_exists($livewire, $property));
    }

    /**
     * The picked status, or the first choice for an unknown one (a stale
     * or hand-edited URL).
     *
     * @param  array<string, string>  $choices
     */
    private static function current(mixed $livewire, string $property, array $choices): string
    {
        $status = (string) data_get($livewire, $property);

        return array_key_exists($status, $choices) ? $status : (string) array_key_first($choices);
    }
}
