<x-filament-panels::page>

    <div class="space-y-4">

        <x-filament::section>

            <h2 class="text-lg font-bold">
                Continue Approved PAN Request
            </h2>

            <div>

                <strong>Customer</strong>

                {{ $this->customer->customer_name }}

            </div>

            <div>

                <strong>PAN</strong>

                {{ $this->customer->pan_number }}

            </div>

            <div>

                <strong>Mobile</strong>

                {{ $this->customer->mobile_no }}

            </div>

        </x-filament::section>

    </div>

</x-filament-panels::page>
