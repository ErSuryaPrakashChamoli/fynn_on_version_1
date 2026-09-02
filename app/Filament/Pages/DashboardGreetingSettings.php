<?php

namespace App\Filament\Pages;

use App\Models\DashboardGreetingSetting;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * A singleton settings form for the dashboard greeting banner's tagline
 * (App\Models\DashboardGreetingSetting, read by
 * resources/views/filament/components/dashboard-greeting.blade.php) — lets
 * an Admin refresh the "thought of the day" shown to everyone without a
 * code change or deploy.
 */
class DashboardGreetingSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static ?string $navigationLabel = 'Dashboard Greeting';

    protected static ?string $title = 'Dashboard Greeting';

    protected string $view = 'filament.pages.dashboard-greeting-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(DashboardGreetingSetting::current()->toArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tagline')
                    ->description("Shown under the \"Good Morning/Afternoon/Evening\" greeting on everyone's Dashboard.")
                    ->schema([
                        TextInput::make('tagline')
                            ->label('Tagline')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Select::make('icon')
                            ->label('Icon')
                            ->options(self::iconOptions())
                            ->allowHtml()
                            ->searchable()
                            ->optionsLimit(500)
                            ->required()
                            ->helperText('Heroicons recolor themselves to match whichever theme (Classic/Emerald/FYNN-ON) is active; emoji always keep their own built-in colors.')
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Grouped Select options: every outlined Heroicon this app ships with
     * (value stored as the full "heroicon-o-{slug}" string the Blade icon
     * component expects), plus a broad curated set of common emoji (value
     * stored as the raw character). The dashboard-greeting view tells the
     * two apart by the "heroicon-" prefix. Labels are HTML (see
     * ->allowHtml() above) so each option shows its actual glyph — an
     * inlined SVG for Heroicons, the emoji character itself for emoji —
     * next to its name, so an admin can tell options apart at a glance
     * instead of guessing from the name alone.
     *
     * @return array<string, array<string, string>>
     */
    protected static function iconOptions(): array
    {
        $heroicons = collect(Heroicon::cases())
            ->filter(fn (Heroicon $icon): bool => str_starts_with($icon->value, 'o-'))
            ->map(fn (Heroicon $icon): array => ['value' => "heroicon-{$icon->value}", 'name' => Str::headline(str_replace('Outlined', '', $icon->name))])
            ->sortBy('name')
            ->mapWithKeys(fn (array $icon): array => [
                $icon['value'] => svg($icon['value'], 'w-4 h-4 inline-block align-text-bottom me-1')->toHtml().e($icon['name']),
            ])
            ->all();

        $emoji = [
            '🚀' => '🚀 Rocket', '✨' => '✨ Sparkles', '🌟' => '🌟 Glowing Star', '⭐' => '⭐ Star',
            '🔥' => '🔥 Fire', '💪' => '💪 Flexed Biceps', '🏆' => '🏆 Trophy', '🎯' => '🎯 Direct Hit',
            '🎉' => '🎉 Party Popper', '🎊' => '🎊 Confetti Ball', '👍' => '👍 Thumbs Up', '👏' => '👏 Clapping Hands',
            '🙌' => '🙌 Raised Hands', '💯' => '💯 Hundred Points', '⚡' => '⚡ High Voltage', '💥' => '💥 Collision',
            '🌈' => '🌈 Rainbow', '☀️' => '☀️ Sun', '🌤️' => '🌤️ Sun Behind Cloud', '🌙' => '🌙 Crescent Moon',
            '🌻' => '🌻 Sunflower', '🌱' => '🌱 Seedling', '🍀' => '🍀 Four Leaf Clover', '🌊' => '🌊 Wave',
            '😀' => '😀 Grinning Face', '😃' => '😃 Smiling Face', '😄' => '😄 Beaming Face', '😁' => '😁 Grinning Face With Smiling Eyes',
            '😊' => '😊 Smiling Face With Smiling Eyes', '🙂' => '🙂 Slightly Smiling Face', '😎' => '😎 Smiling Face With Sunglasses', '🤩' => '🤩 Star Struck',
            '🥳' => '🥳 Partying Face', '😇' => '😇 Smiling Face With Halo', '🤗' => '🤗 Hugging Face', '☕' => '☕ Hot Beverage',
            '💡' => '💡 Light Bulb', '📈' => '📈 Chart Increasing', '🏁' => '🏁 Chequered Flag', '🥇' => '🥇 First Place Medal',
            '🎖️' => '🎖️ Military Medal', '🛡️' => '🛡️ Shield', '⏰' => '⏰ Alarm Clock', '🧭' => '🧭 Compass',
            '🗝️' => '🗝️ Old Key', '🔑' => '🔑 Key', '📌' => '📌 Pushpin', '🎈' => '🎈 Balloon',
            '❤️' => '❤️ Red Heart', '💚' => '💚 Green Heart', '💙' => '💙 Blue Heart', '🧡' => '🧡 Orange Heart',
        ];

        return [
            'Heroicons' => $heroicons,
            'Emoji' => $emoji,
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        DashboardGreetingSetting::current()->update($data);

        Notification::make()
            ->title('Dashboard greeting updated')
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
