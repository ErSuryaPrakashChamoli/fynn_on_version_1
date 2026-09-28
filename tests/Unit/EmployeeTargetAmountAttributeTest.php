<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\TargetCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The target amount is read from the admin-managed Target Categories, so
 * this needs the application and its seeded target_categories table.
 */
class EmployeeTargetAmountAttributeTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_target_amount_follows_the_category_amount_set_by_the_admin(): void
    {
        TargetCategory::query()->where('code', '3000000')->sole()->update(['target_amount' => 3200000]);

        $this->assertSame(3200000, (new Employee(['category' => '3000000']))->target_amount);
    }
}
