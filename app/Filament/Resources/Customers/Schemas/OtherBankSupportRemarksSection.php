<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\OtherBankRemarkStage;
use App\Models\Customer;
use App\Models\User;
use App\Services\OtherBankSupportService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;

/**
 * The step-wise Other Bank Support remarks block, shared by the customer
 * edit form and the view infolist so the owner's hierarchy (callers see
 * only the view page) and the support team read the same thing.
 */
class OtherBankSupportRemarksSection
{
    public static function make(): Section
    {
        return Section::make('Other Bank Support Remarks')
            ->key('otherBankSupportRemarks')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->description('Step-wise remarks from the Other Bank Support team, visible to the file owner and everyone above them.')
            ->columnSpanFull()
            ->visible(fn (?Customer $record): bool => $record !== null && OtherBankSupportService::isOtherBankCase($record))
            ->headerActions([
                Action::make('addOtherBankRemark')
                    ->label('Add Remark')
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (?Customer $record): bool => $record !== null
                        && OtherBankSupportService::canWorkOn(Filament::auth()->user(), $record))
                    ->modalHeading('Add Other Bank Support remark')
                    ->modalSubmitActionLabel('Save remark')
                    ->schema([
                        Select::make('stage')
                            ->label('Step')
                            ->options(OtherBankRemarkStage::options())
                            ->default(fn (?Customer $record): string => OtherBankRemarkStage::forJourneyStatus($record?->journey_status)->value)
                            ->required(),

                        Textarea::make('remark')
                            ->label('Remark')
                            ->rows(4)
                            ->maxLength(2000)
                            ->required(),
                    ])
                    ->action(function (array $data, Customer $record): void {
                        $user = Filament::auth()->user();

                        if (! $user instanceof User) {
                            return;
                        }

                        app(OtherBankSupportService::class)->addRemark(
                            $user,
                            $record,
                            OtherBankRemarkStage::from($data['stage']),
                            $data['remark'],
                        );

                        Notification::make()
                            ->title('Remark saved')
                            ->success()
                            ->send();
                    }),
            ])
            ->schema([
                View::make('filament.components.other-bank-support-remarks')
                    ->viewData(fn (?Customer $record): array => [
                        'groups' => $record
                            ? app(OtherBankSupportService::class)->remarksByStage($record)
                            : [],
                    ]),
            ]);
    }
}
