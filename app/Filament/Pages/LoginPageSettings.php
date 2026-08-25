<?php

namespace App\Filament\Pages;

use App\Models\LoginPageSetting;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * A singleton settings form for the admin login page's banner/branding
 * (App\Models\LoginPageSetting, read by App\Filament\Pages\Auth\Login) —
 * lets an Admin replace the login page's logos and copy without a
 * code change or deploy.
 */
class LoginPageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Login Page';

    protected static ?string $title = 'Login Page Banner';

    protected string $view = 'filament.pages.login-page-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(LoginPageSetting::current()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Left banner')
                    ->description('The dark panel shown beside the sign-in form.')
                    ->schema([
                        FileUpload::make('left_logo_path')
                            ->label('Logo')
                            ->helperText('Leave empty to keep using the default FYNN-ON logo.')
                            ->image()
                            ->disk('public')
                            ->directory('login-page')
                            ->visibility('public')
                            ->preventFilePathTampering()
                            ->columnSpanFull(),

                        Grid::make(2)->schema([
                            TextInput::make('left_heading')
                                ->label('Heading')
                                ->required(),

                            TextInput::make('left_tagline')
                                ->label('Tagline')
                                ->required(),
                        ]),
                    ]),

                Section::make('Right panel')
                    ->description('Shown above the sign-in form itself.')
                    ->schema([
                        FileUpload::make('right_logo_path')
                            ->label('Logo')
                            ->helperText('Leave empty to keep using the default Fynnedge Advisory logo.')
                            ->image()
                            ->disk('public')
                            ->directory('login-page')
                            ->visibility('public')
                            ->preventFilePathTampering()
                            ->columnSpanFull(),

                        TextInput::make('right_tagline')
                            ->label('Tagline')
                            ->required()
                            ->columnSpanFull(),

                        Grid::make(2)->schema([
                            TextInput::make('welcome_heading')
                                ->label('Welcome heading')
                                ->required(),

                            TextInput::make('welcome_subheading')
                                ->label('Welcome subheading')
                                ->required(),
                        ]),

                        TextInput::make('footer_text')
                            ->label('Footer text')
                            ->required()
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->validate();

        LoginPageSetting::current()->update($this->data);

        Notification::make()
            ->title('Login page banner updated')
            ->success()
            ->send();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->check() && auth()->user()->hasRole('Admin');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->hasRole('Admin');
    }
}
