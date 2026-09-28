<?php

namespace EasyPixel\Laravel\Tests;

use EasyPixel\Exception\RateLimitException;
use EasyPixel\Exception\ValidationException;
use EasyPixel\Laravel\Jobs\SendSceneJob;
use EasyPixel\Laravel\MatrixManager;
use EasyPixel\Webhook;
use Illuminate\Support\Facades\Queue;

class MatrixConnectionTest extends TestCase
{
    /** @var MatrixManager */
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make(MatrixManager::class);
    }

    public function testSendReturnsTheDecodedResponse()
    {
        $connection = $this->manager->matrix('entrance');

        $this->clientFor('entrance')->addResponse(202, [
            'message'           => 'Webhook accepted.',
            'scene_id'          => 42,
            'matrix_id'         => 7,
            'variables_updated' => ['count'],
        ]);

        $response = $connection->send(42, ['count' => 15]);

        $this->assertSame(['count'], $response['variables_updated']);
        $this->assertSame(7, $response['matrix_id']);
    }

    public function testSdkExceptionsPassThroughUntouched()
    {
        $connection = $this->manager->matrix('entrance');

        $this->clientFor('entrance')->addResponse(422, [
            'message' => 'Scene not found in assigned scenario.',
        ]);

        $this->expectException(ValidationException::class);

        $connection->send(999);
    }

    public function testRateLimitExceptionCarriesRetryAfter()
    {
        $connection = $this->manager->matrix('entrance');

        $this->clientFor('entrance')->addResponse(
            429,
            ['message' => 'Too Many Attempts.'],
            ['retry-after' => '7']
        );

        try {
            $connection->send(42);
            $this->fail('RateLimitException was not thrown.');
        } catch (RateLimitException $e) {
            $this->assertSame(7, $e->getRetryAfter());
        }
    }

    public function testWebhookExposesTheUnderlyingSdkClient()
    {
        $webhook = $this->manager->matrix('lobby')->webhook();

        $this->assertInstanceOf(Webhook::class, $webhook);
        $this->assertSame('lobby-key', $webhook->getApiKey());
    }

    public function testQueueDispatchesTheJobWithoutTheApiKey()
    {
        Queue::fake();

        $this->manager->matrix('entrance')->queue(42, ['count' => 15]);

        Queue::assertPushed(SendSceneJob::class, function ($job) {
            return $job->matrix === 'entrance'
                && $job->sceneId === 42
                && $job->variables === ['count' => 15]
                && strpos(serialize($job), 'entrance-key') === false;
        });
    }

    public function testQueueHonoursTheConfiguredConnectionQueueAndTries()
    {
        Queue::fake();

        $this->app['config']->set('easypixel.queue.connection', 'redis');
        $this->app['config']->set('easypixel.queue.queue', 'displays');
        $this->app['config']->set('easypixel.queue.tries', 7);

        $this->manager->forgetConnections()->matrix('entrance')->queue(42);

        Queue::assertPushedOn('displays', SendSceneJob::class, function ($job) {
            return $job->connection === 'redis' && $job->tries === 7;
        });
    }

    public function testQueueFallsBackToTheApplicationDefaults()
    {
        Queue::fake();

        $this->manager->matrix('entrance')->queue(42);

        Queue::assertPushed(SendSceneJob::class, function ($job) {
            return $job->connection === null && $job->queue === null;
        });
    }
}
