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
        /*
         * Filament rebuilds this schema on every Livewire round-trip, not
         * just the initial page load — including the AJAX "save" request,
         * which is POSTed to Livewire's own generic update endpoint rather
         * than this resource's /{record}/edit URL. request()->route('record')
         * only resolves on the original page request, so reading the record
         * from the request's route silently produced zero form fields (and
         * therefore nothing to save) on every actual save. The owning
         * Livewire page component's own record — restored from Livewire's
         * snapshot on every request, independent of the current URL — is
         * the reliable source here.
         */
        $livewire = $schema->getLivewire();
        $record = method_exists($livewire, 'getRecord') ? $livewire->getRecord() : null;

        $model = $record instanceof AiCustomerRecord ? $record : null;
        $model?->loadMissing('schema');

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

            /*
             * This form is a correction/review step for OCR-extracted data,
             * not the final record — a row can legitimately have a blank
             * required field (e.g. OCR never found a mobile number) that
             * the reviewer is still in the process of fixing one field at
             * a time. Hard-enforcing required() here blocks saving ANY
             * field whenever another required field is still blank, which
             * silently prevents edits from persisting. Actual
             * required-field completeness is already enforced separately
             * before a record can be approved (see the "approve" header
             * action), so here we only show the asterisk, not a hard rule.
             */
            $component
                ->label($label)
                ->markAsRequired((bool) ($field['required'] ?? false));

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
