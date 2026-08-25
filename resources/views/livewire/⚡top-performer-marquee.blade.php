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

        // $employee = auth()->user()->employee;
        // $performers = $service->getTopPerformers($employee);
       $service = app(TopPerformerService::class);

        $user = auth()->user();
        $employee = $user->employee;

        $performers = $service->getTopPerformers($employee);

        if (empty($performers)) {
            $this->message = '🏆 No Top Performers Found';
            return;
        }

        if (!$employee) {

            $title = '🏆 Top 5 Callers';

        } else {

            $title = match ($employee->designation) {

                Employee::DESIGNATION_CALLER => '🏆 Top 5 Callers',

                Employee::DESIGNATION_TEAM_LEADER => '🏆 Top 3 Team Leaders',

                Employee::DESIGNATION_MANAGER => '🏆 Top 3 Managers',

                Employee::DESIGNATION_CLUSTER => '🏆 Top 3 Cluster Managers',

                default => '🏆 Top Performers',
            };
        }

        // if (empty($performers)) {
        //     $this->message = '🏆 No Top Performers Found';
        //     return;
        // }

        // $title = match ($employee->designation) {

        //     Employee::DESIGNATION_CALLER => '🏆 Top 5 Callers',

        //     Employee::DESIGNATION_TEAM_LEADER => '🏆 Top 3 Team Leaders',

        //     Employee::DESIGNATION_MANAGER => '🏆 Top 3 Managers',

        //     Employee::DESIGNATION_CLUSTER => '🏆 Top 3 Cluster Managers',

        //     default => '🏆 Top Performers',
        // };

        $messages = [$title];

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

        $this->message = implode('     •     ', $messages);
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
                right: 160px;      /* End before profile area */
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
                animation: marquee 25s linear infinite;
                font-weight: 900;
                font-size: 1rem;
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
