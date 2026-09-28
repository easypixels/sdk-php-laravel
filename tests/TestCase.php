<?php

namespace EasyPixel\Laravel\Tests;

use EasyPixel\Laravel\EasyPixelServiceProvider;
use EasyPixel\Laravel\Facades\EasyPixel;
use EasyPixel\Laravel\MatrixManager;
use EasyPixel\Laravel\Tests\Fixtures\RecordingHttpClient;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** @var RecordingHttpClient[] Keyed by the API key the client was built with */
    protected $httpClients = [];

    protected function getPackageProviders($app)
    {
        return [EasyPixelServiceProvider::class];
    }

    protected function getPackageAliases($app)
    {
        return ['EasyPixel' => EasyPixel::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('easypixel.base_url', 'https://api.test');
        $app['config']->set('easypixel.default', 'entrance');
        $app['config']->set('easypixel.timeout', 30);
        $app['config']->set('easypixel.connect_timeout', 10);
        $app['config']->set('easypixel.matrices', [
            'entrance' => 'entrance-key',
            'lobby'    => [
                'api_key'  => 'lobby-key',
                'base_url' => 'https://lobby.test',
                'timeout'  => 5,
            ],
            'blank' => '',
        ]);

        $clients = &$this->httpClients;

        $app->bind(EasyPixelServiceProvider::HTTP_FACTORY, function () use (&$clients) {
            return function (array $config) use (&$clients) {
                $client = new RecordingHttpClient(
                    $config['base_url'],
                    [],
                    (int) $config['timeout'],
                    (int) $config['connect_timeout']
                );

                $clients[$config['api_key']] = $client;

                return $client;
            };
        });
    }

    /**
     * The recording client of a matrix, built on the first resolution.
     *
     * @param string $name
     * @return RecordingHttpClient
     */
    protected function clientFor($name)
    {
        $webhook = $this->app->make(MatrixManager::class)->matrix($name)->webhook();

        return $this->httpClient($webhook->getApiKey());
    }

    /**
     * The recording client built for a matrix, identified by its API key.
     *
     * @param string $apiKey
     * @return RecordingHttpClient
     */
    protected function httpClient($apiKey)
    {
        $this->assertArrayHasKey($apiKey, $this->httpClients, "No client was built for key [{$apiKey}].");

        return $this->httpClients[$apiKey];
    }
}
