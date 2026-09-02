<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CustomerJourneyDelegations\CustomerJourneyDelegationResource;
use App\Filament\Resources\CustomerReassignments\CustomerReassignmentResource;
use App\Filament\Resources\JourneyTakeovers\JourneyTakeoverResource;
use App\Filament\Widgets\JourneyContinuityStats;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Entry point for the Customer Journey Continuity module — the
 * administrative/operational layer that manages access to existing
 * Customer Journeys when the normal assigned Manager cannot proceed. This
 * is NOT the Customer Journey itself (see CustomerResource for that); it
 * is where delegation, emergency takeover, SLA breaches, and reassignment
 * are surfaced and actioned from one place.
 */
class JourneyContinuityDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Customer Journey Continuity';

    protected static ?int $navigationSort = 0;

    protected function getHeaderWidgets(): array
    {
        return [
            JourneyContinuityStats::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createDelegation')
                ->label('Create Delegation')
                ->icon('heroicon-o-arrows-right-left')
                ->color('info')
                ->visible(fn (): bool => CustomerJourneyDelegationResource::canAccess())
                ->url(fn (): string => CustomerJourneyDelegationResource::getUrl('index')),

            Action::make('emergencyTakeover')
                ->label('Emergency Takeover')
                ->icon('heroicon-o-shield-exclamation')
                ->color('danger')
                ->visible(fn (): bool => JourneyTakeoverResource::canAccess())
                ->url(fn (): string => JourneyTakeoverResource::getUrl('index')),

            Action::make('reassignCustomers')
                ->label('Reassign Customers')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('warning')
                ->visible(fn (): bool => CustomerReassignmentResource::canAccess())
                ->url(fn (): string => CustomerReassignmentResource::getUrl('index')),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()->hasAnyRole(['Admin', 'Manager', 'Cluster Manager', 'Business Head']);
    }
}
