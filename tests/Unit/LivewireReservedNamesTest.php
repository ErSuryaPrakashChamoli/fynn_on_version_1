<?php

namespace Tests\Unit;

use Livewire\Component;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Livewire's $wire object resolves a fixed list of aliases BEFORE it looks
 * at the component's own state, so a public method or property named after
 * one of them is unreachable from the browser: wire:click="commit" calls
 * Livewire's internal $commit (flush pending updates), comes back 200 with
 * the component re-rendered and unchanged, and the method never runs.
 *
 * There is no error and no failing request, and Livewire::test()->call()
 * goes straight to the method rather than through $wire, so nothing else in
 * this suite can see it. This test is the only thing standing between us and
 * another silent dead button.
 *
 * The list is the `aliases` map in livewire/livewire/dist/livewire.js.
 */
class LivewireReservedNamesTest extends TestCase
{
    /** @var array<int, string> */
    private const RESERVED = [
        'on', 'el', 'id', 'js', 'get', 'set', 'refs', 'call', 'hook', 'watch',
        'dirty', 'effect', 'commit', 'errors', 'island', 'upload', 'entangle',
        'dispatch', 'intercept', 'interceptAction', 'interceptMessage',
        'interceptRequest', 'dispatchTo', 'dispatchSelf', 'dispatchEl',
        'dispatchRef', 'removeUpload', 'cancelUpload', 'uploadMultiple',
    ];

    public function test_no_livewire_component_exposes_a_name_the_wire_object_has_taken(): void
    {
        $clashes = [];

        foreach ($this->componentClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Component::class)) {
                continue;
            }

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if (in_array($method->getName(), self::RESERVED, true)) {
                    $clashes[] = "{$class}::{$method->getName()}()";
                }
            }

            foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                if ($property->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                if (in_array($property->getName(), self::RESERVED, true)) {
                    $clashes[] = "{$class}::\${$property->getName()}";
                }
            }
        }

        $this->assertSame([], $clashes, implode("\n", [
            'These are shadowed by Livewire\'s $wire aliases and can never be reached from the browser.',
            'Rename them (giveCommitment, not commit):',
            ...$clashes,
        ]));
    }

    /**
     * @return array<int, class-string>
     */
    private function componentClasses(): array
    {
        $classes = [];

        $finder = (new Finder)
            ->files()
            ->in([app_path('Livewire'), app_path('Filament')])
            ->name('*.php');

        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            $class = 'App\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                ltrim(str_replace(app_path(), '', $file->getRealPath()), '/')
            );

            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
