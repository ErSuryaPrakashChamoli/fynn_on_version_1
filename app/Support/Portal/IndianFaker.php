<?php

namespace App\Support\Portal;

/**
 * The demo dataset's Indian-business vocabulary.
 *
 * Kept in one place so the factories and the seeder draw from the same
 * pools, and so it is obvious at a glance that nothing here is a real
 * person, employer or document number. PAN numbers deliberately use the
 * reserved ZZ series and mobile numbers the 90000-90999 block, which are
 * shapes that look right in a screenshot but cannot collide with a real
 * record.
 */
class IndianFaker
{
    /** @var list<string> */
    public const FIRST_NAMES = [
        'Rahul', 'Amit', 'Neha', 'Priya', 'Rohit', 'Anjali', 'Vivek', 'Pooja',
        'Arjun', 'Karan', 'Sneha', 'Rohan', 'Vikram', 'Deepak', 'Kavita', 'Manish',
        'Shreya', 'Nikhil', 'Ritu', 'Sanjay', 'Meera', 'Ajay', 'Divya', 'Suresh',
    ];

    /** @var list<string> */
    public const LAST_NAMES = [
        'Sharma', 'Verma', 'Singh', 'Mehta', 'Kumar', 'Gupta', 'Yadav', 'Malhotra',
        'Kapoor', 'Joshi', 'Nair', 'Reddy', 'Iyer', 'Chauhan', 'Bansal', 'Agarwal',
    ];

    /** @var list<string> */
    public const CITIES = [
        'Mumbai', 'Delhi', 'Bengaluru', 'Hyderabad', 'Pune', 'Chennai',
        'Ahmedabad', 'Kolkata', 'Jaipur', 'Noida', 'Gurugram', 'Indore',
    ];

    /**
     * Fictional employers — none of these is a real registered company.
     *
     * @var list<string>
     */
    public const COMPANIES = [
        'Meridian Softworks Pvt Ltd', 'Bluestone Analytics', 'Vantage Retail India',
        'Northline Logistics', 'Crestpoint Consulting', 'Auralink Technologies',
        'Sunhaven Healthcare', 'Ironwood Manufacturing', 'Skyfare Travels',
        'Quantum Ledger Services',
    ];

    /** @var list<string> */
    public const COMPANY_CATEGORIES = ['Cat A', 'Cat B', 'Cat C', 'Listed', 'Unlisted'];

    /** @var list<string> */
    public const LEAD_SOURCES = [
        'tele-calling', 'website', 'referral', 'walk-in', 'campaign', 'partner',
    ];

    /**
     * Fictional lender names. Deliberately NOT real Indian banks — the
     * demo must not imply a partnership that does not exist.
     *
     * @var list<array{name: string, short: string, type: string}>
     */
    public const BANKS = [
        ['name' => 'Meridian Bank', 'short' => 'MRD', 'type' => 'private'],
        ['name' => 'Sundara Finserv', 'short' => 'SNF', 'type' => 'nbfc'],
        ['name' => 'Kaveri National Bank', 'short' => 'KNB', 'type' => 'public'],
        ['name' => 'Northline Credit', 'short' => 'NLC', 'type' => 'nbfc'],
        ['name' => 'Aravalli Bank', 'short' => 'ARV', 'type' => 'private'],
        ['name' => 'Sagara Small Finance Bank', 'short' => 'SSF', 'type' => 'sfb'],
        ['name' => 'Vindhya Housing Finance', 'short' => 'VHF', 'type' => 'hfc'],
        ['name' => 'Deccan Capital', 'short' => 'DCC', 'type' => 'nbfc'],
        ['name' => 'Konkan Cooperative Bank', 'short' => 'KCB', 'type' => 'cooperative'],
        ['name' => 'Nilgiri Finance Ltd', 'short' => 'NGF', 'type' => 'nbfc'],
    ];

    public static function name(): string
    {
        return fake()->randomElement(self::FIRST_NAMES).' '.fake()->randomElement(self::LAST_NAMES);
    }

    public static function city(): string
    {
        return fake()->randomElement(self::CITIES);
    }

    /**
     * A PAN-shaped string in the reserved ZZ series, so it can never
     * match an issued PAN.
     */
    public static function pan(): string
    {
        return 'ZZ'.fake()->regexify('[A-Z]{3}').fake()->numerify('####').fake()->regexify('[A-Z]');
    }

    /**
     * A mobile number inside the 90000 00000 block reserved here for
     * demo data.
     */
    public static function mobile(): string
    {
        return '90'.fake()->numerify('00#######');
    }

    public static function email(string $name, string $domain = 'demo-fynnon.test'): string
    {
        $slug = str_replace(' ', '.', strtolower($name));

        return $slug.'.'.fake()->numberBetween(10, 99).'@'.$domain;
    }
}
