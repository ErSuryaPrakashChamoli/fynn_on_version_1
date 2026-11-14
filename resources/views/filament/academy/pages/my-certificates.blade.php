<x-filament-panels::page class="academy-page">
    @php($certificates = $this->getCertificates())

    @if ($certificates->isEmpty())
        <div class="academy-empty">
            <span class="academy-empty__icon"><x-filament::icon icon="heroicon-o-trophy" /></span>
            <span>
                You have not earned a certificate yet. Complete every lesson in a course and your
                trainer will approve it.
            </span>
        </div>
    @else
        <div class="academy-grid academy-grid--wide">
            @foreach ($certificates as $certificate)
                {{--
                    A print-friendly certificate face. Deliberately
                    plain HTML rather than a generated PDF: the
                    browser's own "print to PDF" gives the same
                    artefact without adding a PDF dependency to the
                    project, and file_path on the model is there for
                    when a rendered file is wanted later.
                --}}
                <article class="academy-certificate">
                    <div class="academy-certificate__face">
                        <span class="academy-certificate__seal"><x-filament::icon icon="heroicon-o-check-badge" /></span>

                        <p class="academy-certificate__kicker">
                            Certificate of Completion
                        </p>

                        <p class="academy-certificate__small">This certifies that</p>

                        <p class="academy-certificate__name">
                            {{ $certificate->trainee?->name }}
                        </p>

                        <p class="academy-certificate__small academy-certificate__small--tight">has completed</p>

                        <p class="academy-certificate__course">
                            {{ $certificate->course?->title }}
                        </p>

                        <p class="academy-certificate__score">
                            Final score <strong>{{ $certificate->final_score }}%</strong>
                        </p>

                        <dl class="academy-certificate__meta">
                            <div>
                                <dt>Certificate No.</dt>
                                <dd class="academy-mono">
                                    {{ $certificate->certificate_number }}
                                </dd>
                            </div>
                            <div>
                                <dt>Issued</dt>
                                <dd>
                                    {{ $certificate->issued_on?->format('d M Y') }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </article>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
