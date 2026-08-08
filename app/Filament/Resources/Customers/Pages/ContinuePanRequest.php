<?php

namespace App\Filament\Resources\Customers\Pages;

// use App\Filament\Resources\CustomerResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerPanRequest;
use Filament\Resources\Pages\Page;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;

class ContinuePanRequest extends Page implements HasForms
{
    use InteractsWithForms;
    protected static string $resource = CustomerResource::class;

    // protected static string $view =
    //     'filament.resources.customer-resource.pages.continue-pan-request';
    // protected string $view = 'filament.resources.customer-resource.pages.continue-pan-request';
    protected  string $view = 'filament.resources.customers.pages.continue-pan-request';

    public CustomerPanRequest $panRequest;

    public Customer $customer;

    public function mount(CustomerPanRequest $request): void
    {
        abort_unless(
            $request->status === CustomerPanRequest::STATUS_APPROVED,
            403
        );

        abort_unless(
            $request->requested_by === auth()->user()->employee->id,
            403
        );

        $this->panRequest = $request;
        $this->customer = $request->customer;
    }
}
