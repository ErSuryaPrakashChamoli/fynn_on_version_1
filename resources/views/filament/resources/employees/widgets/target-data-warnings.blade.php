<x-filament-widgets::widget>
    @if ($missing !== [] || $broken !== [])
        <x-filament::section
            icon="heroicon-o-exclamation-triangle"
            icon-color="warning"
            heading="Target data to fix — {{ $month }}"
            description="These people make this month's targets wrong until they are fixed."
            collapsible
            collapsed
        >
            <div class="space-y-4 text-sm">
                @if ($missing !== [])
                    <div>
                        <p class="font-semibold text-gray-950 dark:text-white">
                            {{ count($missing) }} {{ \Illuminate\Support\Str::plural('caller', count($missing)) }} without a Target Category
                        </p>
                        <p class="text-gray-500 dark:text-gray-400">Their target counts as ₹0 until a category with a monthly amount is set (Change Position / Target).</p>
                        <ul class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($missing as $person)
                                <li><a href="{{ $person['url'] }}" class="fynn-chip">{{ $person['name'] }}{{ $person['emp_id'] ? ' · '.$person['emp_id'] : '' }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($broken !== [])
                    <div>
                        <p class="font-semibold text-gray-950 dark:text-white">
                            {{ count($broken) }} {{ \Illuminate\Support\Str::plural('person', count($broken)) }} with a broken reporting line
                        </p>
                        <p class="text-gray-500 dark:text-gray-400">Every boss on their record has left on some day this month, so for those days their target counts only in the company total. Give them a boss who is on the rolls.</p>
                        <ul class="mt-1.5 flex flex-wrap gap-1.5">
                            @foreach ($broken as $person)
                                <li><a href="{{ $person['url'] }}" class="fynn-chip">{{ $person['name'] }}{{ $person['emp_id'] ? ' · '.$person['emp_id'] : '' }}</a></li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
