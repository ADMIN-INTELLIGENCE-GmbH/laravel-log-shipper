<?php

namespace AdminIntelligence\LogShipper\Tests\Unit;

use AdminIntelligence\LogShipper\Jobs\ShipStatusJob;
use AdminIntelligence\LogShipper\Tests\TestCase;
use Illuminate\Support\Facades\Http;
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
}
