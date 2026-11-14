<x-filament-panels::page>

    <div class="space-y-4">

        <x-filament::section icon="heroicon-o-identification" heading="Continue Approved PAN Request">

            <dl class="fynn-dl">

                <div class="fynn-dl-item">

                    <dt>Customer</dt>

                    <dd>{{ $this->customer->customer_name }}</dd>

                </div>

                <div class="fynn-dl-item">

                    <dt>PAN</dt>

                    <dd class="font-mono">{{ $this->customer->pan_number }}</dd>

                </div>

                <div class="fynn-dl-item">

                    <dt>Mobile</dt>

                    <dd class="font-mono">{{ $this->customer->mobile_no }}</dd>

                </div>

            </dl>

        </x-filament::section>

    </div>

</x-filament-panels::page>
