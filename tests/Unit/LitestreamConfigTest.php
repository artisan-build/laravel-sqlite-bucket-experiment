<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Litestream;
use App\Support\SqlitePath;
use PHPUnit\Framework\TestCase;

final class LitestreamConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('AWS_BUCKET=example-bucket');
        putenv('AWS_ENDPOINT=https://accountid.r2.cloudflarestorage.com');
        putenv('AWS_DEFAULT_REGION=auto');
        putenv('AWS_ACCESS_KEY_ID=AKIAEXAMPLEDONOTUSE');
        putenv('AWS_SECRET_ACCESS_KEY=shhh-not-a-real-secret');
        putenv('APP_URL=https://sqlite-bucket-production-example.laravel.cloud');
        putenv('LITESTREAM_DB_PATH=/tmp/sqlite-bucket-test/database.sqlite');
        SqlitePath::flush();
    }

    protected function tearDown(): void
    {
        foreach (['LARAVEL_CLOUD_DISK_CONFIG', 'LARAVEL_CLOUD_CI', 'LARAVEL_CLOUD_DEPLOY', 'LITESTREAM_SKIP', 'AWS_BUCKET', 'AWS_ENDPOINT', 'AWS_DEFAULT_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'APP_URL', 'LITESTREAM_DB_PATH'] as $key) {
            putenv($key);
        }

        SqlitePath::flush();

        parent::tearDown();
    }

    public function test_the_generated_config_never_contains_a_credential(): void
    {
        $config = Litestream::config();

        $this->assertStringNotContainsString('AKIAEXAMPLEDONOTUSE', $config);
        $this->assertStringNotContainsString('shhh-not-a-real-secret', $config);
        $this->assertStringNotContainsString('access-key-id', $config);
        $this->assertStringNotContainsString('secret-access-key', $config);
    }

    public function test_it_replicates_the_resolved_database_to_the_attached_bucket(): void
    {
        $config = Litestream::config();

        $this->assertStringContainsString('- path: /tmp/sqlite-bucket-test/database.sqlite', $config);
        $this->assertStringContainsString('type: s3', $config);
        $this->assertStringContainsString('bucket: example-bucket', $config);
        $this->assertStringContainsString('endpoint: https://accountid.r2.cloudflarestorage.com', $config);
        $this->assertStringContainsString('region: auto', $config);
    }

    public function test_each_environment_gets_its_own_replica_prefix(): void
    {
        $this->assertSame(
            'litestream/sqlite-bucket-production-example.laravel.cloud',
            Litestream::replicaPath()
        );
        $this->assertStringContainsString('path: litestream/sqlite-bucket-production-example.laravel.cloud', Litestream::config());
    }

    public function test_replication_is_off_until_a_bucket_is_attached(): void
    {
        putenv('AWS_BUCKET');

        $this->assertFalse(Litestream::enabled());
    }

    public function test_it_reads_the_bucket_out_of_clouds_own_disk_config(): void
    {
        // A Laravel app on Cloud gets no AWS_* variables at all: it gets this
        // one blob, the same one the framework's CloudBootstrapper reads.
        foreach (['AWS_BUCKET', 'AWS_ENDPOINT', 'AWS_DEFAULT_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'] as $key) {
            putenv($key);
        }

        putenv('LARAVEL_CLOUD_DISK_CONFIG='.json_encode([
            ['disk' => 'scoped-thing', 'scoped_disk' => 's3', 'prefix' => 'x'],
            [
                'disk' => 's3',
                'bucket' => 'cloud-bucket',
                'endpoint' => 'https://acct.r2.cloudflarestorage.com',
                'region' => 'auto',
                'access_key_id' => 'CLOUDKEYDONOTUSE',
                'access_key_secret' => 'cloud-secret-not-real',
                'is_default' => true,
            ],
        ]));

        $this->assertTrue(Litestream::disk() !== null);
        $this->assertSame('cloud-bucket', Litestream::disk()['bucket']);
        $this->assertSame('https://acct.r2.cloudflarestorage.com', Litestream::disk()['endpoint']);

        $config = Litestream::config();
        $this->assertStringContainsString('bucket: cloud-bucket', $config);
        $this->assertStringNotContainsString('CLOUDKEYDONOTUSE', $config);
        $this->assertStringNotContainsString('cloud-secret-not-real', $config);

        putenv('LARAVEL_CLOUD_DISK_CONFIG');
    }

    public function test_it_refuses_to_replicate_from_clouds_throwaway_containers(): void
    {
        $this->assertTrue(Litestream::onInstance());

        putenv('LARAVEL_CLOUD_CI=1');
        $this->assertFalse(Litestream::onInstance(), 'the build container must not replicate');
        putenv('LARAVEL_CLOUD_CI');

        putenv('LARAVEL_CLOUD_DEPLOY=1');
        $this->assertFalse(Litestream::onInstance(), 'the deploy-command container must not replicate');
        putenv('LARAVEL_CLOUD_DEPLOY');

        putenv('LITESTREAM_SKIP=1');
        $this->assertFalse(Litestream::onInstance(), 'the manual override must still work');
        putenv('LITESTREAM_SKIP');

        $this->assertTrue(Litestream::onInstance());
    }
}
