<?php

namespace EasyPixel\Laravel\Console;

use EasyPixel\Exception\EasyPixelException;
use EasyPixel\Exception\RateLimitException;
use EasyPixel\Laravel\MatrixManager;
use Illuminate\Console\Command;

class SendSceneCommand extends Command
{
    /** @var string */
    protected $signature = 'easypixel:send
                            {scene : ID of the scene to show}
                            {--matrix= : Matrix name from config/easypixel.php; default one when omitted}
                            {--var=* : Scene variable as name=value, repeatable}
                            {--queue : Dispatch a queued job instead of sending inline}';

    /** @var string */
    protected $description = 'Show a scene on an EasyPixel matrix';

    /**
     * @param MatrixManager $manager
     * @return int
     */
    public function handle(MatrixManager $manager)
    {
        $sceneId = (int) $this->argument('scene');

        try {
            $variables = $this->parseVariables((array) $this->option('var'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $name = $this->option('matrix');

        try {
            $matrix = $manager->matrix($name !== null && $name !== '' ? $name : null);

            if ($this->option('queue')) {
                $matrix->queue($sceneId, $variables);

                $this->info(sprintf(
                    'Scene %d queued for matrix [%s].',
                    $sceneId,
                    $matrix->getName()
                ));

                return 0;
            }

            $response = $matrix->send($sceneId, $variables);
        } catch (RateLimitException $e) {
            $this->error('Throttled: ' . $e->getMessage());
            $this->line('Retry after: ' . ($e->getRetryAfter() !== null ? $e->getRetryAfter() . 's' : 'unknown'));

            return 1;
        } catch (EasyPixelException $e) {
            $this->error($e->getMessage());

            return 1;
        }

        $this->info(sprintf(
            'Scene %d accepted for matrix [%s].',
            isset($response['scene_id']) ? $response['scene_id'] : $sceneId,
            $matrix->getName()
        ));

        $updated = isset($response['variables_updated']) ? $response['variables_updated'] : [];

        if (! empty($variables)) {
            $this->line('Variables updated: ' . (empty($updated) ? 'none' : implode(', ', $updated)));

            $ignored = array_diff(array_keys($variables), $updated);

            if (! empty($ignored)) {
                $this->warn('Not defined in the scenario, ignored: ' . implode(', ', $ignored));
            }
        }

        return 0;
    }

    /**
     * Turn --var=name=value pairs into an associative array.
     *
     * @param array $pairs
     * @return array
     *
     * @throws \InvalidArgumentException
     */
    protected function parseVariables(array $pairs)
    {
        $variables = [];

        foreach ($pairs as $pair) {
            $parts = explode('=', $pair, 2);

            if (count($parts) !== 2 || $parts[0] === '') {
                throw new \InvalidArgumentException(
                    sprintf('Variable [%s] must be written as name=value.', $pair)
                );
            }

            $variables[$parts[0]] = $parts[1];
        }

        return $variables;
    }
}
