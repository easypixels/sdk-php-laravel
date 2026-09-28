<?php

namespace EasyPixel\Laravel\Exception;

use EasyPixel\Exception\EasyPixelException;

/**
 * A matrix was requested by a name that config/easypixel.php does not describe.
 *
 * Extends the SDK's own base exception so an integrator who already wraps calls
 * in catch (EasyPixelException) also catches a typo in the configuration.
 */
class MatrixNotConfiguredException extends EasyPixelException
{
    /**
     * @param string $name
     * @return self
     */
    public static function unknown($name)
    {
        return new static(sprintf(
            'Matrix [%s] is not defined in config/easypixel.php under "matrices".',
            $name
        ));
    }

    /**
     * @param string $name
     * @return self
     */
    public static function missingApiKey($name)
    {
        return new static(sprintf(
            'Matrix [%s] has no API key. Set it in config/easypixel.php or in the environment.',
            $name
        ));
    }

    /**
     * @return self
     */
    public static function cannotQueueWithoutAName()
    {
        return new static(
            'A matrix built from a bare API key cannot be queued: a job carries the matrix name '
            . 'and looks the key up when it runs. Register EasyPixel::resolveMatrixUsing() and '
            . 'queue through EasyPixel::matrix($name) instead.'
        );
    }

    /**
     * @param string $name
     * @param mixed  $value
     * @return self
     */
    public static function malformed($name, $value)
    {
        return new static(sprintf(
            'Matrix [%s] must be configured as an API key string or an array, %s given.',
            $name,
            gettype($value)
        ));
    }
}
