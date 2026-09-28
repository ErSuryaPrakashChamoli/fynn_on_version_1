<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * The tab a bell notification is filed under, and how the reminder pop-up
 * treats it. Stored on notifications.category, set when the row is written
 * (see AppServiceProvider): from the `category` a sender tags into the
 * Filament notification's viewData, or — for anything sent untagged —
 * inferred from its title so nothing lands without a tab.
 */
enum NotificationCategory: string
{
    case FollowUp = 'follow_up';
    case Eligibility = 'eligibility';
    case PanRequest = 'pan_request';
    case OtherBankSupport = 'other_bank_support';
    case Settlement = 'settlement';
    case Ocr = 'ocr';
    case Announcement = 'announcement';
    case HelpDesk = 'help_desk';
    case Voting = 'voting';
    case CustomerEdit = 'customer_edit';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::FollowUp => 'Follow-ups',
            self::Eligibility => 'Eligibility',
            self::PanRequest => 'PAN Requests',
            self::OtherBankSupport => 'Other Bank',
            self::Settlement => 'Settlement',
            self::Ocr => 'OCR',
            self::Announcement => 'Announcements',
            self::HelpDesk => 'Help Desk',
            self::Voting => 'Voting',
            self::CustomerEdit => 'Edit Requests',
            self::General => 'General',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::FollowUp => 'heroicon-o-phone-arrow-up-right',
            self::Eligibility => 'heroicon-o-shield-check',
            self::PanRequest => 'heroicon-o-identification',
            self::OtherBankSupport => 'heroicon-o-chat-bubble-left-right',
            self::Settlement => 'heroicon-o-banknotes',
            self::Ocr => 'heroicon-o-document-magnifying-glass',
            self::Announcement => 'heroicon-o-megaphone',
            self::HelpDesk => 'heroicon-o-lifebuoy',
            self::Voting => 'heroicon-o-hand-thumb-up',
            self::CustomerEdit => 'heroicon-o-pencil-square',
            self::General => 'heroicon-o-bell',
        };
    }

    /**
     * Categories the bell shows together under one "Requests" tab.
     *
     * @return list<self>
     */
    public static function requestCategories(): array
    {
        return [self::Eligibility, self::PanRequest, self::CustomerEdit];
    }

    public function isRequest(): bool
    {
        return in_array($this, self::requestCategories(), true);
    }

    /**
     * The viewData to tag onto a Filament notification so it is filed here.
     *
     * @return array{category: string}
     */
    public function viewData(): array
    {
        return ['category' => $this->value];
    }

    /**
     * Resolves the category of a stored Filament notification payload.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromNotificationData(array $data): self
    {
        $tagged = self::tryFrom((string) data_get($data, 'viewData.category'));

        if ($tagged) {
            return $tagged;
        }

        $title = Str::lower((string) ($data['title'] ?? ''));

        return match (true) {
            Str::contains($title, 'follow-up') || Str::contains($title, 'follow up') => self::FollowUp,
            Str::startsWith($title, 'eligibility') => self::Eligibility,
            Str::contains($title, 'pan request') => self::PanRequest,
            Str::contains($title, 'other bank support') => self::OtherBankSupport,
            Str::contains($title, 'bank mis') || Str::contains($title, 'settlement') => self::Settlement,
            Str::startsWith($title, 'ocr') => self::Ocr,
            Str::contains($title, 'ticket') || Str::contains($title, 'complaint') => self::HelpDesk,
            Str::contains($title, 'vote') || Str::contains($title, 'poll') => self::Voting,
            Str::contains($title, 'edit request') => self::CustomerEdit,
            default => self::General,
        };
    }
}
