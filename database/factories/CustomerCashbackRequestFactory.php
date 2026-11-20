<?php

namespace Database\Factories;

use App\Enums\CashbackPaymentMode;
use App\Enums\CashbackPaymentStatus;
use App\Enums\CashbackRequestStatus;
use App\Enums\CashbackType;
use App\Models\Customer;
use App\Models\CustomerCashbackRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Rows written straight to the table — tests of the workflow go through
 * CustomerCashbackService::raise() instead, which also syncs the file.
 *
 * @extends Factory<CustomerCashbackRequest>
 */
class CustomerCashbackRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => CashbackType::Cashback,
            'amount' => fake()->numberBetween(500, 25000),
            'status' => CashbackRequestStatus::PendingManager,
            'payment_mode' => CashbackPaymentMode::Upi,
            'upi_platform' => 'gpay',
            'payee_upi' => fake()->numerify('9#########').'@okaxis',
            'payment_status' => CashbackPaymentStatus::NotPaid,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (CustomerCashbackRequest $request): void {
            if ($request->voucher_no === null) {
                $request->forceFill(['voucher_no' => sprintf('CB-%06d', $request->getKey())])->saveQuietly();
            }
        });
    }

    public function expected(): static
    {
        return $this->state(fn (): array => ['type' => CashbackType::Expected]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CashbackRequestStatus::Approved,
            'approved_amount' => $attributes['amount'],
            'approved_at' => now(),
        ]);
    }
}
