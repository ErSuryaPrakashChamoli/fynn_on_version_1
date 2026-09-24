<?php

namespace Database\Seeders\DemoEnvironment;

use Faker\Factory as FakerFactory;
use Faker\Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared state for the demo environment seeders: one seeded Faker (so a
 * refresh rebuilds the same people every night), the dates everything is
 * placed relative to, and the ids the later seeders build on.
 */
class DemoWorld
{
    public Generator $faker;

    public Carbon $today;

    public Carbon $now;

    /** @var array<string, int> persona slug => users.id */
    public array $personaUserIds = [];

    /** @var array<string, int> persona slug => employees.id */
    public array $personaEmployeeIds = [];

    /** @var Collection<int, object> every seeded employee row (id, designation, reporting columns, …) */
    public Collection $employees;

    /** @var array<int, int> employees.id => users.id */
    public array $userIdByEmployee = [];

    /** @var array<int, array{id: int, name: string}> */
    public array $banks = [];

    /** @var array<int, string> active cities.city values */
    public array $cities = [];

    /** @var array<string, true> */
    private array $usedMobiles = [];

    /** @var array<string, true> */
    private array $usedPans = [];

    private int $sequence = 0;

    public const IN_HOUSE_BANKS = ['BFL Prime', 'BFL Growth', 'BFL SOL', 'BFL RSL'];

    public const OTHER_BANKS = ['HDFC Bank', 'ICICI Bank', 'Axis Bank', 'Tata Capital', 'Incred', 'Poonawala', 'ABFL', 'Kotak Mahindra Bank', 'IDFC First Bank', 'Piramal Finance'];

    public const COMPANIES = [
        'Tata Consultancy Services', 'Infosys Limited', 'Wipro Technologies', 'HCL Technologies', 'Tech Mahindra',
        'Accenture Solutions', 'Capgemini India', 'Cognizant Technology', 'IBM India', 'Genpact India',
        'Deloitte India', 'KPMG Global Services', 'Reliance Retail', 'Bharti Airtel', 'Maruti Suzuki',
        'Mahindra & Mahindra', 'Larsen & Toubro', 'Asian Paints', 'Hindustan Unilever', 'ITC Limited',
        'Apollo Hospitals', 'Fortis Healthcare', 'Amazon Development Centre', 'Flipkart Internet', 'Zomato Limited',
        'Concentrix Services', 'Teleperformance India', 'Govt School Teacher', 'Indian Railways', 'State Govt Employee',
    ];

    public function __construct()
    {
        $this->faker = FakerFactory::create('en_IN');
        $this->faker->seed(20260921);
        mt_srand(20260921);

        $this->now = now();
        $this->today = today();
        $this->employees = collect();
    }

    /** First day of the month $offset months back (0 = the current month). */
    public function monthStart(int $offset = 0): Carbon
    {
        return $this->today->copy()->startOfMonth()->subMonthsNoOverflow($offset);
    }

    /**
     * Last day of that month, capped at the last business day for the
     * current month — the nightly refresh runs just after midnight, and
     * nothing should be stamped as having happened later than "now".
     */
    public function monthEndOrToday(int $offset = 0): Carbon
    {
        $end = $this->monthStart($offset)->endOfMonth()->startOfDay();

        return $end->greaterThan($this->lastBusinessDay()) ? $this->lastBusinessDay() : $end;
    }

    /** Today once the office day is underway, otherwise yesterday. */
    public function lastBusinessDay(): Carbon
    {
        return $this->now->hour >= 11 ? $this->today->copy() : $this->today->copy()->subDay();
    }

    /**
     * Working days (Mon–Sat, as config/performance.php counts them)
     * between two dates, inclusive.
     *
     * @return array<int, Carbon>
     */
    public function workingDays(Carbon $from, Carbon $to): array
    {
        $days = [];

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            if (! $day->isSunday()) {
                $days[] = $day->copy();
            }
        }

        return $days;
    }

    public function chance(float $probability): bool
    {
        return mt_rand() / mt_getrandmax() < $probability;
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[array_rand($items)];
    }

    /**
     * Picks a key by weight: ['a' => 60, 'b' => 40].
     *
     * @param  array<string, int>  $weights
     */
    public function weighted(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (string) $key;
            }
        }

        return (string) array_key_last($weights);
    }

    /** A time on $day within office hours, never later than now when $day is today. */
    public function timeOn(Carbon $day, int $fromHour = 9, int $toHour = 19): Carbon
    {
        $moment = $day->copy()->setTime(mt_rand($fromHour, $toHour - 1), mt_rand(0, 59), mt_rand(0, 59));

        if ($moment->greaterThan($this->now) && $day->isSameDay($this->now)) {
            return $this->now->copy()->subMinutes(mt_rand(5, 90));
        }

        return $moment;
    }

    public function mobile(): string
    {
        do {
            $number = $this->pick(['6', '7', '8', '9']).str_pad((string) mt_rand(0, 999999999), 9, '0', STR_PAD_LEFT);
        } while (isset($this->usedMobiles[$number]));

        $this->usedMobiles[$number] = true;

        return $number;
    }

    public function pan(string $lastName): string
    {
        $initial = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $lastName) ?: 'X', 0, 1));

        do {
            $pan = $this->letters(3).'P'.$initial.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT).$this->letters(1);
        } while (isset($this->usedPans[$pan]));

        $this->usedPans[$pan] = true;

        return $pan;
    }

    public function email(string $name, string $domain = 'example.in'): string
    {
        return str($name)->lower()->ascii()->replaceMatches('/[^a-z]+/', '.')->trim('.')
            .'.'.(++$this->sequence).'@'.$domain;
    }

    /** Rounded to the nearest $step, the way loan amounts are quoted. */
    public function amount(int $min, int $max, int $step = 5000): int
    {
        return (int) (round(mt_rand($min, $max) / $step) * $step);
    }

    public function nextSequence(): int
    {
        return ++$this->sequence;
    }

    /**
     * Bulk insert in chunks, returning nothing — ids are re-read by the
     * caller where it needs them.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function insert(string $table, array $rows, int $chunk = 500): void
    {
        foreach (array_chunk($rows, $chunk) as $batch) {
            DB::table($table)->insert($batch);
        }
    }

    public function userIdFor(?int $employeeId): ?int
    {
        return $employeeId ? ($this->userIdByEmployee[$employeeId] ?? null) : null;
    }

    /** @return Collection<int, object> */
    public function employeesWith(int $designation, bool $activeOnly = true): Collection
    {
        return $this->employees
            ->where('designation', $designation)
            ->when($activeOnly, fn (Collection $rows): Collection => $rows->where('exit_status', 'no'))
            ->values();
    }

    public function employee(int $id): object
    {
        return $this->employees->firstWhere('id', $id);
    }

    /** A one-page PDF, so document viewers in the demo have a real file to show. */
    public function samplePdf(string $title): string
    {
        $text = 'BT /F1 18 Tf 60 760 Td (FYNN-ON Demo - {$title}) Tj ET '
            .'BT /F1 11 Tf 60 730 Td (Sample document for the demo environment. All data is fictitious.) Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($text)." >>\nstream\n{$text}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function letters(int $count): string
    {
        $out = '';

        for ($i = 0; $i < $count; $i++) {
            $out .= chr(mt_rand(65, 90));
        }

        return $out;
    }
}
