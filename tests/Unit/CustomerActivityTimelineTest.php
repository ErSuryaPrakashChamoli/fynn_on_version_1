<?php

namespace Tests\Unit;

use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Models\ActivityLog;
use PHPUnit\Framework\TestCase;

class CustomerActivityTimelineTest extends TestCase
{
    public function test_created_activity_only_notes_the_creation(): void
    {
        $activity = new ActivityLog([
            'event' => 'created',
            'attribute_changes' => [
                'attributes' => ['eligibility_status' => 'consent_pending', 'name' => 'Ravi'],
            ],
        ]);

        $html = CustomerInfolist::formatActivityChanges($activity);

        $this->assertStringContainsString('Customer file created', $html);
        $this->assertStringNotContainsString('Eligibility Status', $html);
    }

    public function test_timestamps_and_id_are_not_listed_as_changes(): void
    {
        $activity = new ActivityLog([
            'event' => 'updated',
            'attribute_changes' => [
                'old' => ['eligibility_status' => 'consent_pending', 'updated_at' => '2026-09-21T13:22:25.000000Z'],
                'attributes' => ['eligibility_status' => 'not_eligible', 'updated_at' => '2026-09-21T13:22:40.000000Z'],
            ],
        ]);

        $html = CustomerInfolist::formatActivityChanges($activity);

        $this->assertStringContainsString('Eligibility Status', $html);
        $this->assertStringNotContainsString('Updated At', $html);
    }

    public function test_update_touching_only_timestamps_shows_placeholder(): void
    {
        $activity = new ActivityLog([
            'event' => 'updated',
            'attribute_changes' => [
                'old' => ['updated_at' => '2026-09-21T13:22:25.000000Z'],
                'attributes' => ['updated_at' => '2026-09-21T13:22:40.000000Z'],
            ],
        ]);

        $this->assertStringContainsString('No field changes', CustomerInfolist::formatActivityChanges($activity));
    }

    public function test_updated_activity_renders_each_change_once(): void
    {
        $activity = new ActivityLog(['attribute_changes' => [
            'old' => ['eligibility_status' => 'consent_pending', 'eligibility_reason' => null],
            'attributes' => ['eligibility_status' => 'not_eligible', 'eligibility_reason' => 'Low score'],
        ]]);

        $html = CustomerInfolist::formatActivityChanges($activity);

        $this->assertSame(1, substr_count($html, 'Eligibility Status'));
        $this->assertSame(1, substr_count($html, 'Eligibility Reason'));
        $this->assertStringContainsString('Old: consent_pending', $html);
        $this->assertStringContainsString('New: not_eligible', $html);
        $this->assertStringContainsString('Old: -', $html);
        $this->assertStringContainsString('New: Low score', $html);
    }

    public function test_activity_without_changes_shows_placeholder(): void
    {
        $html = CustomerInfolist::formatActivityChanges(new ActivityLog);

        $this->assertStringContainsString('No field changes', $html);
    }

    public function test_values_are_escaped(): void
    {
        $activity = new ActivityLog(['event' => 'updated', 'attribute_changes' => [
            'old' => ['remarks' => null],
            'attributes' => ['remarks' => '<script>x</script>'],
        ]]);

        $html = CustomerInfolist::formatActivityChanges($activity);

        $this->assertStringNotContainsString('<script>', $html);
    }
}
