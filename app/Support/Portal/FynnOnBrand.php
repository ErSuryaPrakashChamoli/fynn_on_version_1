<?php

namespace App\Support\Portal;

use Filament\Support\Colors\Color;

/**
 * The FYNN-ON palette, shared by the Academy and Demo panels.
 *
 * Copied out of AdminPanelProvider::buildColors()'s default branch
 * rather than referenced from it: the admin panel's palette is chosen
 * per-user by its theme switcher cookie, and the portals must look the
 * same for every visitor regardless of what an internal user last
 * picked. Keeping a separate copy also means nothing about the portals
 * can change the admin panel's appearance.
 */
class FynnOnBrand
{
    /**
     * @return array<string, array<int, string>|string>
     */
    public static function colors(): array
    {
        return [
            'primary' => [
                50 => '#F5FBE0',
                100 => '#EAF7C2',
                200 => '#DCF299',
                300 => '#C8E83C',
                400 => '#B7DE1A',
                500 => '#A6D900',
                600 => '#8FBE00',
                700 => '#7FAF00',
                800 => '#5E8200',
                900 => '#3F5700',
                950 => '#223000',
            ],
            'teal' => Color::generatePalette('#C8E83C'),
            'success' => Color::generatePalette('#8FBE00'),
            'warning' => Color::Amber,
            'danger' => Color::Rose,
            'info' => Color::Sky,
            'gray' => [
                50 => '#F7F8F6',
                100 => '#EEF0EC',
                200 => '#DDE1D8',
                300 => '#C6CBC1',
                400 => '#9AA296',
                500 => '#626862',
                600 => '#4D524C',
                700 => '#383C37',
                800 => '#222222',
                900 => '#1B1B1B',
                950 => '#151515',
            ],
        ];
    }
}
