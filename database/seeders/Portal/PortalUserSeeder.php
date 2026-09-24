<?php

namespace Database\Seeders\Portal;

use App\Enums\PortalRole;
use App\Models\Tenant;
use App\Services\Portal\PortalAccountService;
use Illuminate\Database\Seeder;

/**
 * The Academy logins. (/demo logins live only in the demo database —
 * see Database\Seeders\Demo\DemoOrganisationSeeder.)
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

    public const DEFAULT_PASSWORD = 'Academy@123';

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
    }
}
