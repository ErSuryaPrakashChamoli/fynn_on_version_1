<?php

namespace App\Filament\Pages;

use App\Models\LoginPageSetting;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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

    /**
     * @var array<string, string>
     */
    protected static array $headingSizeOptions = [
        'sm' => 'Small',
        'md' => 'Medium',
        'lg' => 'Large',
        'xl' => 'Extra large',
    ];

    /**
     * @var array<string, string>
     */
    protected static array $headingAlignOptions = [
        'left' => 'Left',
        'center' => 'Center',
        'right' => 'Right',
    ];

    /**
     * @var array<string, string>
     */
    protected static array $logoSideOptions = [
        'left' => 'Left banner',
        'right' => 'Right panel',
    ];

    /**
     * @var array<string, string>
     */
    protected static array $logoVerticalAlignOptions = [
        'top' => 'Top',
        'middle' => 'Middle',
        'bottom' => 'Bottom',
    ];

    /**
     * @var array<string, string>
     */
    protected static array $logoHorizontalAlignOptions = [
        'left' => 'Left',
        'center' => 'Center',
        'right' => 'Right',
    ];

    public function mount(): void
    {
        $this->form->fill(LoginPageSetting::current()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Left banner')
                    ->description('The panel shown beside the sign-in form.')
                    ->schema([
                        FileUpload::make('left_banner_path')
                            ->label('Custom banner image (optional)')
                            ->helperText('Upload a complete, ready-made banner and it replaces this whole panel exactly as provided (the logo below is still shown as a small overlay on top of it, but the heading/tagline/typography fields below stop applying — they\'re only used when no custom banner is set). Recommended size: 1080 × 1350px (portrait) or taller; it\'s cropped to fill the panel.')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['3:4', '9:16', null])
                            ->disk('public')
                            ->directory('login-page')
                            ->visibility('public')
                            ->live()
                            ->columnSpanFull(),

                        FileUpload::make('left_logo_path')
                            ->label('Logo')
                            ->helperText('Shown over the custom banner above if one is set, or over the default composition otherwise. Leave empty to keep using the default FYNN-ON logo. Recommended size: 480 × 220px.')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['16:9', '4:3', null])
                            ->disk('public')
                            ->directory('login-page')
                            ->visibility('public')
                            ->columnSpanFull(),

                        Grid::make(3)
                            ->schema([
                                Select::make('left_logo_side')
                                    ->label('Logo placement')
                                    ->options(self::$logoSideOptions)
                                    ->required(),

                                Select::make('left_logo_vertical_align')
                                    ->label('Vertical alignment')
                                    ->options(self::$logoVerticalAlignOptions)
                                    ->required(),

                                Select::make('left_logo_horizontal_align')
                                    ->label('Horizontal alignment')
                                    ->options(self::$logoHorizontalAlignOptions)
                                    ->required(),
                            ])
                            ->columnSpanFull(),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('left_heading')
                                    ->label('Heading')
                                    ->required(),

                                TextInput::make('left_tagline')
                                    ->label('Tagline')
                                    ->required(),
                            ])
                            ->visible(fn (Get $get): bool => blank($get('left_banner_path'))),

                        Grid::make(2)
                            ->schema([
                                Select::make('left_heading_size')
                                    ->label('Heading font size')
                                    ->options(self::$headingSizeOptions)
                                    ->required(),

                                Select::make('left_heading_align')
                                    ->label('Heading alignment')
                                    ->options(self::$headingAlignOptions)
                                    ->required(),
                            ])
                            ->visible(fn (Get $get): bool => blank($get('left_banner_path'))),

                        FileUpload::make('right_logo_path')
                            ->label('Company logo')
                            ->helperText('Leave empty to keep using the default Fynnedge Advisory logo. Recommended size: 480 × 480px.')
                            ->image()
                            ->imageEditor()
                            ->imageEditorAspectRatios(['1:1', '4:3', null])
                            ->disk('public')
                            ->directory('login-page')
                            ->visibility('public')
                            ->columnSpanFull(),

                        Grid::make(3)
                            ->schema([
                                Select::make('right_logo_side')
                                    ->label('Logo placement')
                                    ->options(self::$logoSideOptions)
                                    ->required(),

                                Select::make('right_logo_vertical_align')
                                    ->label('Vertical alignment')
                                    ->options(self::$logoVerticalAlignOptions)
                                    ->required(),

                                Select::make('right_logo_horizontal_align')
                                    ->label('Horizontal alignment')
                                    ->options(self::$logoHorizontalAlignOptions)
                                    ->required(),
                            ])
                            ->columnSpanFull(),
                    ]),

                Section::make('Right panel')
                    ->description('Shown above the sign-in form itself.')
                    ->schema([
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

                        Grid::make(2)
                            ->schema([
                                Select::make('welcome_heading_size')
                                    ->label('Welcome heading font size')
                                    ->options(self::$headingSizeOptions)
                                    ->helperText('Shrink this if a longer heading is wrapping awkwardly.')
                                    ->required(),

                                Select::make('welcome_heading_align')
                                    ->label('Welcome heading alignment')
                                    ->options(self::$headingAlignOptions)
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
        // `$this->form->getState()` (not the raw `$this->data` property) is
        // what runs validation *and* dehydrates FileUpload's internal
        // Livewire upload-tracking state into a plain storable path —
        // reading `$this->data` directly skipped that and was saving the
        // tracker's raw internal structure instead of the uploaded file's
        // path (visible as literal `{"<uuid>":{}}` strings in the database).
        $data = $this->form->getState();

        LoginPageSetting::current()->update($data);

        Notification::make()
            ->title('Login page banner updated')
            ->success()
            ->send();

        $this->redirect(Dashboard::getUrl());
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
