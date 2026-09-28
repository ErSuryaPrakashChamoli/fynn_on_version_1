<?php

namespace App\Enums;

/**
 * How a complaint category finds its handlers.
 *
 * Team: the ticket lands in the queue of everyone holding one of the
 * category's handler roles (IT, Admin, Accounts, ...). Supervisor: the
 * person raising it picks one of their own bosses from the reporting
 * tree — the route for sales / team matters.
 */
enum ComplaintRouting: string
{
    case Team = 'team';
    case Supervisor = 'supervisor';

    public function label(): string
    {
        return match ($this) {
            self::Team => 'A team (by role)',
            self::Supervisor => 'A supervisor the user picks',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Team => 'Everyone holding one of the handler roles sees the ticket and any of them can take it up.',
            self::Supervisor => 'The user chooses their Team Leader, Manager, Cluster Manager or Business Head when raising it.',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $routing): array => [$routing->value => $routing->label()])
            ->all();
    }
}
