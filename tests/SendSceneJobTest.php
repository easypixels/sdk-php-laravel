<?php

namespace EasyPixel\Laravel\Tests;

use EasyPixel\Exception\ValidationException;
use EasyPixel\Laravel\Jobs\SendSceneJob;
use EasyPixel\Laravel\MatrixManager;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Mockery;

class SendSceneJobTest extends TestCase
{
    public function testSendsTheSceneThroughTheNamedMatrix()
    {
        $this->runJob(new SendSceneJob('lobby', 42, ['count' => 15]));

        $client = $this->httpClient('lobby-key');

        $this->assertSame('https://lobby.test/api/webhook/lobby-key', $client->getLastRequest()['url']);
        $this->assertSame(['scene_id' => 42, 'variables' => ['count' => '15']], $client->getLastBody());
    }

    public function testReleasesForTheRetryAfterDelayWhenThrottled()
    {
        $this->clientFor('entrance')->addResponse(
            429,
            ['message' => 'Too Many Attempts.'],
            ['retry-after' => '7']
        );

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('release')->once()->with(7);

        $this->runJob(new SendSceneJob('entrance', 42), $queueJob);
    }

    public function testReleasesForAMinuteWhenTheServerSendsNoRetryAfter()
    {
        $this->clientFor('entrance')->addResponse(429, ['message' => 'Too Many Attempts.']);

        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('release')->once()->with(SendSceneJob::DEFAULT_RETRY_AFTER);

        $this->runJob(new SendSceneJob('entrance', 42), $queueJob);
    }

    public function testOtherApiErrorsFailTheJob()
    {
        $this->clientFor('entrance')->addResponse(422, ['message' => 'No scenario assigned to this matrix.']);

        $this->expectException(ValidationException::class);

        $this->runJob(new SendSceneJob('entrance', 42));
    }

    public function testCarriesTheAttemptBudgetItWasDispatchedWith()
    {
        $job = new SendSceneJob('entrance', 42, [], 7);

        $this->assertSame(7, $job->tries);
    }

    /**
     * @param SendSceneJob                 $job
     * @param QueueJobContract|Mockery\MockInterface|null $queueJob
     * @return void
     */
    private function runJob(SendSceneJob $job, $queueJob = null)
    {
        if ($queueJob !== null) {
            $job->setJob($queueJob);
        }

        $job->handle($this->app->make(MatrixManager::class));
    }
}
