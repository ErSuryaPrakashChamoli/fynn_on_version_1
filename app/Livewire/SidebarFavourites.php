<?php

namespace App\Livewire;

use App\Models\User;
use App\Support\SidebarShortcuts;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Stars a sidebar submodule so it is also listed under Favourites, just
 * below Dashboard (user decision 2026-11-14). The submodule stays in its
 * own module as well.
 *
 * The star on each submodule row (published sidebar/item.blade.php)
 * dispatches `toggle-sidebar-favourite`; this component saves it on the
 * user and fires Filament's own `refresh-sidebar`, so the sidebar
 * re-renders without a page load. App\Support\SidebarShortcuts builds the
 * section.
 */
class SidebarFavourites extends Component
{
    #[On('toggle-sidebar-favourite')]
    public function toggleFavourite(string $item): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            return;
        }

        // Only a submodule in the user's own sidebar; a crafted request
        // cannot store anything else.
        if (! in_array($item, SidebarShortcuts::keysIn(Filament::getNavigation()), true)) {
            return;
        }

        $user->toggleSidebarFavourite($item);

        $this->dispatch('refresh-sidebar');
    }

    public function render(): View
    {
        return view('livewire.sidebar-favourites');
    }
}
