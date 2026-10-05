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
        foreach (['LARAVEL_CLOUD_DISK_CONFIG', 'LARAVEL_CLOUD', 'LARAVEL_CLOUD_CI', 'LARAVEL_CLOUD_DEPLOY', 'HOSTNAME', 'LITESTREAM_SKIP', 'AWS_BUCKET', 'AWS_ENDPOINT', 'AWS_DEFAULT_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'APP_URL', 'LITESTREAM_DB_PATH'] as $key) {
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
        $this->assertTrue(Litestream::onInstance(), 'off Cloud entirely, nothing is in the way');

        putenv('LARAVEL_CLOUD=1');
        putenv('HOSTNAME=inst-a2e8ca99-c82e-42c3-8f58-68f6265ce0a8-6162532-app-597bxwcch');
        $this->assertTrue(Litestream::onInstance(), 'the application instance replicates');

        putenv('HOSTNAME=dj-depl-a2e8d36f-9901-4d44-810a-9a099c368a80-4tjbd');
        $this->assertFalse(Litestream::onInstance(), 'the deploy-command pod must not replicate');
        $this->assertStringContainsString('not an application instance', (string) Litestream::skipReason());

        putenv('HOSTNAME=buildkitsandbox');
        putenv('LARAVEL_CLOUD_CI=1');
        $this->assertFalse(Litestream::onInstance(), 'the build container must not replicate');
        $this->assertStringContainsString('build container', (string) Litestream::skipReason());
        putenv('LARAVEL_CLOUD_CI');

        // LARAVEL_CLOUD_DEPLOY is the deploy NUMBER and is set on the instance
        // too, so it must never be read as "this is the deploy container".
        putenv('HOSTNAME=inst-a2e8ca99-c82e-42c3-8f58-68f6265ce0a8-6162532-app-597bxwcch');
        putenv('LARAVEL_CLOUD_DEPLOY=9');
        $this->assertTrue(Litestream::onInstance(), 'the deploy number is not a container marker');

        putenv('LITESTREAM_SKIP=1');
        $this->assertFalse(Litestream::onInstance(), 'the manual override must still work');

        foreach (['LITESTREAM_SKIP', 'LARAVEL_CLOUD', 'LARAVEL_CLOUD_DEPLOY', 'HOSTNAME'] as $key) {
            putenv($key);
        }
    }
}
