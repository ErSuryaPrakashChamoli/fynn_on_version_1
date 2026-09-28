<?php

namespace App\Support;

use App\Filament\Pages\EmployeeHierarchy;
use App\Filament\Pages\MyVotes;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Models\User;

/**
 * What a user holding the IT role (and not Admin) may reach in the panel.
 *
 * Decided 2026-09-26: IT sees the Reporting Hierarchy (company-wide,
 * anyone in it) and the Help Desk tickets — plus, since 2026-09-27, My
 * Votes, because polls go to everyone. Every other resource, page and
 * dashboard is off for them. Enforced in three
 * places that all read this one list:
 *  - AdminPanelProvider::navigationItemsFor() hides everything else from
 *    the sidebar;
 *  - RestrictItRoleToAllowedModules (panel authMiddleware) sends any other
 *    URL to the hierarchy page, so nothing is reachable by typing it;
 *  - HierarchyHelper::ownHierarchyIds() / EmployeeHierarchy::canAccess()
 *    open the whole company to them on the one page they do get.
 */
final class ItModuleAccess
{
    /**
     * Resources/pages whose navigation items an IT user keeps.
     *
     * @var array<int, class-string>
     */
    public const array ALLOWED_CLASSES = [
        EmployeeHierarchy::class,
        ComplaintResource::class,
        // Voting is company-wide (2026-09-27): IT answers polls like anyone.
        MyVotes::class,
    ];

    /**
     * Route-name fragments an IT user may request. Matched with
     * str_contains() so both the admin and the demo panel (filament.admin.*
     * / filament.demo.*) are covered by one list. Signing in/out and the
     * profile stay open; everything else is closed.
     *
     * @var array<int, string>
     */
    public const array ALLOWED_ROUTE_FRAGMENTS = [
        '.auth.',
        '.pages.change-password',
        '.pages.employee-hierarchy',
        '.pages.my-votes',
        '.resources.complaints.',
    ];

    public static function restricts(?User $user): bool
    {
        return $user !== null && $user->isRestrictedToItModules();
    }

    /**
     * @param  class-string  $class
     */
    public static function allowsNavigationOf(string $class): bool
    {
        return in_array($class, self::ALLOWED_CLASSES, true);
    }

    public static function allowsRoute(?string $routeName): bool
    {
        if (blank($routeName)) {
            return false;
        }

        foreach (self::ALLOWED_ROUTE_FRAGMENTS as $fragment) {
            if (str_contains($routeName, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public static function landingUrl(): string
    {
        return EmployeeHierarchy::getUrl();
    }
}
