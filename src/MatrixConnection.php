<?php

namespace EasyPixel\Laravel;

use EasyPixel\Laravel\Exception\MatrixNotConfiguredException;
use EasyPixel\Laravel\Jobs\SendSceneJob;
use EasyPixel\Webhook;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * One configured matrix: the SDK webhook client plus the queue settings that
 * apply when a scene is sent through the queue instead of inline.
 */
class MatrixConnection
{
    /** @var string|null Matrix name; null when built from a bare API key */
    protected $name;

    /** @var Webhook */
    protected $webhook;

    /** @var array Keys: 'connection', 'queue', 'tries' */
    protected $queueConfig;

    /**
     * @param string|null $name
     * @param Webhook     $webhook
     * @param array       $queueConfig
     */
    public function __construct($name, Webhook $webhook, array $queueConfig = [])
    {
        $this->name        = $name;
        $this->webhook     = $webhook;
        $this->queueConfig = $queueConfig;
    }

    /**
     * Send a scene to this matrix and wait for the API to accept it.
     *
     * @param int   $sceneId
     * @param array $variables
     * @return array Response: ['message', 'scene_id', 'matrix_id', 'variables_updated']
     *
     * @throws \EasyPixel\Exception\EasyPixelException
     */
    public function send($sceneId, array $variables = [])
    {
        return $this->webhook->send($sceneId, $variables);
    }

    /**
     * Send a scene to this matrix from a queued job.
     *
     * The job stores the matrix name and resolves the key when it runs, so a
     * connection built from a bare API key has nothing to store and is refused.
     *
     * @param int   $sceneId
     * @param array $variables
     * @return PendingDispatch
     *
     * @throws MatrixNotConfiguredException
     */
    public function queue($sceneId, array $variables = [])
    {
        if ($this->name === null) {
            throw MatrixNotConfiguredException::cannotQueueWithoutAName();
        }

        $tries = isset($this->queueConfig['tries']) ? (int) $this->queueConfig['tries'] : 3;

        $pending = SendSceneJob::dispatch($this->name, (int) $sceneId, $variables, $tries);

        if (! empty($this->queueConfig['connection'])) {
            $pending->onConnection($this->queueConfig['connection']);
        }

        if (! empty($this->queueConfig['queue'])) {
            $pending->onQueue($this->queueConfig['queue']);
        }

        return $pending;
    }

    /**
     * Name of this matrix, or null when it was built from a bare API key.
     *
     * @return string|null
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * The underlying SDK client, for anything the wrapper does not expose —
     * the API key, or the HttpClient and its timeouts.
     *
     * @return Webhook
     */
    public function webhook()
    {
        return $this->webhook;
    }
}
