<?php
use Livewire\Component;
use App\Services\TopPerformerService;
use App\Models\Employee;

new class extends Component
{
    public string $message = '';
    public bool $readyToLoad = false; // Add a flag to delay calculation



    public function mount(TopPerformerService $service)
    {

         $this->loadPerformers($service);
    }


    public function loadPerformers(TopPerformerService $service){

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
                <div class="ticker-container">
                    <div class="marquee-text">
                        <span>{{ $message }}</span>
                        <span class="ml-24">{{ $message }}</span>
                    </div>
                </div>
            </div>

            <!-- Your CSS -->


                        <style>
            .fi-top-marquee-wrapper {
                position: absolute;
                left: 260px;       /* Start after Filament logo/sidebar area */
                right: 380px;      /* End before profile area — widened from 160px to also clear the global month selector (two <select>s, ~220px) now sitting left of the notification bell; fine-tune visually if it still overlaps at your topbar font/zoom. */
                top: 50%;
                transform: translateY(-50%);
                overflow: hidden;
                z-index: 10;
            }

            .ticker-container {
                width: 100%;
                overflow: hidden;
            }

            .marquee-text {
                display: inline-flex;
                white-space: nowrap;
                animation: marquee 160s linear infinite;
                font-weight: 900;
                font-size: 0.8rem;
                color: #ffffff;
                text-shadow: 0 0 10px rgb(45 212 191 / 60%), 0 1px 2px rgb(0 0 0 / 50%);
            }

            .marquee-text:hover {
                animation-play-state: paused;
            }

            @keyframes marquee {
                from {
                    transform: translateX(100%);
                }
                to {
                    transform: translateX(-100%);
                }
            }
            </style>


        HTML;
    }



};

?>
