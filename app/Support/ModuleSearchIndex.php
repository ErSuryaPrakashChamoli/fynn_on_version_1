<?php

namespace App\Support;

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

use function Filament\Support\generate_icon_html;

/**
 * Everything the topbar module search can jump to: each sidebar module
 * (navigation group) and its sub-modules (items), exactly as the current
 * user's sidebar shows them. It reads the built navigation, so a role or
 * IT restriction that hides a screen from the sidebar hides it here too.
 * The unlabelled top group (the standalone Dashboard) is left out: it is
 * one click away in the sidebar and only cluttered the pop-up.
 */
class ModuleSearchIndex
{
    /**
     * @return list<array{module: string, label: string, url: string, newTab: bool, icon: string|null, moduleIcon: string|null}>
     */
    public static function entries(): array
    {
        if (! Filament::auth()->check()) {
            return [];
        }

        $entries = [];

        foreach (Filament::getNavigation() as $group) {
            // Favourites / Recent only repeat submodules listed in their own
            // module; the unlabelled top group is the standalone Dashboard.
            if ((! $group instanceof NavigationGroup) || blank($group->getLabel()) || SidebarShortcuts::isShortcutSection($group)) {
                continue;
            }

            $moduleIcon = self::iconHtml($group->getIcon() ?? collect($group->getItems())->first()?->getIcon());

            foreach ($group->getItems() as $item) {
                /** @var NavigationItem $item */
                foreach ([$item, ...$item->getChildItems()] as $navigationItem) {
                    if (blank($navigationItem->getUrl())) {
                        continue;
                    }

                    $entries[] = [
                        'module' => $group->getLabel(),
                        'label' => $navigationItem->getLabel(),
                        'url' => $navigationItem->getUrl(),
                        'newTab' => $navigationItem->shouldOpenUrlInNewTab(),
                        'icon' => self::iconHtml($navigationItem->getIcon()),
                        'moduleIcon' => $moduleIcon,
                    ];
                }
            }
        }

        return $entries;
    }

    private static function iconHtml(mixed $icon): ?string
    {
        return filled($icon) ? generate_icon_html($icon)?->toHtml() : null;
    }
}
