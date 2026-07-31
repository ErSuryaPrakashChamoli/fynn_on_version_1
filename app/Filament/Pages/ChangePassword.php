<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Filament\Support\Icons\Heroicon;
use BackedEnum;

class ChangePassword extends Page
{
    // protected static ?string $navigationIcon = 'heroicon-o-key';
    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?string $navigationLabel = 'Change Password';

    protected static ?string $title = 'Change Password';

    protected string $view = 'filament.pages.change-password';

    public ?array $data = [];

    public function mount(): void
    {
        abort_unless(
            auth()->user()->hasAnyRole(['Caller', 'Team Leader', 'Manager','Cluster Manager','Admin']),
            403
        );

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([

                TextInput::make('current_password')
                    ->label('Current Password')
                    ->password()
                    ->revealable()
                    ->required(),

                TextInput::make('password')
                    ->label('New Password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->minLength(8)
                    ->confirmed(),

                TextInput::make('password_confirmation')
                    ->label('Confirm Password')
                    ->password()
                    ->revealable()
                    ->required(),

            ])
            ->statePath('data');
    }

    public function changePassword(): void
    {
        $this->validate();

        $user = auth()->user();

        $user->update([
            'password' => Hash::make($this->data['password']),
        ]);

        $this->form->fill();

        Notification::make()
            ->title('Password changed successfully')
            ->success()
            ->send();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check()
            && auth()->user()->hasAnyRole([
                'Caller',
                'Team Leader',
                'Manager',
                'Cluster Manager',
                'Admin'
            ]);
    }
}
