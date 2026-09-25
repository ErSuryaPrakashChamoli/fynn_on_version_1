<?php

namespace App\Livewire;

use App\Enums\NotificationCategory;
use Filament\Livewire\DatabaseNotifications;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The panel's notification bell, split into tabs by NotificationCategory
 * (Follow-ups, Eligibility, PAN requests, …) with an unread count on each.
 *
 * The bell badge still counts every unread notification; the list, "Mark
 * all as read" and "Clear" act on the open tab only, so a flood of one kind
 * can be cleared without losing the others.
 *
 * Extends the PANEL's DatabaseNotifications (not the notifications
 * package's base class): only the panel one supplies getTrigger(), the
 * topbar bell button. Without it the modal still mounts but no bell renders.
 */
class CategorizedDatabaseNotifications extends DatabaseNotifications
{
    /** The open tab: a NotificationCategory value, or 'all'. */
    public string $activeCategory = 'all';

    public function showCategory(string $category): void
    {
        $this->activeCategory = $category === 'all' || NotificationCategory::tryFrom($category)
            ? $category
            : 'all';

        $this->resetPage('database-notifications-page');
    }

    public function getNotificationsQuery(): Builder|Relation
    {
        $query = parent::getNotificationsQuery();

        if ($this->activeCategory !== 'all') {
            $query->where('category', $this->activeCategory);
        }

        return $query;
    }

    /**
     * Every unread notification, whichever tab is open — what the bell badge shows.
     */
    public function getUnreadNotificationsCount(): int
    {
        return $this->allUnreadQuery()->count();
    }

    /**
     * Unread count per category, only for categories that have any.
     *
     * @return array<string, int>
     */
    public function getUnreadCountsByCategory(): array
    {
        return $this->allUnreadQuery()
            ->reorder()
            ->selectRaw('category, COUNT(*) as aggregate')
            ->groupBy('category')
            ->pluck('aggregate', 'category')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    /**
     * Tabs to show: "All" plus every category that has notifications for
     * this user (read or not), in enum order.
     *
     * @return array<string, array{label: string, icon: ?string, unread: int}>
     */
    public function getCategoryTabs(): array
    {
        $present = parent::getNotificationsQuery()
            ->reorder()
            ->distinct()
            ->pluck('category')
            ->all();

        $unread = $this->getUnreadCountsByCategory();

        $tabs = ['all' => ['label' => 'All', 'icon' => null, 'unread' => array_sum($unread)]];

        foreach (NotificationCategory::cases() as $category) {
            if (in_array($category->value, $present, true) || $this->activeCategory === $category->value) {
                $tabs[$category->value] = [
                    'label' => $category->label(),
                    'icon' => $category->icon(),
                    'unread' => $unread[$category->value] ?? 0,
                ];
            }
        }

        return $tabs;
    }

    public function render(): View
    {
        return view('livewire.categorized-database-notifications');
    }

    private function allUnreadQuery(): Builder|Relation
    {
        /** @phpstan-ignore-next-line */
        return parent::getNotificationsQuery()->unread();
    }
}
