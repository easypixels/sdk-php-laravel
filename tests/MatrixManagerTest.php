<?php

namespace EasyPixel\Laravel\Tests;

use EasyPixel\Laravel\Exception\MatrixNotConfiguredException;
use EasyPixel\Laravel\Facades\EasyPixel;
use EasyPixel\Laravel\MatrixConnection;
use EasyPixel\Laravel\MatrixManager;

class MatrixManagerTest extends TestCase
{
    /** @var MatrixManager */
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make(MatrixManager::class);
    }

    public function testResolvesTheDefaultMatrixWhenNoNameIsGiven()
    {
        $this->assertSame('entrance', $this->manager->getDefaultMatrix());
        $this->assertInstanceOf(MatrixConnection::class, $this->manager->matrix());
        $this->assertSame('entrance', $this->manager->matrix()->getName());
    }

    public function testCachesOneConnectionPerMatrix()
    {
        $this->assertSame($this->manager->matrix('lobby'), $this->manager->matrix('lobby'));
        $this->assertNotSame($this->manager->matrix('lobby'), $this->manager->matrix('entrance'));
    }

    public function testForgetConnectionsRebuildsFromCurrentConfig()
    {
        $first = $this->manager->matrix('entrance');

        $this->manager->forgetConnections();

        $this->assertNotSame($first, $this->manager->matrix('entrance'));
    }

    public function testUnknownMatrixIsRejected()
    {
        $this->expectException(MatrixNotConfiguredException::class);
        $this->expectExceptionMessage('Matrix [roof] is not defined');

        $this->manager->matrix('roof');
    }

    public function testMatrixWithoutApiKeyIsRejected()
    {
        $this->expectException(MatrixNotConfiguredException::class);
        $this->expectExceptionMessage('has no API key');

        $this->manager->matrix('blank');
    }

    public function testMalformedMatrixEntryIsRejected()
    {
        $this->app['config']->set('easypixel.matrices.broken', 42);

        $this->expectException(MatrixNotConfiguredException::class);
        $this->expectExceptionMessage('must be configured as an API key string or an array');

        $this->manager->matrix('broken');
    }

    public function testGlobalDefaultsReachTheHttpClient()
    {
        $this->manager->matrix('entrance')->send(42);

        $client = $this->httpClient('entrance-key');

        $this->assertSame('https://api.test', $client->getBaseUrl());
        $this->assertSame(30, $client->getTimeout());
        $this->assertSame(10, $client->getConnectTimeout());
    }

    public function testPerMatrixSettingsOverrideTheGlobalOnes()
    {
        $this->manager->matrix('lobby')->send(42);

        $client = $this->httpClient('lobby-key');

        $this->assertSame('https://lobby.test', $client->getBaseUrl());
        $this->assertSame(5, $client->getTimeout());
        $this->assertSame(10, $client->getConnectTimeout(), 'Unset keys still fall back to the global value');
    }

    public function testApiKeyTravelsInTheWebhookUrl()
    {
        $this->manager->matrix('entrance')->send(42);

        $request = $this->httpClient('entrance-key')->getLastRequest();

        $this->assertSame('https://api.test/api/webhook/entrance-key', $request['url']);
        $this->assertSame('POST', $request['method']);
    }

    public function testFacadeProxiesUnknownCallsToTheDefaultMatrix()
    {
        EasyPixel::send(42, ['count' => 15]);

        $this->assertSame(
            ['scene_id' => 42, 'variables' => ['count' => '15']],
            $this->httpClient('entrance-key')->getLastBody()
        );
    }

    public function testListsConfiguredMatrixNames()
    {
        $this->assertSame(['entrance', 'lobby', 'blank'], $this->manager->getMatrixNames());
    }
}
