<?php

namespace Tests\Feature\Portal;

use App\Filament\Imports\LeadImporter;
use App\Filament\Pages\Auth\Login;
use App\Filament\Resources\Cities\Pages\CreateCity;
use App\Filament\Resources\Cities\Pages\ListCities;
use App\Jobs\ProcessOcrDocument;
use App\Models\City;
use App\Models\Customer;
use App\Models\Demo\DemoUser;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\OcrDocument;
use App\Models\User;
use App\Models\UserLoginSession;
use App\Support\Demo\DemoContext;
use App\Support\Demo\DemoDatabase;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * /admin and /demo are the same application on two isolated environments.
 *
 * The main database is the default connection; the demo database is the
 * `demo` connection (its own SQLite :memory: database under phpunit.xml,
 * its own MySQL database in production) carrying the same schema. Every
 * test here proves one boundary of App\Support\Demo\DemoContext: data,
 * logins, queue, cache, storage, scheduled work and permissions written
 * on one side are never visible to — or written into — the other.
 */
class DemoEnvironmentIsolationTest extends PortalBoundaryTestCase
{
    protected function tearDown(): void
    {
        DemoContext::deactivate();

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Schema
    // ---------------------------------------------------------------

    public function test_the_demo_database_carries_the_same_schema_as_the_main_database(): void
    {
        $mainTables = collect(Schema::getTableListing(schemaQualified: false))->sort()->values()->all();
        $demoTables = collect(Schema::connection('demo')->getTableListing(schemaQualified: false))->sort()->values()->all();

        $this->assertNotEmpty($mainTables);
        $this->assertSame($mainTables, $demoTables);

        // The retired simplified sandbox tables exist in neither.
        $this->assertNotContains('demo_customers', $demoTables);
    }

    // ---------------------------------------------------------------
    // Authentication
    // ---------------------------------------------------------------

    public function test_admin_credentials_reach_admin_but_are_a_guest_on_demo(): void
    {
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));

