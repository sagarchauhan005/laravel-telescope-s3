<?php

namespace Laravel\Telescope\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Telescope\Contracts\EntriesRepository;
use Laravel\Telescope\Storage\DatabaseEntriesRepository;
use Laravel\Telescope\Storage\EntryModel;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeServiceProvider;
use Orchestra\Testbench\Concerns\WithLaravelMigrations;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;

class FeatureTestCase extends TestCase
{
    use WithWorkbench, RefreshDatabase, WithLaravelMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        TestResponse::macro('terminateTelescope', [$this, 'terminateTelescope']);

        Telescope::flushEntries();
        Telescope::$afterStoringHooks = [];
    }

    protected function tearDown(): void
    {
        Telescope::flushEntries();
        Telescope::$afterStoringHooks = [];

        Queue::createPayloadUsing(null);

        parent::tearDown();
    }

    protected function getPackageProviders($app)
    {
        return [
            TelescopeServiceProvider::class,
        ];
    }

    public function ignorePackageDiscoveriesFrom()
    {
        return ['*', 'spatie/laravel-ray'];
    }

    protected function resolveApplicationCore($app)
    {
        parent::resolveApplicationCore($app);

        $app->detectEnvironment(function () {
            return 'self-testing';
        });
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        $config = $app->get('config');

        $config->set('logging.default', 'errorlog');

        $config->set('database.default', 'testbench');

        $config->set('telescope.storage.database.connection', 'testbench');

        $config->set('queue.batching.database', 'testbench');

        $config->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app->when(DatabaseEntriesRepository::class)
            ->needs('$connection')
            ->give('testbench');
    }

    protected function loadTelescopeEntries()
    {
        $this->terminateTelescope();

        return EntryModel::all();
    }

    public function terminateTelescope()
    {
        Telescope::store(app(EntriesRepository::class));
    }
}
