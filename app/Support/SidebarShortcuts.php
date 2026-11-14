<?php

namespace App\Support;

use App\Models\User;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Support\Icons\Heroicon;

/**
 * The sidebar's two shortcut sections (user decision 2026-11-14):
 * "Favourites", just below Dashboard, lists the submodules the user starred;
 * "Recent", below the modules, the last few submodules they opened. Each
 * shortcut is a copy of the submodule's own item — the submodule itself
 * stays in its module — so a section can only ever hold what the user's
 * sidebar already shows: same role checks, same URL.
 *
 * The star sits on every submodule row (published sidebar/item.blade.php)
 * and is saved by App\Livewire\SidebarFavourites. A visit is recorded while
 * the navigation is built for a full-page GET, from whichever submodule
 * Filament marks active (a resource's create/edit/view pages count as that
 * resource's submodule).
 */
class SidebarShortcuts
{
    public const FAVOURITES = 'Favourites';

    public const RECENT = 'Recent';

    /**
     * Submodules kept in the Recent section.
     */
    public const RECENT_LIMIT = 5;

    /**
     * Identifies a submodule across requests: its URL's path and query,
     * without scheme or host.
     */
    public static function keyFor(NavigationItem $item): ?string
    {
        $url = $item->getUrl();

        if (blank($url)) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);

        return filled($query) ? "{$path}?{$query}" : $path;
    }

    public static function isShortcutSection(NavigationGroup $group): bool
    {
        return in_array($group->getLabel(), [self::FAVOURITES, self::RECENT], true);
    }

    /**
     * Keys of every module submodule in the given sidebar: not Dashboard
     * (always on top, no star) and not the shortcut sections.
     *
     * @param  array<NavigationGroup>  $groups
     * @return list<string>
     */
    public static function keysIn(array $groups): array
    {
        $modules = array_filter(
            $groups,
            fn (mixed $group): bool => $group instanceof NavigationGroup && filled($group->getLabel()) && ! self::isShortcutSection($group),
        );

        return array_keys(self::itemsByKey($modules));
    }

    /**
     * Dashboard, then Favourites, the modules with their submodules A to Z,
     * and Recent. Sections with nothing in them are left out. Favourites keep
     * the order they were starred in, Recent the most recent first.
     *
     * @param  list<NavigationGroup>  $modules
     * @return list<NavigationGroup>
     */
    public static function arrange(NavigationGroup $dashboard, array $modules, ?User $user): array
    {
        $modules = array_map(self::withSubmodulesAlphabetically(...), $modules);
        $itemsByKey = self::itemsByKey($modules);

        if ($user !== null) {
            self::recordVisit($user, $itemsByKey);
        }

        $favourites = $user?->sidebarFavourites() ?? [];

        // The copies are taken before the originals are marked: Filament
        // merges extra attributes with the first value winning, so a copy
        // made afterwards could not get its own wire:key.
        $favouritesSection = self::section(self::FAVOURITES, Heroicon::Star, $favourites, $itemsByKey, $favourites);
        $recentSection = self::section(self::RECENT, Heroicon::OutlinedClock, $user?->sidebarRecents() ?? [], $itemsByKey, $favourites);

        foreach ($itemsByKey as $key => $item) {
            self::mark($item, $key, 'item', in_array($key, $favourites, true));
        }

        return array_values(array_filter([$dashboard, $favouritesSection, ...$modules, $recentSection]));
    }

    /**
     * User decision 2026-11-14: submodules A to Z inside every module,
     * whatever order buildNavigation() lists them in.
     */
    private static function withSubmodulesAlphabetically(NavigationGroup $module): NavigationGroup
    {
        $items = array_values($module->getItems());

        usort($items, fn (NavigationItem $first, NavigationItem $second): int => strnatcasecmp(
            (string) $first->getLabel(),
            (string) $second->getLabel(),
        ));

        return $module->items($items);
    }

    /**
     * @param  array<NavigationGroup>  $modules
     * @return array<string, NavigationItem>
     */
    private static function itemsByKey(array $modules): array
    {
        $itemsByKey = [];

        foreach ($modules as $module) {
            foreach ($module->getItems() as $item) {
                $key = $item instanceof NavigationItem ? self::keyFor($item) : null;

                if ($key !== null && ! isset($itemsByKey[$key])) {
                    $itemsByKey[$key] = $item;
                }
            }
        }

        return $itemsByKey;
    }

    /**
     * Livewire round-trips are POSTs and never count as a visit.
     *
     * @param  array<string, NavigationItem>  $itemsByKey
     */
    private static function recordVisit(User $user, array $itemsByKey): void
    {
        if (! request()->isMethod('GET')) {
            return;
        }

        foreach ($itemsByKey as $key => $item) {
            if ($item->isActive()) {
                $user->recordSidebarVisit($key, self::RECENT_LIMIT);

                return;
            }
        }
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, NavigationItem>  $itemsByKey
     * @param  list<string>  $favourites
     */
    private static function section(string $label, Heroicon $icon, array $keys, array $itemsByKey, array $favourites): ?NavigationGroup
    {
        $placement = strtolower($label);

        $shortcuts = collect($keys)
            ->filter(fn (string $key): bool => isset($itemsByKey[$key]))
            ->map(fn (string $key): NavigationItem => self::mark(
                // Never highlighted: the submodule in its own module is.
                (clone $itemsByKey[$key])->isActiveWhen(fn (): bool => false),
                $key,
                $placement,
                in_array($key, $favourites, true),
            ))
            ->values()
            ->all();

        if ($shortcuts === []) {
            return null;
        }

        return NavigationGroup::make($label)
            ->icon($icon)
            ->collapsible(false)
            ->extraSidebarAttributes(['data-sidebar-shortcuts' => $placement])
            ->items($shortcuts);
    }

    /**
     * wire:key lets Livewire move a row (instead of rewriting another row in
     * place) when a star re-renders the sidebar; data-shortcut-key is what
     * the row's star sends.
     */
    private static function mark(NavigationItem $item, string $key, string $placement, bool $isFavourite): NavigationItem
    {
        return $item->extraAttributes([
            'wire:key' => "fynn-sidebar-{$placement}-".md5($key),
            'data-shortcut-key' => $key,
            ...($isFavourite ? ['data-favourite' => 'true'] : []),
        ], merge: true);
    }
}
