<?php

namespace Database\Seeders;

use App\Enums\ComplaintRouting;
use App\Models\ComplaintCategory;
use App\Models\ComplaintPriority;
use Illuminate\Database\Seeder;

/**
 * The Help Desk's starting configuration: the complaint categories the
 * user asked for (Fynn-On, IT, Workspace, Asset, Desktop, Sales / Team,
 * Other), each with a reason dropdown and a route to its handling team,
 * plus four priorities with a resolution SLA in minutes.
 *
 * Safe to run repeatedly: it only ever adds what is missing and never
 * touches a category, reason or priority the Admin has already edited.
 */
class HelpDeskSeeder extends Seeder
{
    /**
     * @return array<string, array{description: string, routing: ComplaintRouting, handler_roles: list<string>, reasons: list<string>}>
     */
    public static function defaultCategories(): array
    {
        return [
            'Fynn-On Application' => [
                'description' => 'Anything wrong or missing in the Fynn-On LMS itself.',
                'routing' => ComplaintRouting::Team,
                'handler_roles' => ['IT'],
                'reasons' => ['Cannot log in', 'Page error or crash', 'Wrong data shown', 'Customer Journey', 'Report / export problem', 'Feature request', 'Slow performance', 'Other'],
            ],
            'IT' => [
                'description' => 'Email, network, printers, software and accounts.',
                'routing' => ComplaintRouting::Team,
                'handler_roles' => ['IT'],
                'reasons' => ['Email not working', 'Internet / network down', 'Software installation', 'Password reset', 'Printer / scanner', 'Other'],
            ],
            'Workspace' => [
                'description' => 'Seating, facilities, housekeeping and office environment.',
                'routing' => ComplaintRouting::Team,
                'handler_roles' => ['Admin'],
                'reasons' => ['Seating / desk', 'Air conditioning / lighting', 'Housekeeping', 'Pantry / drinking water', 'Safety concern', 'Other'],
            ],
            'Asset' => [
                'description' => 'Company assets issued to you: ID card, headset, SIM, phone.',
                'routing' => ComplaintRouting::Team,
                'handler_roles' => ['Admin', 'IT'],
                'reasons' => ['Asset not issued', 'Asset damaged', 'Asset lost / stolen', 'Asset replacement', 'Other'],
            ],
            'Desktop / Laptop' => [
                'description' => 'Your computer, monitor, keyboard, mouse or headset.',
                'routing' => ComplaintRouting::Team,
                'handler_roles' => ['IT'],
                'reasons' => ['Not switching on', 'Very slow', 'Mouse not working', 'Keyboard not working', 'Monitor not working', 'CPU issue', 'Headphone issue', 'Voice issue', 'Dialer issue', 'Slow call flow', 'Internet not working', 'Other issue'],
            ],
            'Sales / Team' => [
                'description' => 'Leads, targets, incentives or anything for your reporting line.',
                'routing' => ComplaintRouting::Supervisor,
                'handler_roles' => [],
                'reasons' => ['Lead allocation', 'Target / incentive query', 'Attendance / leave', 'Team concern', 'Training needed', 'Other'],
            ],
            'HR / Payroll' => [
                'description' => 'Salary, reimbursements, documents and HR policies.',
                'routing' => ComplaintRouting::Team,
                'handler_roles' => ['Accounts', 'Admin'],
                'reasons' => ['Salary not credited', 'Salary mismatch', 'Reimbursement', 'Payslip / documents', 'Policy query', 'Other'],
            ],
            'Other' => [
                'description' => 'Anything that does not fit the categories above.',
                'routing' => ComplaintRouting::Team,
                'handler_roles' => ['Admin'],
                'reasons' => ['General query', 'Suggestion', 'Grievance', 'Other'],
            ],
        ];
    }

    /**
     * Low within 2 days, Medium within a day, High within an hour,
     * Critical within 10 minutes (set 2026-09-26).
     *
     * @return array<string, array{color: string, resolve_within_minutes: int}>
     */
    public static function defaultPriorities(): array
    {
        return [
            'Low' => ['color' => 'gray', 'resolve_within_minutes' => 2 * 1440],
            'Medium' => ['color' => 'info', 'resolve_within_minutes' => 1440],
            'High' => ['color' => 'warning', 'resolve_within_minutes' => 60],
            'Critical' => ['color' => 'danger', 'resolve_within_minutes' => 10],
        ];
    }

    public function run(): void
    {
        $order = 0;

        foreach (self::defaultCategories() as $name => $definition) {
            $order++;

            $category = ComplaintCategory::query()->firstOrCreate(
                ['name' => $name],
                [
                    'description' => $definition['description'],
                    'routing' => $definition['routing'],
                    'handler_roles' => $definition['handler_roles'],
                    'is_active' => true,
                    'sort_order' => $order,
                ],
            );

            if (! $category->wasRecentlyCreated) {
                continue;
            }

            self::syncReasons($category, $definition['reasons']);
        }

        $order = 0;

        foreach (self::defaultPriorities() as $name => $definition) {
            $order++;

            ComplaintPriority::query()->firstOrCreate(
                ['name' => $name],
                [...$definition, 'is_active' => true, 'sort_order' => $order],
            );
        }
    }

    /**
     * Adds any of $reasons the category does not have yet, keeping "Other" /
     * "Other issue" last. Existing reasons (and the Admin's edits to them) are untouched.
     *
     * @param  list<string>  $reasons
     */
    public static function syncReasons(ComplaintCategory $category, array $reasons): void
    {
        $existing = $category->reasons()->pluck('name')->map(fn (string $name): string => mb_strtolower($name))->all();
        $order = (int) $category->reasons()->max('sort_order');

        foreach ($reasons as $reason) {
            if (in_array(mb_strtolower($reason), $existing, true)) {
                continue;
            }

            $category->reasons()->create([
                'name' => $reason,
                'is_active' => true,
                'sort_order' => ++$order,
            ]);
        }

        $category->reasons()->whereIn('name', ['Other', 'Other issue'])->update(['sort_order' => $order + 1]);
    }
}
