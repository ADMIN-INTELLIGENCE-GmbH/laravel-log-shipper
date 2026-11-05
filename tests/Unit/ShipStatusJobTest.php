<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipStatusJob;
use AdminIntelligence\LogShipper\Tests\Support\FakeCommandRunner;
use AdminIntelligence\LogShipper\Tests\Support\TestableShipStatusJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PDOException;
use PHPUnit\Framework\Attributes\Test;

class ShipStatusJobTest extends TestCase
{
    #[Test]
    public function it_does_not_run_when_disabled(): void
    {
        Http::fake();

        config(['log-shipper.status.enabled' => false]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_collects_system_metrics_when_enabled(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.system' => true,
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['system'])
                && isset($data['system']['php_version'])
                && isset($data['system']['laravel_version'])
                && isset($data['system']['memory_usage']);
        });
    }

    #[Test]
    public function it_collects_cpu_usage(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.system' => true,
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return array_key_exists('cpu_usage', $data['system']);
        });
    }

    #[Test]
    public function it_collects_filesize_metrics(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempFile, 'test content');

        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.filesize' => true,
            'log-shipper.status.monitored_files' => [$tempFile],
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) use ($tempFile) {
            $data = $request->data();
            $basename = basename($tempFile);

            return isset($data['filesize'])
                && isset($data['filesize'][$basename])
                && $data['filesize'][$basename] === 12; // 'test content' = 12 bytes
        });

        unlink($tempFile);
    }

    #[Test]
    public function it_returns_negative_one_for_missing_file(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.filesize' => true,
            'log-shipper.status.monitored_files' => ['/non/existent/file.log'],
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['filesize'])
                && isset($data['filesize']['file.log'])
                && $data['filesize']['file.log'] === -1;
        });
    }

    #[Test]
    public function it_collects_foldersize_metrics(): void
    {
        // Create a temporary directory with some files
        $tempDir = sys_get_temp_dir() . '/test_folder_' . uniqid();
        mkdir($tempDir);
        file_put_contents($tempDir . '/file1.txt', 'content1'); // 8 bytes
        file_put_contents($tempDir . '/file2.txt', 'content2'); // 8 bytes
        mkdir($tempDir . '/subdir');
        file_put_contents($tempDir . '/subdir/file3.txt', 'content3'); // 8 bytes

        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.foldersize' => true,
            'log-shipper.status.monitored_folders' => [$tempDir],
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) use ($tempDir) {
            $data = $request->data();
            $basename = basename($tempDir);

            return isset($data['foldersize'])
                && isset($data['foldersize'][$basename])
                && $data['foldersize'][$basename] === 24; // 8 + 8 + 8 = 24 bytes
        });

        // Cleanup
        unlink($tempDir . '/subdir/file3.txt');
        rmdir($tempDir . '/subdir');
        unlink($tempDir . '/file1.txt');
        unlink($tempDir . '/file2.txt');
        rmdir($tempDir);
    }

    #[Test]
    public function it_returns_negative_one_for_missing_folder(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.foldersize' => true,
            'log-shipper.status.monitored_folders' => ['/non/existent/folder'],
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['foldersize'])
                && isset($data['foldersize']['folder'])
                && $data['foldersize']['folder'] === -1;
        });
    }

    #[Test]
    public function it_handles_nested_folders_correctly(): void
    {
        // Create a deeply nested directory structure
        $tempDir = sys_get_temp_dir() . '/test_nested_' . uniqid();
        mkdir($tempDir);
        mkdir($tempDir . '/level1');
        mkdir($tempDir . '/level1/level2');
        mkdir($tempDir . '/level1/level2/level3');

        file_put_contents($tempDir . '/root.txt', 'a'); // 1 byte
        file_put_contents($tempDir . '/level1/file.txt', 'bb'); // 2 bytes
        file_put_contents($tempDir . '/level1/level2/file.txt', 'ccc'); // 3 bytes
        file_put_contents($tempDir . '/level1/level2/level3/file.txt', 'dddd'); // 4 bytes

        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.foldersize' => true,
            'log-shipper.status.monitored_folders' => [$tempDir],
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) use ($tempDir) {
            $data = $request->data();
            $basename = basename($tempDir);

            return isset($data['foldersize'])
                && isset($data['foldersize'][$basename])
                && $data['foldersize'][$basename] === 10; // 1 + 2 + 3 + 4 = 10 bytes
        });

        // Cleanup
        unlink($tempDir . '/level1/level2/level3/file.txt');
        rmdir($tempDir . '/level1/level2/level3');
        unlink($tempDir . '/level1/level2/file.txt');
        rmdir($tempDir . '/level1/level2');
        unlink($tempDir . '/level1/file.txt');
        rmdir($tempDir . '/level1');
        unlink($tempDir . '/root.txt');
        rmdir($tempDir);
    }

    #[Test]
    public function it_collects_queue_metrics(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.queue' => true,
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['queue'])
                && array_key_exists('size', $data['queue'])
                && isset($data['queue']['connection']);
        });
    }

    #[Test]
    public function it_collects_database_metrics(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.database' => true,
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['database'])
                && isset($data['database']['status']);
        });
    }

    #[Test]
    public function it_does_not_send_when_endpoint_is_empty(): void
    {
        Http::fake();

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => '',
            'log-shipper.api_key' => 'test-key',
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_does_not_send_when_api_key_is_empty(): void
    {
        Http::fake();

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => '',
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_sends_correct_headers(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'my-secret-key',
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Project-Key', 'my-secret-key')
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    #[Test]
    public function it_includes_timestamp_and_app_metadata(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'app.name' => 'Test App',
            'app.env' => 'testing',
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return isset($data['timestamp'])
                && isset($data['app_name'])
                && $data['app_name'] === 'Test App'
                && isset($data['app_env'])
                && $data['app_env'] === 'testing'
                && isset($data['instance_id']);
        });
    }

    #[Test]
    public function it_can_disable_specific_metrics(): void
    {
        Http::fake([
            '*' => Http::response(['status' => 'ok'], 200),
        ]);

        config([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => 'https://status.example.com/api/status',
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics.system' => false,
            'log-shipper.status.metrics.queue' => false,
            'log-shipper.status.metrics.database' => false,
            'log-shipper.status.metrics.filesize' => false,
            'log-shipper.status.metrics.foldersize' => false,
        ]);

        $job = new ShipStatusJob;
        $job->handle();

        Http::assertSent(function ($request) {
            $data = $request->data();

            return !isset($data['system'])
                && !isset($data['queue'])
                && !isset($data['database'])
                && !isset($data['filesize'])
                && !isset($data['foldersize']);
        });
    }

    #[Test]
    public function collect_metrics_is_public_and_sends_no_cache_metric(): void
    {
        $this->minimalStatusConfig(['log-shipper.status.metrics.cache' => true]);

        $metrics = (new ShipStatusJob)->collectMetrics();

        $this->assertArrayNotHasKey('cache', $metrics);
    }

    private function minimalStatusConfig(array $overrides = []): void
    {
        config(array_merge([
            'log-shipper.status.enabled' => true,
            'log-shipper.status.endpoint' => null,
            'log-shipper.api_key' => 'test-key',
            'log-shipper.status.metrics' => ['system' => false, 'queue' => false, 'database' => false, 'filesize' => false, 'foldersize' => false],
        ], $overrides));
    }

    #[Test]
    public function it_derives_the_stats_endpoint_from_an_ingest_endpoint(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $this->minimalStatusConfig(['log-shipper.api_endpoint' => 'https://logs.example.com/api/ingest/']);

        (new ShipStatusJob)->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://logs.example.com/api/stats');
    }

    #[Test]
    public function it_appends_status_to_a_non_ingest_endpoint(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $this->minimalStatusConfig(['log-shipper.api_endpoint' => 'https://logs.example.com/collect']);

        (new ShipStatusJob)->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://logs.example.com/collect/status');
    }

    #[Test]
    public function it_logs_a_warning_when_the_status_push_fails(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);
        Log::spy();
        $this->minimalStatusConfig();

        (new ShipStatusJob)->handle();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'HTTP 404')
                && isset($context['log_shipper_failure']));
    }

    #[Test]
    public function it_maps_the_default_queue_connection_for_queue_metrics(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $this->minimalStatusConfig([
            'log-shipper.queue_connection' => 'default',
            'queue.default' => 'sync',
            'log-shipper.status.metrics.queue' => true,
        ]);

        (new ShipStatusJob)->handle();

        Http::assertSent(fn ($request) => ($request->data()['queue']['connection'] ?? null) === 'sync'
            && ($request->data()['queue']['size'] ?? null) === 0);
    }

    #[Test]
    public function it_refuses_a_derived_http_endpoint_in_production(): void
    {
        Http::fake();
        Log::spy();
        $this->minimalStatusConfig([
            'app.env' => 'production',
            'log-shipper.api_endpoint' => 'http://logs.example.com/api/ingest',
        ]);

        (new ShipStatusJob)->handle();

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'https required')
                && $context['endpoint'] === 'http://logs.example.com/api/stats'
                && isset($context['log_shipper_failure']));
    }

    #[Test]
    public function it_refuses_an_explicit_http_status_endpoint_in_production(): void
    {
        Http::fake();
        $this->minimalStatusConfig([
            'app.env' => 'production',
            'log-shipper.api_endpoint' => 'https://logs.example.com/api/ingest',
            'log-shipper.status.endpoint' => 'http://logs.example.com/api/stats',
        ]);

        (new ShipStatusJob)->handle();

        Http::assertNothingSent();
    }

    #[Test]
    public function it_sends_to_an_https_endpoint_in_production(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $this->minimalStatusConfig([
            'app.env' => 'production',
            'log-shipper.api_endpoint' => 'https://logs.example.com/api/ingest',
        ]);

        (new ShipStatusJob)->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://logs.example.com/api/stats');
    }

    #[Test]
    public function it_reports_a_database_failure_without_the_raw_message(): void
    {
        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $this->minimalStatusConfig([
            'log-shipper.api_endpoint' => 'https://logs.example.com/api/ingest',
            'log-shipper.status.metrics.database' => true,
        ]);

        $e = new PDOException("SQLSTATE[HY000] [1045] Access denied for user 'app'@'10.0.0.5'");
        $e->errorInfo = ['HY000', 1045, "Access denied for user 'app'@'10.0.0.5'"];
        DB::shouldReceive('connection')->andThrow($e);

        (new ShipStatusJob)->handle();

        Http::assertSent(function ($request) {
            $db = $request->data()['database'] ?? [];

            return $db['status'] === 'failed'
                && $db['error'] === 'PDOException SQLSTATE[HY000] [1045]'
                && !str_contains($db['error'], '10.0.0.5');
        });
    }

    #[Test]
    public function dependency_checks_and_security_audits_are_off_by_default(): void
    {
        $config = require __DIR__ . '/../../config/log-shipper.php';

        $this->assertFalse($config['status']['metrics']['dependency_checks']);
        $this->assertFalse($config['status']['metrics']['security_audits']);
    }

    /**
     * Runs the status job with dependency checks and audits on, in a base
     * path that has a package.json, against canned command output.
     */
    private function shipDependencyCounts(FakeCommandRunner $runner): array
    {
        $dir = sys_get_temp_dir() . '/log-shipper-deps-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/package.json', '{}');
        $this->app->setBasePath($dir);

        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $this->minimalStatusConfig([
            'log-shipper.api_endpoint' => 'https://logs.example.com/api/ingest',
            'log-shipper.status.metrics.system' => true,
            'log-shipper.status.metrics.dependency_checks' => true,
            'log-shipper.status.metrics.security_audits' => true,
        ]);

        $job = new TestableShipStatusJob;
        $job->fakeRunner = $runner;

        try {
            $job->handle();
        } finally {
            unlink($dir . '/package.json');
            rmdir($dir);
        }

        $sent = null;
        Http::assertSent(function ($request) use (&$sent) {
            $sent = $request->data()['system'];

            return true;
        });

        return [$sent, $dir];
    }

    #[Test]
    public function it_sends_null_dependency_counts_when_the_commands_fail(): void
    {
        [$data] = $this->shipDependencyCounts(new FakeCommandRunner([
            'composer outdated' => '',
            'npm outdated' => 'not json',
            'composer audit' => '{"unexpected": true}',
            'npm audit' => '{"error": {"code": "ENOLOCK"}}',
        ]));

        $this->assertNull($data['composer_outdated']);
        $this->assertNull($data['npm_outdated']);
        $this->assertNull($data['composer_audit']);
        $this->assertNull($data['npm_audit']);
    }

    #[Test]
    public function it_sends_dependency_counts_when_the_commands_succeed(): void
    {
        $runner = new FakeCommandRunner([
            'composer outdated' => '{"installed": [{"name": "a/b"}, {"name": "c/d"}]}',
            'npm outdated' => '{}',
            'composer audit' => '{"advisories": []}',
            'npm audit' => '{"metadata": {"vulnerabilities": {"low": 1, "high": 2}}}',
        ]);

        [$data, $dir] = $this->shipDependencyCounts($runner);

        $this->assertSame(2, $data['composer_outdated']);
        $this->assertSame(0, $data['npm_outdated']);
        $this->assertSame(0, $data['composer_audit']);
        $this->assertSame(3, $data['npm_audit']);

        // Runs in the app dir via cwd, not a `cd X &&` prefix that would orphan on timeout
        $deps = array_keys(array_filter($runner->commands, fn ($c) => preg_match('/composer|npm (outdated|audit)/', $c)));
        $this->assertCount(4, $deps);
        foreach ($deps as $i) {
            $this->assertStringStartsWith('exec ', $runner->commands[$i]);
            $this->assertSame($dir, $runner->cwds[$i]);
        }
    }

    /**
     * Runs the status job $times times in one app dir and returns the runner.
     */
    private function shipDependencyChecksRepeatedly(FakeCommandRunner $runner, int $times, array $config = [], ?callable $between = null): FakeCommandRunner
    {
        $dir = sys_get_temp_dir() . '/log-shipper-deps-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/package.json', '{}');
        file_put_contents($dir . '/composer.lock', '{}');
        $this->app->setBasePath($dir);

        Http::fake(['*' => Http::response(['status' => 'ok'], 200)]);
        $this->minimalStatusConfig(array_merge([
            'log-shipper.api_endpoint' => 'https://logs.example.com/api/ingest',
            'log-shipper.status.metrics.system' => true,
            'log-shipper.status.metrics.dependency_checks' => true,
            'log-shipper.status.metrics.security_audits' => true,
        ], $config));

        try {
            for ($i = 0; $i < $times; $i++) {
                if ($i > 0 && $between) {
                    $between($dir);
                }
                $job = new TestableShipStatusJob;
                $job->fakeRunner = $runner;
                $job->handle();
            }
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }

        return $runner;
    }

    private function countDependencyCommands(FakeCommandRunner $runner): int
    {
        return count(array_filter($runner->commands, fn ($c) => preg_match('/(composer|npm) (outdated|audit)/', $c)));
    }

    private function succeedingDependencyRunner(): FakeCommandRunner
    {
        return new FakeCommandRunner([
            'composer outdated' => '{"installed": [{"name": "a/b"}]}',
            'npm outdated' => '{}',
            'composer audit' => '{"advisories": []}',
            'npm audit' => '{"metadata": {"vulnerabilities": {"high": 1}}}',
        ]);
    }

    #[Test]
    public function it_reuses_dependency_results_between_pushes(): void
    {
        $runner = $this->shipDependencyChecksRepeatedly($this->succeedingDependencyRunner(), 2);

        $this->assertSame(4, $this->countDependencyCommands($runner));

        $pushes = [];
        Http::assertSent(function ($request) use (&$pushes) {
            $pushes[] = $request->data()['system'];

            return true;
        });
        $this->assertCount(2, $pushes);
        foreach ($pushes as $data) {
            $this->assertSame(1, $data['composer_outdated']);
            $this->assertSame(0, $data['npm_outdated']);
            $this->assertSame(0, $data['composer_audit']);
            $this->assertSame(1, $data['npm_audit']);
        }
    }

    #[Test]
    public function it_runs_dependency_checks_every_push_when_the_cache_ttl_is_zero(): void
    {
        $runner = $this->shipDependencyChecksRepeatedly($this->succeedingDependencyRunner(), 2, ['log-shipper.status.dependency_cache_ttl' => 0]);

        $this->assertSame(8, $this->countDependencyCommands($runner));
    }

    #[Test]
    public function it_reruns_composer_checks_when_the_lock_file_changes(): void
    {
        $runner = $this->shipDependencyChecksRepeatedly($this->succeedingDependencyRunner(), 2, [], function (string $dir) {
            touch($dir . '/composer.lock', time() + 60);
            clearstatcache();
        });

        $composer = array_filter($runner->commands, fn ($c) => preg_match('/composer (outdated|audit)/', $c));
        $npm = array_filter($runner->commands, fn ($c) => preg_match('/npm (outdated|audit)/', $c));
        $this->assertCount(4, $composer);
        $this->assertCount(2, $npm);
    }

    #[Test]
    public function it_does_not_cache_failed_dependency_checks(): void
    {
        $runner = $this->shipDependencyChecksRepeatedly(new FakeCommandRunner([
            'composer outdated' => '',
            'npm outdated' => 'not json',
            'composer audit' => '{"unexpected": true}',
            'npm audit' => '{"error": {"code": "ENOLOCK"}}',
        ]), 2);

        $this->assertSame(8, $this->countDependencyCommands($runner));
    }

    #[Test]
    public function it_still_sends_dependency_counts_when_the_cache_is_down(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));

        $runner = $this->shipDependencyChecksRepeatedly($this->succeedingDependencyRunner(), 1);

        $this->assertSame(4, $this->countDependencyCommands($runner));
        Http::assertSent(fn ($request) => $request->data()['system']['npm_audit'] === 1);
    }
}
