<?php
use Livewire\Component;
use App\Services\TopPerformerService;
use App\Models\Employee;

new class extends Component
{
    public string $message = '';

    /**
     * Seconds for the ticker to scroll one copy of the message — scaled to
     * its length so a long leaderboard moves at the same readable speed as
     * a short one. See render().
     */
    public int $duration = 30;
    public bool $readyToLoad = false; // Add a flag to delay calculation



    public function mount(TopPerformerService $service)
    {

         $this->loadPerformers($service);
    }


    public function loadPerformers(TopPerformerService $service){

        $this->buildMessage($service);

        $this->duration = max(20, (int) ceil(mb_strlen($this->message) / 8));
    }

    private function buildMessage(TopPerformerService $service): void
    {

        $user = auth()->user();
        $employee = $user->employee;

        $performers = $service->getTopPerformers($employee);

        // Labeled explicitly because this can be the prior month rather
        // than the real current one — see
        // AchievementCalculatorService::resolveReferenceMonth() — a new
        // calendar month starts with zero disbursals until loans already
        // in the pipeline actually clear, so figures fall back to the last
        // month that has data instead of showing a wall of 0%.
        $monthLabel = '📅 '.$service->getReferenceMonth()->format('F Y');

        if (empty($performers)) {
            $this->message = "{$monthLabel}     •     🏆 No Top Performers Found";
            return;
        }

        if (!$employee) {

            $this->message = "{$monthLabel}     •     ".$this->buildAdminMessage($performers);

            return;
        }

        $title = match ($employee->designation) {

            Employee::DESIGNATION_CALLER => '🏆 Top 5 Callers',

            Employee::DESIGNATION_TEAM_LEADER => '🏆 Top 3 Team Leaders',

            Employee::DESIGNATION_MANAGER => '🏆 Top 3 Managers',

            Employee::DESIGNATION_CLUSTER => '🏆 Top 3 Cluster Managers',

            default => '🏆 Top Performers',
        };

        $this->message = implode('     •     ', [$monthLabel, $title, ...$this->formatPerformers($performers)]);
    }

    /**
     * Admin sees a combined leaderboard (Top 5 Callers, Top 5 Team Leaders,
     * Top 2 Managers), each ranked with its own 🥇🥈🥉 medals rather than
     * one ranking spanning all three groups.
     */
    private function buildAdminMessage(array $performers): string
    {
        $sections = [
            Employee::DESIGNATION_CALLER => '🏆 Top 5 Callers',
            Employee::DESIGNATION_TEAM_LEADER => '🏆 Top 5 Team Leaders',
            Employee::DESIGNATION_MANAGER => '🏆 Top 2 Managers',
        ];

        $messages = [];

        foreach ($sections as $designation => $title) {

            $group = array_values(array_filter(
                $performers,
                fn ($performer) => $performer['designation'] === $designation
            ));

            if (empty($group)) {
                continue;
            }

            $messages[] = $title;
            array_push($messages, ...$this->formatPerformers($group));
        }

        return implode('     •     ', $messages);
    }

    /**
     * @return array<int, string>
     */
    private function formatPerformers(array $performers): array
    {
        $messages = [];

        foreach ($performers as $index => $top) {

            $rank = match ($index) {
                0 => '🥇',
                1 => '🥈',
                2 => '🥉',
                default => '🏅',
            };

            $messages[] = "{$rank} {$top['name']} | "
                . number_format($top['percentage'], 2)
                . "%";
        }

        return $messages;
    }


    // public function render(){

    //   return <<<'HTML'
    //         <div class="fi-top-marquee-wrapper">
    //             <div class="ticker-container">
    //                 <div class="marquee-text">
    //                     <span>{{ $message }}</span>
    //                     <span class="ml-24" aria-hidden="true">{{ $message }}</span>
    //                 </div>
    //             </div>
    //         </div>

            // <style>
            // .fi-top-marquee-wrapper {
            //     position: absolute;
            //     left: 260px;       /* Start after Filament logo/sidebar area */
            //     right: 160px;      /* End before profile area */
            //     top: 50%;
            //     transform: translateY(-50%);
            //     overflow: hidden;
            //     z-index: 10;
            // }

            // .ticker-container {
            //     width: 100%;
            //     overflow: hidden;
            // }

            // .marquee-text {
            //     display: inline-flex;
            //     white-space: nowrap;
            //     animation: marquee 25s linear infinite;
            //     font-weight: 900;
            //     font-size: 1rem;
            //     color: #ae2012;
            // }

            // .marquee-text:hover {
            //     animation-play-state: paused;
            // }

            // @keyframes marquee {
            //     from {
            //         transform: translateX(100%);
            //     }
            //     to {
            //         transform: translateX(-100%);
            //     }
            // }
            // </style>
    //         HTML;

    //         }


       public function render()
    {
        return <<<'HTML'
            <div wire:poll.60s="loadPerformers" class="fi-top-marquee-wrapper">
                {{-- Circular ticker. The server renders two copies scrolled
                     by -50% so the text moves from the first frame even
                     without JS; Alpine then clones the copy until the track
                     spans at least two strip widths (an even count, so -50%
                     is still a whole number of copies) and re-times the
                     animation at a constant px/s. Without that, a message
                     shorter than the strip left a blank stretch behind the
                     second copy before it wrapped. wire:ignore keeps the
                     clones across polls; the wire:key on the message hash
                     replaces the whole track when the text changes. --}}
                <div
                    class="ticker-container"
                    wire:ignore
                    wire:key="marquee-{{ md5($message) }}"
                    x-data="{
                        speed: 60,
                        build() {
                            const track = $refs.track;
                            const copy = $refs.copy;
                            track.querySelectorAll('[data-marquee-clone]').forEach((clone) => clone.remove());
                            const copyWidth = copy.getBoundingClientRect().width;
                            const stripWidth = $el.clientWidth;
                            if (! copyWidth || ! stripWidth) {
                                return;
                            }
                            let count = Math.max(2, Math.ceil(stripWidth / copyWidth) * 2);
                            if (count % 2) {
                                count++;
                            }
                            for (let i = 1; i < count; i++) {
                                const clone = copy.cloneNode(true);
                                clone.setAttribute('aria-hidden', 'true');
                                clone.setAttribute('data-marquee-clone', '');
                                track.appendChild(clone);
                            }
                            track.style.animation = 'none';
                            void track.offsetWidth;
                            track.style.animation = '';
                            track.style.animationDuration = ((copyWidth * count) / 2 / this.speed) + 's';
                        },
                    }"
                    x-init="
                        build();
                        if (window.ResizeObserver) {
                            let last = $el.clientWidth;
                            new ResizeObserver(() => {
                                if ($el.clientWidth !== last) {
                                    last = $el.clientWidth;
                                    build();
                                }
                            }).observe($el);
                        }
                    "
                >
                    <div
                        class="marquee-text"
                        style="animation-duration: {{ $duration }}s"
                        x-ref="track"
                    >
                        {{-- Segments coloured by kind so the headings and the
                             month stand apart from the names: 📅 month (sky
                             blue), 🏆 section title (gold), the rest names.
                             build() clones this copy, so clones keep it. --}}
                        <span class="marquee-copy" x-ref="copy">@foreach (explode('     •     ', $message) as $segment)@if (! $loop->first)<span class="marquee-sep">&nbsp;&nbsp;•&nbsp;&nbsp;</span>@endif<span @class(['marquee-month' => str_starts_with($segment, '📅'), 'marquee-title' => str_starts_with($segment, '🏆'), 'marquee-name' => ! str_starts_with($segment, '📅') && ! str_starts_with($segment, '🏆')])>{{ $segment }}</span>@endforeach</span>
                        <span class="marquee-copy" aria-hidden="true" data-marquee-clone>@foreach (explode('     •     ', $message) as $segment)@if (! $loop->first)<span class="marquee-sep">&nbsp;&nbsp;•&nbsp;&nbsp;</span>@endif<span @class(['marquee-month' => str_starts_with($segment, '📅'), 'marquee-title' => str_starts_with($segment, '🏆'), 'marquee-name' => ! str_starts_with($segment, '📅') && ! str_starts_with($segment, '🏆')])>{{ $segment }}</span>@endforeach</span>
                    </div>
                </div>
            </div>

            <!-- Your CSS -->


                        <style>
            /* Fills the full-width strip under the topbar (.fynn-marquee-strip,
               rendered by AdminPanelProvider's TOPBAR_AFTER hook and styled in
               theme.css). It used to sit inside the topbar, absolutely
               positioned with a hard-coded right edge that the period
               selector outgrew. */
            .fi-top-marquee-wrapper {
                position: relative;
                width: 100%;
                overflow: hidden;
            }

            .ticker-container {
                width: 100%;
                overflow: hidden;
            }

            .marquee-text {
                display: inline-flex;
                white-space: nowrap;
                will-change: transform;
                animation: marquee 60s linear infinite;
                font-weight: 900;
                font-size: 0.8rem;
                color: #ffffff;
                text-shadow: 0 0 10px rgb(45 212 191 / 60%), 0 1px 2px rgb(0 0 0 / 50%);
            }

            .marquee-copy {
                padding-right: 6rem;
            }

            /* The month: sky blue, so it reads as the period, not a name. */
            .marquee-month {
                color: #7dd3fc;
                text-shadow: 0 0 10px rgb(56 189 248 / 55%), 0 1px 2px rgb(0 0 0 / 50%);
            }

            /* Group headings (the trophy segments): gold capitals, so each
               leaderboard group's start stands out from its names. */
            .marquee-title {
                color: #fbbf24;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                text-shadow: 0 0 10px rgb(245 158 11 / 55%), 0 1px 2px rgb(0 0 0 / 50%);
            }

            .marquee-name {
                color: #ffffff;
            }

            .marquee-sep {
                color: rgb(148 163 184);
                text-shadow: none;
            }

            .marquee-text:hover {
                animation-play-state: paused;
            }

            @keyframes marquee {
                from {
                    transform: translateX(0);
                }
                to {
                    transform: translateX(-50%);
                }
            }
            </style>


        HTML;
    }



};

?>
