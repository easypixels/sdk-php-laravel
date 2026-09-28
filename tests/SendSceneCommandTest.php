<?php

namespace EasyPixel\Laravel\Tests;

use EasyPixel\Laravel\Jobs\SendSceneJob;
use Illuminate\Support\Facades\Queue;

class SendSceneCommandTest extends TestCase
{
    public function testSendsToTheDefaultMatrix()
    {
        $this->artisan('easypixel:send', ['scene' => 42])->assertExitCode(0);

        $this->assertSame(
            ['scene_id' => 42],
            $this->httpClient('entrance-key')->getLastBody()
        );
    }

    public function testSendsToTheNamedMatrixWithVariables()
    {
        $this->artisan('easypixel:send', [
            'scene'    => 42,
            '--matrix' => 'lobby',
            '--var'    => ['count=15', 'label=Free spots'],
        ])->assertExitCode(0);

        $this->assertSame(
            ['scene_id' => 42, 'variables' => ['count' => '15', 'label' => 'Free spots']],
            $this->httpClient('lobby-key')->getLastBody()
        );
    }

    public function testKeepsEverythingAfterTheFirstEqualsSignInAValue()
    {
        $this->artisan('easypixel:send', [
            'scene' => 42,
            '--var' => ['url=https://example.test/?a=1'],
        ])->assertExitCode(0);

        $body = $this->httpClient('entrance-key')->getLastBody();

        $this->assertSame('https://example.test/?a=1', $body['variables']['url']);
    }

    public function testRejectsAVariableWithoutAValue()
    {
        $this->artisan('easypixel:send', ['scene' => 42, '--var' => ['count']])->assertExitCode(1);

        $this->assertSame([], $this->httpClients);
    }

    public function testReportsAnUnknownMatrix()
    {
        $this->artisan('easypixel:send', ['scene' => 42, '--matrix' => 'roof'])->assertExitCode(1);
    }

    public function testReportsAnApiError()
    {
        $this->clientFor('entrance')->addResponse(422, ['message' => 'Scene not found in assigned scenario.']);

        $this->artisan('easypixel:send', ['scene' => 999])->assertExitCode(1);
    }

    public function testReportsThrottling()
    {
        $this->clientFor('entrance')->addResponse(
            429,
            ['message' => 'Too Many Attempts.'],
            ['retry-after' => '7']
        );

        $this->artisan('easypixel:send', ['scene' => 42])->assertExitCode(1);
    }

    public function testQueuesInsteadOfSending()
    {
        Queue::fake();

        $this->artisan('easypixel:send', ['scene' => 42, '--queue' => true])->assertExitCode(0);

        Queue::assertPushed(SendSceneJob::class, function ($job) {
            return $job->matrix === 'entrance' && $job->sceneId === 42;
        });

        $this->assertSame([], $this->httpClient('entrance-key')->getRequests());
    }
}
