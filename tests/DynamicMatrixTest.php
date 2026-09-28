<?php

namespace EasyPixel\Laravel\Tests;

use EasyPixel\Laravel\Exception\MatrixNotConfiguredException;
use EasyPixel\Laravel\Facades\EasyPixel;
use EasyPixel\Laravel\Jobs\SendSceneJob;
use EasyPixel\Laravel\MatrixManager;
use Illuminate\Support\Facades\Queue;

/**
 * Matrices whose API keys are not in the configuration: the resolver stands in
 * for the database lookup an application would do.
 */
class DynamicMatrixTest extends TestCase
{
    /** @var MatrixManager */
    private $manager;

    /** @var string[] Names the resolver was asked about */
    private $lookups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make(MatrixManager::class);
    }

    public function testResolverSuppliesAMatrixMissingFromTheConfiguration()
    {
        $this->resolveFrom(['roof' => 'roof-key']);

        $this->manager->matrix('roof')->send(42);

        $this->assertSame(
            'https://api.test/api/webhook/roof-key',
            $this->httpClient('roof-key')->getLastRequest()['url']
        );
        $this->assertSame(['roof'], $this->lookups);
    }

    public function testResolverMayReturnPerMatrixSettings()
    {
        $this->resolveFrom([
            'roof' => [
                'api_key'  => 'roof-key',
                'base_url' => 'https://roof.test',
                'timeout'  => 3,
            ],
        ]);

        $this->manager->matrix('roof')->send(42);

        $client = $this->httpClient('roof-key');

        $this->assertSame('https://roof.test', $client->getBaseUrl());
        $this->assertSame(3, $client->getTimeout());
        $this->assertSame(10, $client->getConnectTimeout(), 'Unset keys fall back to the global value');
    }

    public function testConfiguredMatricesAreNotHandedToTheResolver()
    {
        $this->resolveFrom(['entrance' => 'resolver-key']);

        $webhook = $this->manager->matrix('entrance')->webhook();

        $this->assertSame('entrance-key', $webhook->getApiKey());
        $this->assertSame([], $this->lookups);
    }

    public function testUnknownMatrixIsReportedWhenTheResolverDeclines()
    {
        $this->resolveFrom([]);

        $this->expectException(MatrixNotConfiguredException::class);
        $this->expectExceptionMessage('Matrix [roof] is not defined');

        $this->manager->matrix('roof');
    }

    public function testResolverResultIsNotCached()
    {
        $this->resolveFrom(['roof' => 'roof-key']);

        $first  = $this->manager->matrix('roof');
        $second = $this->manager->matrix('roof');

        $this->assertNotSame($first, $second, 'A rotated key must not be hidden by a cache');
        $this->assertSame(['roof', 'roof'], $this->lookups);
    }

    public function testResolverIsConsultedWhenTheQueuedJobRuns()
    {
        $this->resolveFrom(['roof' => 'roof-key']);

        $job = new SendSceneJob('roof', 42, ['count' => 15]);

        $this->assertStringNotContainsString('roof-key', serialize($job));

        $job->handle($this->manager);

        $this->assertSame(
            ['scene_id' => 42, 'variables' => ['count' => '15']],
            $this->httpClient('roof-key')->getLastBody()
        );
    }

    public function testResolvedMatrixCanBeQueuedByName()
    {
        Queue::fake();

        $this->resolveFrom(['roof' => 'roof-key']);

        $this->manager->matrix('roof')->queue(42);

        Queue::assertPushed(SendSceneJob::class, function ($job) {
            return $job->matrix === 'roof' && strpos(serialize($job), 'roof-key') === false;
        });
    }

    public function testSendsWithAnApiKeyHandedInDirectly()
    {
        EasyPixel::withApiKey('adhoc-key')->send(42, ['count' => 15]);

        $client = $this->httpClient('adhoc-key');

        $this->assertSame('https://api.test/api/webhook/adhoc-key', $client->getLastRequest()['url']);
        $this->assertSame(['scene_id' => 42, 'variables' => ['count' => '15']], $client->getLastBody());
    }

    public function testDirectApiKeyAcceptsOverrides()
    {
        EasyPixel::withApiKey('adhoc-key', ['base_url' => 'https://adhoc.test', 'timeout' => 2])->send(42);

        $client = $this->httpClient('adhoc-key');

        $this->assertSame('https://adhoc.test', $client->getBaseUrl());
        $this->assertSame(2, $client->getTimeout());
    }

    public function testDirectApiKeyWithoutAKeyIsRejected()
    {
        $this->expectException(MatrixNotConfiguredException::class);
        $this->expectExceptionMessage('has no API key');

        EasyPixel::withApiKey('');
    }

    public function testDirectApiKeyCannotBeQueued()
    {
        $this->expectException(MatrixNotConfiguredException::class);
        $this->expectExceptionMessage('cannot be queued');

        EasyPixel::withApiKey('adhoc-key')->queue(42);
    }

    /**
     * Register a resolver backed by a plain map, standing in for a table.
     *
     * @param array $matrices
     * @return void
     */
    private function resolveFrom(array $matrices)
    {
        $lookups = &$this->lookups;

        $this->manager->resolveMatrixUsing(function ($name) use ($matrices, &$lookups) {
            $lookups[] = $name;

            return isset($matrices[$name]) ? $matrices[$name] : null;
        });
    }
}
