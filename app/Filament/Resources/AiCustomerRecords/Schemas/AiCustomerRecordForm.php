<?php

namespace App\Filament\Resources\AiCustomerRecords\Schemas;

use App\Models\AiCustomerRecord;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiCustomerRecordForm
{
    public static function configure(Schema $schema): Schema
    {
        $record = request()->route('record');

        if ($record instanceof AiCustomerRecord) {
            $model = $record;
        } elseif (is_numeric($record)) {
            $model = AiCustomerRecord::with('schema')->find($record);
        } else {
            $model = null;
        }

        $components = [];
        foreach ($model?->schema?->getFieldDefinitions() ?? [] as $field) {
            $key = (string) ($field['key'] ?? '');
            $label = (string) ($field['label'] ?? $key);
            $type = $field['type'] ?? 'text';

            if ($key === '') {
                continue;
            }

            // $component = match ($type) {
            //     'long_text' => Textarea::make("data.$key"),
            //     'date' => DatePicker::make("data.$key"),
            //     'number', 'decimal' => TextInput::make("data.$key")->numeric(),
            //     'mobile' => TextInput::make("data.$key")->tel(),
            //     'email' => TextInput::make("data.$key")->email(),
            //     default => TextInput::make("data.$key"),
            // };

            // $component->label($label)->required((bool) ($field['required'] ?? false));


            $component = match ($type) {
                'long_text' => Textarea::make("data.$key"),

                // 'date' => DatePicker::make("data.$key")
                //     ->native(false),

                // 'date' => DatePicker::make("data.$key")
                //     ->native(false)
                //     ->format('Y-m-d')
                //     ->displayFormat('d/m/Y')
                //     ->formatStateUsing(function ($state) {
                //         if (blank($state)) {
                //             return null;
                //         }

                //         try {
                //             return \Carbon\Carbon::parse($state)->format('Y-m-d');
                //         } catch (\Throwable) {
                //             return null;
                //         }
                //     })
                //     ->dehydrateStateUsing(function ($state) {
                //         if (blank($state)) {
                //             return null;
                //         }

                //         try {
                //             return \Carbon\Carbon::parse($state)->format('Y-m-d');
                //         } catch (\Throwable) {
                //             return $state;
                //         }
                //     }),

                'date' => DatePicker::make("data.$key")
                    ->native(false)
                    ->format('Y-m-d')
                    ->displayFormat('d/m/Y')
                    ->afterStateHydrated(function ($component, $state) {
                        if (blank($state)) {
                            return;
                        }

                        try {
                            $component->state(
                                \Carbon\Carbon::parse($state)->format('Y-m-d')
                            );
                        } catch (\Throwable) {
                            $component->state(null);
                        }
                    })
                    ->dehydrateStateUsing(function ($state) {
                        if (blank($state)) {
                            return null;
                        }

                        try {
                            return \Carbon\Carbon::parse($state)->format('Y-m-d');
                        } catch (\Throwable) {
                            return null;
                        }
                    }),

                'number', 'decimal' => TextInput::make("data.$key")
                    ->numeric(),

                'mobile' => TextInput::make("data.$key")
                    ->tel()
                    ->regex('/^[6-9][0-9]{9}$/')
                    ->validationMessages([
                        'regex' => 'Enter a valid 10-digit mobile number.',
                    ]),

                'email' => TextInput::make("data.$key")
                    ->email(),

                default => TextInput::make("data.$key"),
            };

            $component
                ->label($label)
                ->required((bool) ($field['required'] ?? false));

            $components[] = $component;
        }

        // dd([
        //     'record' => $model?->id,
        //     'schema' => $model?->schema?->name,
        //     'fields' => $model?->schema?->getFieldDefinitions(),
        // ]);

        return $schema->components([
            Section::make('Customer Data')
                ->description($model?->schema?->name ? 'Configuration: ' . $model->schema->name : null)
                ->schema($components)
                ->columns(2),
        ]);
    }
}
