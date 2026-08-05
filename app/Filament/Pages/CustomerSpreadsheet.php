<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use Filament\Pages\Page;
use Livewire\WithPagination;

class CustomerSpreadsheet extends Page
{
    use WithPagination;

    // protected static ?string $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Customer Spreadsheet';

    // protected static ?string $navigationGroup = 'Customers';

    // protected static string $view = 'filament.pages.customer-spreadsheet';
    protected string $view = 'filament.pages.customer-spreadsheet';

    public string $search = '';

    public int $perPage = 50;

    public array $rows = [];

    public function mount()
    {
        $this->loadCustomers();
    }

    public function updatedSearch()
    {
        $this->resetPage();

        $this->loadCustomers();
    }

    public function updatedPage()
    {
        $this->loadCustomers();
    }

    public function loadCustomers()
    {
        $this->rows = Customer::query()

            ->when($this->search, function ($q) {

                $q->where('customer_name', 'like', "%{$this->search}%")
                    ->orWhere('mobile_no', 'like', "%{$this->search}%")
                    ->orWhere('application_no', 'like', "%{$this->search}%");
            })

            ->paginate($this->perPage)

            ->items();
    }

    public function saveRow($id)
    {
        $row = collect($this->rows)->firstWhere('id', $id);

        if (!$row) {
            return;
        }

        Customer::find($id)->update([
            'customer_name' => $row['customer_name'],
            'mobile_no' => $row['mobile_no'],
            'salary' => $row['salary'],
            'approved_loan_amount' => $row['approved_loan_amount'],
            'journey_status' => $row['journey_status'],
        ]);

        $this->dispatch('notify', type: 'success', message: 'Saved');
    }
}
