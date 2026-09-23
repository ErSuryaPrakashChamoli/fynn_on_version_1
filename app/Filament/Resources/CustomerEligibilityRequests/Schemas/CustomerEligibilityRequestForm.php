<?php

namespace App\Filament\Resources\CustomerEligibilityRequests\Schemas;

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerEligibilityService;
use App\Support\HierarchyHelper;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Raising an eligibility request from the Request menu instead of from the
 * customer page. The customer picker only offers files the user may raise a
 * request for; CreateCustomerEligibilityRequest re-checks through the service.
 */
class CustomerEligibilityRequestForm
{
    private const SEARCH_LIMIT = 50;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ask the Admin to make a Not Eligible file eligible')
                    ->description('Only Not Eligible files in your team without a waiting request are listed. Once approved, the file becomes Eligible, moves to SFL and is locked.')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('customer_id')
                            ->label('Customer')
                            ->required()
                            ->live()
                            ->getSearchResultsUsing(fn (string $search): array => self::requestableCustomers()
                                ->where(fn ($query) => $query
                                    ->where('customer_name', 'like', "%{$search}%")
                                    ->orWhere('mobile_no', 'like', "%{$search}%")
                                    ->orWhere('application_no', 'like', "%{$search}%"))
                                ->limit(self::SEARCH_LIMIT)
                                ->get()
                                ->mapWithKeys(fn (Customer $customer): array => [$customer->id => self::label($customer)])
                                ->all())
                            ->options(fn (): array => self::requestableCustomers()
                                ->latest('id')
                                ->limit(self::SEARCH_LIMIT)
                                ->get()
                                ->mapWithKeys(fn (Customer $customer): array => [$customer->id => self::label($customer)])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => ($customer = Customer::find($value)) ? self::label($customer) : null),

                        Placeholder::make('current_reason')
                            ->label('Current Not Eligible reason')
                            ->visible(fn (Get $get): bool => filled($get('customer_id')))
                            ->content(function (Get $get): string {
                                $reason = Customer::query()->whereKey($get('customer_id'))->value('eligibility_reason');

                                return CustomerEligibilityService::NOT_ELIGIBLE_REASONS[$reason] ?? '—';
                            }),

                        Textarea::make('reason')
                            ->label('Why should it be eligible?')
                            ->rows(4)
                            ->maxLength(1000)
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * Not Eligible files in the user's branch with no waiting request.
     *
     * @return Builder<Customer>
     */
    public static function requestableCustomers(): Builder
    {
        $user = Filament::auth()->user();
        $query = Customer::query()
            ->where('eligibility_status', CustomerEligibilityService::NOT_ELIGIBLE)
            ->whereDoesntHave('eligibilityRequests', fn ($request) => $request->pending());

        if (! $user instanceof User || CustomerEligibilityService::isReviewer($user) || ! $user->employee) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('assign_to', HierarchyHelper::visibleSubordinateIds($user->employee));
    }

    private static function label(Customer $customer): string
    {
        return collect([$customer->customer_name, $customer->mobile_no, $customer->application_no])
            ->filter()
            ->implode(' · ');
    }
}
