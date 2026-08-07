<?php

namespace App\Filament\Resources\Cities\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Http;
use App\Models\City;
use Filament\Support\Icons;
use Filament\Support\Icons\Heroicon;

class CityForm
{
    public static function configure(Schema $schema): Schema
    {


        // $city = City::where('city', $office['District'])
        //     ->where('state', $office['State'])
        //     ->first();



        // if (!$city) {

        //     $city = City::create([
        //         'country' => 'India',
        //         'state' => $office['State'],
        //         'city' => $office['District'],
        //     ]);
        // }



        return $schema
            ->components([
                //

                TextInput::make('country')
                    ->required()
                    ->default('India')
                    ->maxLength(255),

                // TextInput::make('state_code')
                //     ->label('State Code')
                //     ->maxLength(10),

                // TextInput::make('city_code')
                //     ->label('City Code')
                //     ->maxLength(10),


                TextInput::make('pincode')
                    ->label('PIN Code')
                    ->numeric()
                    ->maxLength(6)
                    ->live(debounce: 700)
                    ->suffixIcon('heroicon-o-arrow-path')
                    // ->suffix(
                    //     Heroicon::OutlinedArrowPath
                    // )
                    ->extraAttributes([
                        'wire:loading.class' => 'animate-spin',
                        'wire:target' => 'data.pincode',
                    ])



                    // ->afterStateUpdated(function ($state, callable $set) {

                    //     if (strlen($state) != 6) {
                    //         return;
                    //     }

                    //     $url = "https://api.postalpincode.in/pincode/{$state}";

                    //     $ch = curl_init();

                    //     curl_setopt_array($ch, [
                    //         CURLOPT_URL => $url,
                    //         CURLOPT_RETURNTRANSFER => true,
                    //         CURLOPT_FOLLOWLOCATION => true,
                    //         CURLOPT_TIMEOUT => 15,
                    //         CURLOPT_SSL_VERIFYPEER => false, // remove in production if possible
                    //         CURLOPT_USERAGENT => 'FYNN-ON/1.0',
                    //     ]);

                    //     $result = curl_exec($ch);



                    //     if (curl_errno($ch)) {

                    //         \Filament\Notifications\Notification::make()
                    //             ->title('Curl Error')
                    //             ->body(curl_error($ch))
                    //             ->danger()
                    //             ->send();

                    //         curl_close($ch);
                    //         return;
                    //     }

                    //     curl_close($ch);

                    //     $data = json_decode($result, true);

                    //     if (
                    //         isset($data[0]['Status']) &&
                    //         $data[0]['Status'] === 'Success' &&
                    //         ! empty($data[0]['PostOffice'])
                    //     ) {

                    //         $office = $data[0]['PostOffice'][0];

                    //         $set('city', $office['District']);
                    //         $set('state', $office['State']);
                    //     } else {

                    //         $set('city', null);
                    //         $set('state', null);

                    //         \Filament\Notifications\Notification::make()
                    //             ->title('Invalid PIN Code')
                    //             ->danger()
                    //             ->send();
                    //     }
                    // })

                    ->afterStateUpdated(function ($state, callable $set) {

                        if (strlen($state) != 6) {
                            return;
                        }

                        // 1. Search in database first
                        $city = City::where('pincode', $state)->first();

                        if ($city) {

                            $set('city', $city->city);
                            $set('state', $city->state);

                            return;
                        }

                        // 2. Not found, call API
                        $url = "https://api.postalpincode.in/pincode/{$state}";

                        $ch = curl_init();

                        curl_setopt_array($ch, [
                            CURLOPT_URL => $url,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_TIMEOUT => 15,
                            CURLOPT_SSL_VERIFYPEER => false,
                        ]);

                        $result = curl_exec($ch);

                        if (curl_errno($ch)) {

                            curl_close($ch);

                            \Filament\Notifications\Notification::make()
                                ->title('Unable to fetch PIN Code')
                                ->danger()
                                ->send();

                            return;
                        }

                        curl_close($ch);

                        $data = json_decode($result, true);

                        if (
                            isset($data[0]['Status']) &&
                            $data[0]['Status'] === 'Success' &&
                            ! empty($data[0]['PostOffice'])
                        ) {

                            $office = $data[0]['PostOffice'][0];

                            // Fill form
                            $set('city', $office['District']);
                            $set('state', $office['State']);

                            // Save for next time
                            // City::create([
                            //     'country' => 'India',
                            //     'state' => $office['State'],
                            //     'city' => $office['District'],
                            //     'pincode' => $state,
                            //     'is_active' => true,
                            // ]);

                            City::firstOrCreate(
                                [
                                    'state' => $office['State'],
                                    'city'  => $office['District'],
                                ],
                                [
                                    'country'  => 'India',
                                    'pincode'  => $state,
                                    'is_active' => true,
                                ]
                            );
                        } else {

                            $set('city', null);
                            $set('state', null);

                            \Filament\Notifications\Notification::make()
                                ->title('Invalid PIN Code')
                                ->danger()
                                ->send();
                        }
                    }),


                TextInput::make('city')
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('state')
                    ->readOnly()
                    ->dehydrated(),

                TextInput::make('is_active')
                    ->label('Active')
                    ->default(true),


            ]);
    }
}
