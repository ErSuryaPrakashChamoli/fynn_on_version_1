<?php

namespace App\Enums;

use Filament\Support\Icons\Heroicon;

/**
 * The views of one employee offered by the Employee Lookup page's
 * "Actions" menu. Profile opens first; every other view is one click
 * away from it. The string value is what travels in the page URL
 * (?section=…), so a view can be bookmarked or shared.
 *
 * The Funnel views are the lists behind the OTP & Funnel cards; they are
 * in the Actions menu but not the quick-link row, which would otherwise
 * grow past two lines.
 */
enum EmployeeLookupSection: string
{
    case Profile = 'profile';
    case Achievement = 'achievement';
    case Performance = 'performance';
    case Hierarchy = 'hierarchy';
    case Customers = 'customers';
    case EligibleOtp = 'eligible-otp';
    case NotEligibleOtp = 'not-eligible-otp';
    case CaseLogins = 'case-logins';
    case Approvals = 'approvals';
    case Disbursals = 'disbursals';
    case Dropped = 'dropped';
    case NotApproved = 'not-approved';
    case Leads = 'leads';
    case AssignedLeads = 'assigned-leads';
    case FollowUps = 'follow-ups';
    case Logins = 'logins';

    public function label(): string
    {
        return match ($this) {
            self::Profile => 'Profile',
            self::Achievement => 'Target, Achievement & Incentive',
            self::Performance => 'OTP & Funnel Performance',
            self::Hierarchy => 'Reporting Hierarchy',
            self::Customers => 'Total Customers',
            self::EligibleOtp => 'Eligible OTP',
            self::NotEligibleOtp => 'Not Eligible OTP',
            self::CaseLogins => 'Case Logins',
            self::Approvals => 'Approvals',
            self::Disbursals => 'Disbursals',
            self::Dropped => 'Dropped Cases',
            self::NotApproved => 'Not Approved Cases',
            self::Leads => 'Leads',
            self::AssignedLeads => 'Assigned Leads',
            self::FollowUps => 'Follow-ups',
            self::Logins => 'Login & Screen Time',
        };
    }

    public function icon(): Heroicon
    {
        return match ($this) {
            self::Profile => Heroicon::OutlinedIdentification,
            self::Achievement => Heroicon::OutlinedTrophy,
            self::Performance => Heroicon::OutlinedPresentationChartLine,
            self::Hierarchy => Heroicon::OutlinedShare,
            self::Customers => Heroicon::OutlinedUsers,
            self::EligibleOtp => Heroicon::OutlinedCheckBadge,
            self::NotEligibleOtp => Heroicon::OutlinedNoSymbol,
            self::CaseLogins => Heroicon::OutlinedArrowRightEndOnRectangle,
            self::Approvals => Heroicon::OutlinedHandThumbUp,
            self::Disbursals => Heroicon::OutlinedBanknotes,
            self::Dropped => Heroicon::OutlinedArchiveBoxXMark,
            self::NotApproved => Heroicon::OutlinedHandThumbDown,
            self::Leads => Heroicon::OutlinedPhone,
            self::AssignedLeads => Heroicon::OutlinedInboxArrowDown,
            self::FollowUps => Heroicon::OutlinedCalendarDays,
            self::Logins => Heroicon::OutlinedClock,
        };
    }

    /**
     * The heading the Actions menu files this view under.
     */
    public function group(): string
    {
        return match ($this) {
            self::Profile, self::Hierarchy => 'About',
            self::Achievement, self::Performance => 'Numbers',
            self::Customers, self::EligibleOtp, self::NotEligibleOtp => 'Customers',
            self::CaseLogins, self::Approvals, self::Disbursals, self::Dropped, self::NotApproved => 'Funnel',
            self::Leads, self::AssignedLeads, self::FollowUps, self::Logins => 'Activity',
        };
    }

    public function inQuickLinks(): bool
    {
        return $this->group() !== 'Funnel';
    }

    /**
     * Whether this view is a list of records rather than a set of figures.
     */
    public function isList(): bool
    {
        return ! in_array($this, [self::Profile, self::Achievement, self::Performance, self::Hierarchy], true);
    }

    /**
     * Whether the list is exactly what an OTP & Funnel card counts — and so
     * is scoped the same way (the team of each row's date) rather than by
     * today's team.
     */
    public function isCountedList(): bool
    {
        return in_array($this, [
            self::Customers,
            self::EligibleOtp,
            self::NotEligibleOtp,
            self::CaseLogins,
            self::Approvals,
            self::Disbursals,
            self::Dropped,
            self::NotApproved,
            self::Logins,
        ], true);
    }

    /**
     * For a funnel list, the EmployeePerformanceMetricsService metric key
     * whose stage transition it lists (config('performance.stages')).
     */
    public function stageMetricKey(): ?string
    {
        return match ($this) {
            self::CaseLogins => 'login_count',
            self::Approvals => 'approval_count',
            self::Disbursals => 'disbursal_count',
            self::Dropped => 'dropped_count',
            self::NotApproved => 'not_approved_count',
            default => null,
        };
    }
}
