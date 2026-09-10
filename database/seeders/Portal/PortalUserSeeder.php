<?php

namespace Database\Seeders\Portal;

use App\Enums\PortalRole;
use App\Models\Tenant;
use App\Services\Portal\PortalAccountService;
use Illuminate\Database\Seeder;

/**
 * The Academy and Demo logins.
 *
 * Every account is minted through PortalAccountService, which is what
 * guarantees none of them receives a Spatie role — a seeded trainee must
 * be indistinguishable, to the production LMS's hasRole() checks, from a
 * user with no privileges at all.
 *
 * Passwords are fixed here because these are demo/training credentials
 * that a trainer or salesperson has to be able to hand out. They are
 * NOT production accounts and carry no production access.
 */
class PortalUserSeeder extends Seeder
{
    public const TRAINER_EMAIL = 'trainer@fynnedge.com';

    public const TRAINEE_EMAIL = 'trainee@fynnedge.com';

    public const DEMO_EMAIL = 'demo@fynnedge.com';

    public const DEFAULT_PASSWORD = 'Academy@123';

    public const DEMO_PASSWORD = 'Demo@123';

    /** @var list<string> */
    public const TRAINEE_NAMES = [
        'Rahul Sharma', 'Amit Verma', 'Neha Singh', 'Priya Mehta',
        'Rohit Kumar', 'Anjali Gupta', 'Vivek Yadav', 'Pooja Sharma',
        'Karan Malhotra', 'Sneha Verma', 'Rohan Kapoor', 'Divya Joshi',
    ];

    public function __construct(
        protected PortalAccountService $accounts,
    ) {}

    public function run(): void
    {
        $production = Tenant::production();
        $demo = Tenant::demo();

        $this->accounts->create(
            [
                'name' => 'Sunita Rao',
                'email' => self::TRAINER_EMAIL,
                'password' => self::DEFAULT_PASSWORD,
            ],
            $production,
            PortalRole::Trainer,
        );

        // The named trainee the documentation and demo script refer to.
        $this->accounts->create(
            [
                'name' => 'Rahul Sharma',
                'email' => self::TRAINEE_EMAIL,
                'password' => self::DEFAULT_PASSWORD,
            ],
            $production,
            PortalRole::Trainee,
        );

        foreach (self::TRAINEE_NAMES as $index => $name) {
            $this->accounts->create(
                [
                    'name' => $name,
                    'email' => 'trainee'.($index + 1).'@fynnedge.com',
                    'password' => self::DEFAULT_PASSWORD,
                ],
                $production,
                PortalRole::Trainee,
            );
        }

        /*
         * The sales demo login, deliberately given a 90-day expiry so
         * the expiry mechanism is exercised in a normal install rather
         * than only in tests. Extend it with:
         *   php artisan portal:expire-accounts --report
         * and PortalAccountService::extend().
         */
        $this->accounts->create(
            [
                'name' => 'FYNN-ON Demo User',
                'email' => self::DEMO_EMAIL,
                'password' => self::DEMO_PASSWORD,
            ],
            $demo,
            PortalRole::Demo,
            now()->addDays(90),
        );
    }
}
