<?php

namespace EasyPixel\Laravel\Jobs;

use EasyPixel\Exception\RateLimitException;
use EasyPixel\Laravel\MatrixManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Shows a scene on a matrix from the queue.
 *
 * The job carries the matrix *name*, not its API key: a serialised job sits in
 * Redis or in the jobs table and shows up in Horizon and failed_jobs, and the
 * key is a credential that draws on somebody's display. The name is resolved
 * back into a connection at run time.
 */
class SendSceneJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Delay used when the API throttles without sending Retry-After. */
    const DEFAULT_RETRY_AFTER = 60;

    /** @var string Matrix name in config/easypixel.php */
    public $matrix;

    /** @var int */
    public $sceneId;

    /** @var array */
    public $variables;

    /**
     * @param string $matrix
     * @param int    $sceneId
     * @param array  $variables
     * @param int    $tries Total attempt budget, releases on 429 included
     */
    public function __construct($matrix, $sceneId, array $variables = [], $tries = 3)
    {
        $this->matrix    = $matrix;
        $this->sceneId   = (int) $sceneId;
        $this->variables = $variables;
        $this->tries     = (int) $tries;
    }

    /**
     * @param MatrixManager $manager
     * @return void
     *
     * @throws \EasyPixel\Exception\EasyPixelException
     */
    public function handle(MatrixManager $manager)
    {
        try {
            $manager->matrix($this->matrix)->send($this->sceneId, $this->variables);
        } catch (RateLimitException $e) {
            $retryAfter = $e->getRetryAfter();

            $this->release($retryAfter !== null ? $retryAfter : self::DEFAULT_RETRY_AFTER);
        }
    }
}
