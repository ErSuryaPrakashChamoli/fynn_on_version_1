<x-filament-panels::page>
    @php($certificates = $this->getCertificates())

    @if ($certificates->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                You have not earned a certificate yet. Complete every lesson in a course and your
                trainer will approve it.
            </p>
        </x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($certificates as $certificate)
                <x-filament::section>
                    {{--
                        A print-friendly certificate face. Deliberately
                        plain HTML rather than a generated PDF: the
                        browser's own "print to PDF" gives the same
                        artefact without adding a PDF dependency to the
                        project, and file_path on the model is there for
                        when a rendered file is wanted later.
                    --}}
                    <div class="rounded-xl border-2 border-dashed border-primary-300 p-6 text-center dark:border-primary-700">
                        <p class="text-xs font-semibold uppercase tracking-widest text-primary-600 dark:text-primary-400">
                            Certificate of Completion
                        </p>

                        <p class="mt-4 text-xs uppercase tracking-wide text-gray-400">This certifies that</p>

                        <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">
                            {{ $certificate->trainee?->name }}
                        </p>

                        <p class="mt-3 text-xs uppercase tracking-wide text-gray-400">has completed</p>

                        <p class="mt-1 text-base font-medium text-gray-800 dark:text-gray-200">
                            {{ $certificate->course?->title }}
                        </p>

                        <p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                            Final score <span class="font-semibold">{{ $certificate->final_score }}%</span>
                        </p>

                        <dl class="mt-5 flex items-center justify-center gap-8 text-xs text-gray-500 dark:text-gray-400">
                            <div>
                                <dt class="uppercase tracking-wide">Certificate No.</dt>
                                <dd class="mt-0.5 font-mono font-medium text-gray-700 dark:text-gray-300">
                                    {{ $certificate->certificate_number }}
                                </dd>
                            </div>
                            <div>
                                <dt class="uppercase tracking-wide">Issued</dt>
                                <dd class="mt-0.5 font-medium text-gray-700 dark:text-gray-300">
                                    {{ $certificate->issued_on?->format('d M Y') }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
