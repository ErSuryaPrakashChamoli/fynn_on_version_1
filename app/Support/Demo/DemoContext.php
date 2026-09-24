<?php

namespace App\Support\Demo;

use Closure;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Facade;
use Laravel\Telescope\Telescope;
use Spatie\Permission\PermissionRegistrar;

/**
 * The single switch that turns a request (or a console process) into the
 * /demo environment.
 *
 * /demo runs the SAME resources, pages, widgets, services and models as
 * /admin. What makes it a separate environment is only this runtime
 * context: while it is active every Laravel service that stores or reads
 * business data resolves to the demo side —
 *
 *  - database   default connection → `demo` (the demo database)
 *  - queue      default connection → `demo` (jobs table on the demo DB),
 *               plus job batches and failed jobs on the demo DB
 *  - cache      own file path / prefix / DB connection, and the Spatie
 *               permission cache under its own key
 *  - storage    `local` and `public` disks rooted under a demo/ folder
 *  - mail       the log mailer — a demo never emails anyone
 *  - telescope  stops recording, so no demo payload lands in main tables
 *
 * The session store is pinned to the MAIN connection instead: the same
 * browser session is started before this switch on Livewire round-trips,
 * so it has to live in one place. It holds no business data.
 *
 * DemoDatabase::assertIsolated() runs first on every activation, so the
 * context can never be switched on against a demo connection that points
 * at the main database.
 *
 * The state lives in the config repository rather than a static, so it
 * belongs to one application instance and cannot leak across tests.
 */
class DemoContext
{
    protected const STATE_KEY = 'demo.runtime';

    public static function isActive(): bool
    {
        return (bool) config(self::STATE_KEY.'.active', false);
    }

    public static function activate(): void
    {
        if (static::isActive()) {
            return;
        }

        DemoDatabase::assertIsolated();

        $overrides = static::overrides();

        config([
            self::STATE_KEY => [
                'active' => true,
                'previous' => collect(array_keys($overrides))
                    ->mapWithKeys(fn (string $key): array => [$key => config($key)])
                    ->all(),
                'telescope_was_recording' => static::telescopeIsRecording(),
            ],
        ]);

        config($overrides);

        static::refreshServices();

        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
    }

    public static function deactivate(): void
    {
        if (! static::isActive()) {
            return;
        }

        $state = config(self::STATE_KEY);

        config($state['previous'] ?? []);
        config([self::STATE_KEY => ['active' => false]]);

        static::refreshServices();

        if (($state['telescope_was_recording'] ?? false) && class_exists(Telescope::class)) {
            Telescope::startRecording();
        }
    }

    /**
     * Run a callback inside the demo context, restoring the previous
     * context afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function run(Closure $callback): mixed
    {
        $wasActive = static::isActive();

        static::activate();

        try {
            return $callback();
        } finally {
            if (! $wasActive) {
                static::deactivate();
            }
        }
    }

    /**
     * Every config value the demo context changes.
     *
     * @return array<string, mixed>
     */
    protected static function overrides(): array
    {
        $demo = DemoDatabase::connectionName();
        $main = DemoDatabase::mainConnectionName();
        $publicUrl = rtrim((string) config('filesystems.disks.public.url'), '/');

        /*
         * Livewire's upload endpoint belongs to no panel, so it stages
         * temporary uploads with the context it starts in (main). The demo
         * context therefore reads them back through a copy of that
         * ORIGINAL disk definition; the file is then moved onto the demo
         * disk when the form saves, so nothing is persisted outside demo
         * storage.
         */
        $temporaryUploadDisk = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');

        return [
            'database.default' => $demo,

            // An application that runs its jobs inline keeps doing so on
            // /demo (inline = inside this same demo context); otherwise
            // jobs go to the demo database's own queue.
            'queue.default' => config('queue.default') === 'sync' ? 'sync' : 'demo',
            'queue.batching.database' => $demo,
            'queue.failed.database' => $demo,

            'session.connection' => config('session.connection') ?? $main,

            'cache.prefix' => config('cache.prefix').'demo-',
            'cache.stores.file.path' => storage_path('framework/cache/demo'),
            'cache.stores.file.lock_path' => storage_path('framework/cache/demo'),
            'cache.stores.database.connection' => $demo,
            'cache.stores.database.lock_connection' => $demo,
            'permission.cache.key' => 'demo:'.config('permission.cache.key'),

            'filesystems.disks.local.root' => storage_path('app/private/demo'),
            'filesystems.disks.public.root' => storage_path('app/public/demo'),
            'filesystems.disks.public.url' => $publicUrl.'/demo',
            'filesystems.disks.demo_livewire_tmp' => config("filesystems.disks.{$temporaryUploadDisk}"),
            'livewire.temporary_file_upload.disk' => 'demo_livewire_tmp',

            'mail.default' => 'log',

            // Telescope's tables (and its migration) name their own
            // connection; in the demo context that is the demo database.
            'telescope.storage.database.connection' => $demo,
        ];
    }

    /**
     * Drop every service instance that captured the old configuration
     * when it was built, so the next use rebuilds it from the new one.
     */
    protected static function refreshServices(): void
    {
        $app = app();

        if ($app->resolved('cache')) {
            $app['cache']->forgetDriver();
            $app['cache']->purge('file');
            $app['cache']->purge('database');
        }

        $app->forgetInstance('cache.store');
        $app->forgetInstance(RateLimiter::class);

        if ($app->resolved('filesystem')) {
            $app['filesystem']->forgetDisk(['local', 'public', 'demo_livewire_tmp']);
        }

        $app->forgetInstance('filesystem.disk');

        if ($app->resolved('mail.manager')) {
            $app['mail.manager']->forgetMailers();
        }

        $app->forgetInstance('mailer');

        $app->forgetInstance('queue.connection');
        $app->forgetInstance('queue.failer');
        $app->forgetInstance(BatchRepository::class);
        $app->forgetInstance(DatabaseBatchRepository::class);

        if ($app->resolved(PermissionRegistrar::class)) {
            $app->make(PermissionRegistrar::class)->initializeCache();
        }

        Facade::clearResolvedInstances();
    }

    protected static function telescopeIsRecording(): bool
    {
        return class_exists(Telescope::class) && Telescope::isRecording();
    }
}
