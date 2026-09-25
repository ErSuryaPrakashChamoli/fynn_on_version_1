<?php

namespace Tests\Feature;

use App\Filament\Resources\LeadAssignmentReports\Tables\LeadAssignmentReportsTable;
use App\Models\CustomerAssignment;
use Tests\TestCase;

class LeadCallBackStatusTest extends TestCase
{
    public function test_call_back_is_an_open_follow_up_status(): void
    {
        $this->assertArrayHasKey('Call Back', CustomerAssignment::FOLLOW_UP_STATUSES);
        $this->assertNotContains('Call Back', CustomerAssignment::CLOSED_FOLLOW_UP_STATUSES);
    }

    public function test_call_back_is_counted_in_the_lead_assignment_report(): void
    {
        $this->assertSame('call_back_count', LeadAssignmentReportsTable::REMARK_COUNTS['Call Back']);
    }
}
