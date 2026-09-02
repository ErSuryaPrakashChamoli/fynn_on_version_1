<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerJourneyProgressTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function activeJourneyStatuses(): array
    {
        return [
            'sfl' => ['sfl', 'Sfl', '1/4', 'width: 0%;'],
            'underwriting' => ['underwriting', 'Underwriting', '2/4', 'width: 33%;'],
            'approved' => ['approved', 'Approved', '3/4', 'width: 66%;'],
            'sanctioned' => ['sanctioned', 'Disbursed', '4/4', 'width: 100%;'],
        ];
    }

    #[DataProvider('activeJourneyStatuses')]
    public function test_it_renders_active_journey_progress(string $status, string $label, string $completedFraction, string $progressWidth): void
    {
        $html = $this->renderJourneyStatus($status);

        $this->assertStringContainsString($label, $html);
        $this->assertStringContainsString($completedFraction, $html);
        $this->assertStringContainsString($progressWidth, $html);
        $this->assertStringNotContainsString('Journey Terminated', $html);
    }

    public function test_it_renders_not_approved_as_stopped(): void
    {
        $html = $this->renderJourneyStatus('not_approved');

        $this->assertStringContainsString('Not Approved', $html);
        $this->assertStringContainsString('This application was not approved.', $html);
        $this->assertStringContainsString('Journey Terminated', $html);
        $this->assertStringNotContainsString('width: 100%;', $html);
    }

    private function renderJourneyStatus(?string $status): string
    {
        return view('filament.components.customer-journey-progress', [
            'get' => fn (string $key): ?string => $key === 'journey_status' ? $status : null,
        ])->render();
    }
}
