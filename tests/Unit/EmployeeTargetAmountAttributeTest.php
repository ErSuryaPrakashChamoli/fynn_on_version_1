<?php

namespace Tests\Unit;

use App\Models\Employee;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmployeeTargetAmountAttributeTest extends TestCase
{
    #[DataProvider('categoryProvider')]
    public function test_target_amount_matches_numeric_category(?string $category, int $expected): void
    {
        $employee = new Employee(['category' => $category]);

        $this->assertSame($expected, $employee->target_amount);
    }

    public static function categoryProvider(): array
    {
        return [
            'silver' => ['2500000', 2500000],
            'gold' => ['3000000', 3000000],
            'diamond' => ['3500000', 3500000],
            'non-numeric falls back to default' => ['team_leader', 2500000],
            'null falls back to default' => [null, 2500000],
        ];
    }
}