        $this->get('/demo')->assertRedirect('/demo/login');
        $this->get('/admin')->assertOk();
    }

    public function test_demo_credentials_reach_demo_but_are_a_guest_on_admin(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser());

        // /demo first: Filament's navigation is a request-scoped service, and
        // a real request only ever renders one panel.
        $this->get('/demo')->assertOk()->assertSee('Demo environment');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_forms_never_accept_the_other_environments_credentials(): void
    {
        // The SAME email in both databases, with different passwords.
        User::factory()->create(['email' => 'shared@example.test', 'password' => 'MainPass@123']);
        $this->makeDemoUser(['email' => 'shared@example.test', 'password' => 'DemoPass@123']);

        // /demo login: main password fails, demo password succeeds.
        $this->onDemoPanel(function (): void {
            Livewire::test(Login::class)
                ->fillForm(['email' => 'shared@example.test', 'password' => 'MainPass@123'])
                ->call('authenticate')
                ->assertHasFormErrors(['email']);
            $this->assertGuest('demo');

            Livewire::test(Login::class)
                ->fillForm(['email' => 'shared@example.test', 'password' => 'DemoPass@123'])
                ->call('authenticate')
                ->assertHasNoFormErrors();
        });

        $this->assertInstanceOf(DemoUser::class, auth('demo')->user());
        $this->assertGuest('web');

        // /admin login: demo password fails.
        auth('demo')->logout();
        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'shared@example.test', 'password' => 'DemoPass@123'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
        $this->assertGuest('web');
    }

    public function test_a_demo_login_is_recorded_only_in_the_demo_database(): void
    {
        $demoUser = $this->makeDemoUser(['email' => 'demo-login@example.test', 'password' => 'DemoPass@123']);

        $this->onDemoPanel(fn () => Livewire::test(Login::class)
            ->fillForm(['email' => 'demo-login@example.test', 'password' => 'DemoPass@123'])
            ->call('authenticate')
            ->assertHasNoFormErrors());

        $this->assertSame(0, UserLoginSession::query()->count(), 'A demo login wrote a main-database login session.');
        $this->assertGreaterThan(0, DB::connection('demo')->table('user_login_sessions')->where('user_id', $demoUser->id)->count());
    }

    // ---------------------------------------------------------------
    // Database — records created in one panel exist only in its database
    // ---------------------------------------------------------------

    public function test_a_record_created_through_demo_exists_only_in_the_demo_database(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser());

        $this->onDemoPanel(fn () => Livewire::test(CreateCity::class)
            ->fillForm(['country' => 'India', 'state' => 'Goa', 'city' => 'DEMO TEST CITY', 'pincode' => '403001', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors());

        $this->assertDatabaseHas('cities', ['pincode' => '403001'], 'demo');
        $this->assertDatabaseMissing('cities', ['pincode' => '403001']);
    }

    public function test_a_record_created_through_admin_exists_only_in_the_main_database(): void
    {
        $this->actingAsPortalUser($this->makeInternalUser('Admin'));
        Filament::setCurrentPanel('admin');

        Livewire::test(CreateCity::class)
            ->fillForm(['country' => 'India', 'state' => 'Goa', 'city' => 'MAIN TEST CITY', 'pincode' => '403001', 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('cities', ['pincode' => '403001']);
        $this->assertDatabaseMissing('cities', ['pincode' => '403001'], 'demo');
    }

    public function test_customers_created_on_each_side_are_listed_only_by_their_own_panel(): void
    {
        $mainCustomer = Customer::factory()->create(['customer_name' => 'MAIN TEST CUSTOMER']);
        $demoCustomer = DemoContext::run(fn () => Customer::factory()->create(['customer_name' => 'DEMO TEST CUSTOMER']));

        $this->assertDatabaseHas('customers', ['customer_name' => 'MAIN TEST CUSTOMER']);
        $this->assertDatabaseMissing('customers', ['customer_name' => 'DEMO TEST CUSTOMER']);
        $this->assertDatabaseHas('customers', ['customer_name' => 'DEMO TEST CUSTOMER'], 'demo');
        $this->assertDatabaseMissing('customers', ['customer_name' => 'MAIN TEST CUSTOMER'], 'demo');

        // Same id on both sides would be the classic cross-read bug.
        $this->assertSame($mainCustomer->id, $demoCustomer->id);

        $this->actingAsDemoUser($this->makeDemoUser());
        $this->get('/demo/customers/'.$demoCustomer->id)
            ->assertOk()
            ->assertSee('DEMO TEST CUSTOMER')
            ->assertDontSee('MAIN TEST CUSTOMER');
    }

    public function test_the_admin_panel_lists_only_main_records(): void
    {
        City::query()->create(['country' => 'India', 'state' => 'Goa', 'city' => 'MAIN ONLY CITY', 'is_active' => true]);
        DemoContext::run(fn () => City::query()->create(['country' => 'India', 'state' => 'Goa', 'city' => 'DEMO ONLY CITY', 'is_active' => true]));

        $this->actingAsPortalUser($this->makeInternalUser('Admin'));
        Filament::setCurrentPanel('admin');

        Livewire::test(ListCities::class)
            ->assertSee('MAIN ONLY CITY')
            ->assertDontSee('DEMO ONLY CITY');

        $this->onDemoPanel(fn () => Livewire::test(ListCities::class)
            ->assertSee('DEMO ONLY CITY')
            ->assertDontSee('MAIN ONLY CITY'));
    }

    // ---------------------------------------------------------------
    // Imports
    // ---------------------------------------------------------------

    public function test_a_demo_import_writes_only_to_the_demo_database(): void
    {
        $demoUser = $this->makeDemoUser();

        DemoContext::run(function () use ($demoUser): void {
            // The Lead importer files every row under the importing user's
            // employee, exactly as on /admin.
            $demoUser->forceFill(['employee_id' => Employee::factory()->create()->id])->save();

            auth()->guard('demo')->setUser($demoUser);
            auth()->shouldUse('demo');

            $import = Import::query()->create([
                'file_name' => 'leads.csv',
                'file_path' => 'leads.csv',
                'importer' => LeadImporter::class,
                'total_rows' => 1,
                'user_id' => $demoUser->id,
            ]);

            (new ImportCsv(
                $import,
                rows: base64_encode(serialize([[
                    'customer_name' => 'DEMO IMPORTED LEAD',
                    'mobile_no' => '9000000123',
                    'salary' => '50000',
                    'follow_up_type' => 'call',
                    'remarks' => 'Imported on the demo environment',
                ]])),
                columnMap: [
                    'customer_name' => 'customer_name',
                    'mobile_no' => 'mobile_no',
                    'salary' => 'salary',
                    'follow_up_type' => 'follow_up_type',
                    'remarks' => 'remarks',
                ],
            ))->handle();

        });

        $this->assertDatabaseHas('leads', ['customer_name' => 'DEMO IMPORTED LEAD'], 'demo');
        $this->assertDatabaseMissing('leads', ['customer_name' => 'DEMO IMPORTED LEAD']);
        $this->assertSame(0, DB::table('imports')->count(), 'The import bookkeeping row landed in the main database.');
    }

    // ---------------------------------------------------------------
    // Queue
    // ---------------------------------------------------------------

    public function test_demo_jobs_go_to_the_demo_queue_and_are_processed_against_the_demo_database(): void
    {
        config(['queue.default' => 'database']);

        DemoContext::run(function (): void {
            dispatch(function (): void {
                City::query()->create(['country' => 'India', 'state' => 'Goa', 'city' => 'QUEUED DEMO CITY', 'is_active' => true]);
            });
        });

        $this->assertSame(0, DB::table('jobs')->count(), 'A demo job was pushed onto the MAIN queue.');
        $this->assertSame(1, DB::connection('demo')->table('jobs')->count());

        // A generous memory limit: inside a long test run the process is
        // already past the worker's default 128MB restart threshold.
        $this->artisan('demo:queue-work', ['--stop-when-empty' => true, '--memory' => 4096])->assertExitCode(0);

        // The worker leaves this (test) process in the demo context.
        $this->assertTrue(DemoContext::isActive());
        DemoContext::deactivate();

        $this->assertDatabaseHas('cities', ['city' => 'QUEUED DEMO CITY'], 'demo');
        $this->assertDatabaseMissing('cities', ['city' => 'QUEUED DEMO CITY']);
        $this->assertSame(0, DB::connection('demo')->table('jobs')->count());
    }

    public function test_a_plain_worker_refuses_the_demo_queue_without_reserving_a_job(): void
    {
        config(['queue.default' => 'database']);

        DemoContext::run(function (): void {
            dispatch(function (): void {
                City::query()->create(['country' => 'India', 'state' => 'Goa', 'city' => 'NEVER RUN', 'is_active' => true]);
            });
        });

        $this->assertSame(1, DB::connection('demo')->table('jobs')->count());

        Exceptions::fake();

        Artisan::call('queue:work', ['connection' => 'demo', '--once' => true]);

        Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'demo:queue-work'));

        $this->assertSame(1, DB::connection('demo')->table('jobs')->whereNull('reserved_at')->count(), 'The demo job was reserved by the wrong worker.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertDatabaseMissing('cities', ['city' => 'NEVER RUN']);
    }

    // ---------------------------------------------------------------
    // OCR / documents and storage
    // ---------------------------------------------------------------

    public function test_demo_uploads_are_stored_under_demo_storage_only(): void
    {
        $path = 'isolation-test/'.uniqid().'.txt';

        try {
            DemoContext::run(function () use ($path): void {
                Storage::disk('local')->put($path, 'demo');
                Storage::disk('public')->put($path, 'demo');
            });

            $this->assertFileExists(storage_path('app/private/demo/'.$path));
            $this->assertFileExists(storage_path('app/public/demo/'.$path));
            $this->assertFileDoesNotExist(storage_path('app/private/'.$path));
            $this->assertFileDoesNotExist(storage_path('app/public/'.$path));

            DemoContext::run(fn () => $this->assertStringEndsWith('/storage/demo/'.$path, Storage::disk('public')->url($path)));
        } finally {
            @unlink(storage_path('app/private/demo/'.$path));
            @unlink(storage_path('app/public/demo/'.$path));
        }
    }

    public function test_a_demo_ocr_document_is_stored_and_served_only_from_the_demo_side(): void
    {
        $path = 'ocr-documents/'.uniqid().'.pdf';
        $demoUser = $this->makeDemoUser();

        try {
            $document = DemoContext::run(function () use ($path, $demoUser): OcrDocument {
                Storage::disk('local')->put($path, '%PDF-demo');

                $document = new OcrDocument;
                $document->forceFill([
                    'original_name' => 'demo.pdf',
                    'original_path' => $path,
                    'status' => 'completed',
                    'uploaded_by' => $demoUser->id,
                ])->save();

                // The viewer links to the demo copy of the file route.
                $this->assertStringContainsString('/demo/ocr-documents/'.$document->id.'/file', $document->file_url);

                return $document;
            });

            $this->assertSame(0, DB::table('ocr_documents')->count());
            $this->assertFileExists(storage_path('app/private/demo/'.$path));

            // Served to the demo login, from demo storage.
            $this->actingAsDemoUser($demoUser);
            $this->get('/demo/ocr-documents/'.$document->id.'/file')->assertOk();

            // The main route cannot see it: the id does not exist in main.
            $this->actingAsPortalUser($this->makeInternalUser('Admin'));
            $this->get('/ocr-documents/'.$document->id.'/file')->assertNotFound();
            $this->get('/demo/ocr-documents/'.$document->id.'/file')->assertRedirect();
        } finally {
            @unlink(storage_path('app/private/demo/'.$path));
        }
    }

    public function test_a_demo_ocr_processing_job_is_queued_on_the_demo_queue(): void
    {
        config(['queue.default' => 'database']);

        DemoContext::run(function (): void {
            $document = new OcrDocument;
            $document->forceFill(['original_name' => 'x.pdf', 'original_path' => 'x.pdf', 'status' => 'pending'])->save();

            ProcessOcrDocument::dispatch($document->id);
        });

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::connection('demo')->table('jobs')->count());
        $this->assertStringContainsString('ProcessOcrDocument', (string) DB::connection('demo')->table('jobs')->value('payload'));
    }

    // ---------------------------------------------------------------
    // Cache and permissions
    // ---------------------------------------------------------------

    public function test_demo_never_reads_main_cached_values(): void
    {
        config(['cache.default' => 'file']);

        // Shaped like the marquee's cache key, but unique to this run: the
        // file cache is real storage shared with the local environment.
        $key = 'top-performers:isolation-'.uniqid();

        try {
            Cache::put($key, 'MAIN PERFORMERS', 60);

            DemoContext::run(function () use ($key): void {
                $this->assertNull(Cache::get($key));
                Cache::put($key, 'DEMO PERFORMERS', 60);
            });

            $this->assertSame('MAIN PERFORMERS', Cache::get($key));
            $this->assertSame('DEMO PERFORMERS', DemoContext::run(fn () => Cache::get($key)));
        } finally {
            Cache::forget($key);
            DemoContext::run(fn () => Cache::forget($key));
        }
    }

    public function test_demo_roles_come_from_the_demo_database_and_its_own_permission_cache(): void
    {
        Role::findOrCreate('Main Only Role', 'web');
        app(PermissionRegistrar::class)->getPermissions(); // warm the main permission cache

        DemoContext::run(function (): void {
            $this->assertStringStartsWith('demo:', config('permission.cache.key'));
            $this->assertFalse(Role::query()->where('name', 'Main Only Role')->exists());
            $this->assertNull(Role::query()->firstWhere('name', 'Main Only Role'));
        });

        $demoAdmin = $this->makeDemoUser(role: 'Admin');
        $this->assertTrue(DemoContext::run(fn () => $demoAdmin->fresh()->hasRole('Admin')));
        $this->assertSame(0, DB::table('model_has_roles')->count(), 'A demo role assignment landed in the main database.');
    }

    // ---------------------------------------------------------------
    // Scheduled work
    // ---------------------------------------------------------------

    public function test_a_demo_scheduled_job_only_updates_demo_records(): void
    {
        $mainUser = User::factory()->create();
        $demoUser = $this->makeDemoUser();

        $stale = [
            'session_id' => 'stale',
            'login_at' => now()->subHours(3),
            'last_seen_at' => now()->subHours(3),
            'last_activity_at' => now()->subHours(3),
            'screen_time_seconds' => 0,
        ];

        $mainSession = UserLoginSession::query()->create([...$stale, 'user_id' => $mainUser->id]);
        $demoSession = DemoContext::run(fn () => UserLoginSession::query()->create([...$stale, 'user_id' => $demoUser->id]));

        $this->artisan('demo:run', ['line' => ['sessions:close-idle']])->assertExitCode(0);

        $this->assertFalse(DemoContext::isActive(), 'demo:run must restore the previous context.');

        $this->assertNotNull(DemoContext::run(fn () => $demoSession->fresh()->logout_at), 'The demo session was not closed.');
        $this->assertNull($mainSession->fresh()->logout_at, 'The demo scheduled job closed a MAIN session.');
    }

    public function test_demo_run_refuses_schema_and_worker_commands(): void
    {
        foreach (['migrate:fresh', 'db:wipe', 'queue:work'] as $command) {
            $this->artisan('demo:run', ['line' => [$command]])->assertExitCode(1);
        }
    }

    // ---------------------------------------------------------------
    // Configuration safety (the existing checks remain)
    // ---------------------------------------------------------------

    public function test_the_demo_context_refuses_a_demo_connection_that_points_at_main(): void
    {
        $main = DemoDatabase::mainConnectionName();

        config([
            "database.connections.{$main}.database" => 'shared_database',
            'database.connections.demo' => array_merge(config("database.connections.{$main}"), ['database' => 'shared_database']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('resolves to the main database');

        DemoContext::activate();
    }

    public function test_the_demo_panel_refuses_to_run_without_a_demo_database(): void
    {
        config(['database.connections.demo.database' => null]);

        $this->withoutExceptionHandling();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('DEMO_DB_DATABASE is not set');

        $this->get('/demo/login');
    }

    public function test_the_demo_panel_can_be_switched_off_without_affecting_admin(): void
    {
        config(['demo.enabled' => false]);

        $this->get('/demo/login')->assertNotFound();

        $this->actingAsPortalUser($this->makeInternalUser('Admin'));
        $this->get('/admin')->assertOk();
    }

    public function test_demo_migrate_refuses_a_demo_connection_that_points_at_main(): void
    {
        $main = DemoDatabase::mainConnectionName();

        config([
            "database.connections.{$main}.database" => 'shared_database',
            'database.connections.demo' => array_merge(config("database.connections.{$main}"), ['database' => 'shared_database']),
        ]);

        $this->artisan('demo:migrate', ['--force' => true])
            ->expectsOutputToContain('resolves to the main database')
            ->assertExitCode(1);
    }

    public function test_the_context_is_switched_back_off_after_every_demo_request(): void
    {
        $this->actingAsDemoUser($this->makeDemoUser());

        $this->get('/demo')->assertOk();

        $this->assertFalse(DemoContext::isActive());
        $this->assertSame(DemoDatabase::mainConnectionName(), config('database.default'));
    }

    /**
     * Run Livewire component tests as the demo panel does: panel set to
     * `demo` and the demo context active (Livewire::test() bypasses the
     * HTTP middleware that normally switches it on).
     */
    protected function onDemoPanel(callable $callback): mixed
    {
        Filament::setCurrentPanel('demo');

        return DemoContext::run(function () use ($callback): mixed {
            auth()->shouldUse('demo');

            return $callback();
        });
    }
}
