<?php

namespace EasyPixel\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \EasyPixel\Laravel\MatrixConnection matrix(string|null $name = null)
 * @method static \EasyPixel\Laravel\MatrixConnection withApiKey(string $apiKey, array $overrides = [])
 * @method static \EasyPixel\Laravel\MatrixManager resolveMatrixUsing(callable $resolver)
 * @method static string getDefaultMatrix()
 * @method static string[] getMatrixNames()
 * @method static \EasyPixel\Laravel\MatrixManager forgetConnections()
 * @method static array send(int $sceneId, array $variables = [])
 * @method static \Illuminate\Foundation\Bus\PendingDispatch queue(int $sceneId, array $variables = [])
 *
 * @see \EasyPixel\Laravel\MatrixManager
 */
class EasyPixel extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \EasyPixel\Laravel\EasyPixelServiceProvider::ALIAS;
    }
}
