<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Illuminate\Support\Arr;

/**
 * Re-opens the action that is running, so its pop-up stays on screen and is
 * rebuilt from the database — used by the inactivation pop-ups to move
 * from "clear what still depends on it" to "pick the effective date".
 *
 * Filament keeps a pop-up open only when the running action was replaced
 * by one with a different name or context, so the context carries a pass
 * counter. Page and table actions both ignore the extra context key.
 */
final class ReopenAction
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function for(Action $action, array $arguments = []): void
    {
        $livewire = $action->getLivewire();
        $context = Arr::last($livewire->mountedActions ?? [])['context'] ?? [];

        $livewire->replaceMountedAction($action->getName(), $arguments, [
            ...$context,
            'pass' => ($context['pass'] ?? 0) + 1,
        ]);
    }
}
