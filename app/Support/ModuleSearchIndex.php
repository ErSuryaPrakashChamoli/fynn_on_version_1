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
            if (! $group instanceof NavigationGroup) {
                continue;
            }

            // A standalone item (Dashboard) has no group icon: use its own.
            $moduleIcon = self::iconHtml($group->getIcon() ?? collect($group->getItems())->first()?->getIcon());

            foreach ($group->getItems() as $item) {
                /** @var NavigationItem $item */
                foreach ([$item, ...$item->getChildItems()] as $navigationItem) {
                    if (blank($navigationItem->getUrl())) {
                        continue;
                    }

                    $entries[] = [
                        // A standalone item (Dashboard) is its own module.
                        'module' => $group->getLabel() ?? $navigationItem->getLabel(),
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
