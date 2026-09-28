<?php

namespace EasyPixel\Laravel;

use EasyPixel\HttpClient;
use EasyPixel\Laravel\Exception\MatrixNotConfiguredException;
use EasyPixel\Webhook;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves matrices into ready SDK clients.
 *
 * A name is looked up in config/easypixel.php first, then handed to the
 * resolver registered with resolveMatrixUsing() — that is where a key kept in
 * the database comes from.
 *
 * Configured matrices are cached, because the SDK keeps one HttpClient per
 * Webhook and is meant to be reused. Resolved ones are not: the resolver owns
 * the lookup and stays free to return a rotated key, which a cache in a
 * long-running worker would hide.
 */
class MatrixManager
{
    /** @var Container */
    protected $app;

    /** @var MatrixConnection[] Keyed by matrix name */
    protected $connections = [];

    /** @var callable|null Takes a matrix name, returns an API key, a config array or null */
    protected $resolver;

    public function __construct(Container $app)
    {
        $this->app = $app;
    }

    /**
     * Teach the manager to find matrices this application does not list in its
     * configuration — in a database table, say:
     *
     *     EasyPixel::resolveMatrixUsing(function ($name) {
     *         return Display::where('slug', $name)->value('easypixel_key');
     *     });
     *
     * The callback returns the API key, or an array with 'api_key' and any of
     * 'base_url', 'timeout' and 'connect_timeout'; null means "not mine" and
     * lets the manager report an unknown matrix.
     *
     * Register it in a service provider's boot(). The lookup runs on every
     * call, so cache inside the callback when it is expensive.
     *
     * @param callable $resolver
     * @return $this
     */
    public function resolveMatrixUsing(callable $resolver)
    {
        $this->resolver = $resolver;

        return $this;
    }

    /**
     * Get a configured matrix.
     *
     * @param string|null $name Null takes config('easypixel.default')
     * @return MatrixConnection
     *
     * @throws MatrixNotConfiguredException
     */
    public function matrix($name = null)
    {
        $name = $name !== null ? $name : $this->getDefaultMatrix();

        if (isset($this->connections[$name])) {
            return $this->connections[$name];
        }

        $matrices = $this->config('matrices', []);

        if (array_key_exists($name, $matrices)) {
            return $this->connections[$name] = $this->makeConnection(
                $name,
                $this->normaliseConfig($name, $matrices[$name])
            );
        }

        $resolved = $this->resolver !== null ? call_user_func($this->resolver, $name) : null;

        if ($resolved === null) {
            throw MatrixNotConfiguredException::unknown($name);
        }

        return $this->makeConnection($name, $this->normaliseConfig($name, $resolved));
    }

    /**
     * Build a one-off matrix around an API key the caller already has.
     *
     * The connection has no name, so it cannot be queued — a queued job carries
     * the name and resolves the key when it runs. Register a resolver and go
     * through matrix() to send from the queue.
     *
     * @param string $apiKey
     * @param array  $overrides Any of 'base_url', 'timeout', 'connect_timeout'
     * @return MatrixConnection
     *
     * @throws MatrixNotConfiguredException
     */
    public function withApiKey($apiKey, array $overrides = [])
    {
        $config = $this->normaliseConfig(
            'inline',
            array_merge($overrides, ['api_key' => $apiKey])
        );

        return $this->makeConnection(null, $config);
    }

    /**
     * Name of the matrix used when none is given.
     *
     * @return string
     */
    public function getDefaultMatrix()
    {
        return $this->config('default', 'default');
    }

    /**
     * Names of every configured matrix.
     *
     * @return string[]
     */
    public function getMatrixNames()
    {
        return array_keys($this->config('matrices', []));
    }

    /**
     * Drop cached connections, so the next call rereads the configuration.
     *
     * @return $this
     */
    public function forgetConnections()
    {
        $this->connections = [];

        return $this;
    }

    /**
     * Build the connection for a matrix.
     *
     * @param string|null $name   Null for a connection built from a bare API key
     * @param array       $config Normalised matrix configuration
     * @return MatrixConnection
     */
    protected function makeConnection($name, array $config)
    {
        $webhook = new Webhook(
            $config['api_key'],
            $config['base_url'],
            $this->makeHttpClient($config)
        );

        return new MatrixConnection($name, $webhook, $this->config('queue', []));
    }

    /**
     * Turn a matrix entry — from the configuration or from the resolver — into
     * a full configuration array, filling in the global defaults.
     *
     * @param string $name
     * @param mixed  $config An API key string or an array
     * @return array Keys: 'api_key', 'base_url', 'timeout', 'connect_timeout'
     *
     * @throws MatrixNotConfiguredException
     */
    protected function normaliseConfig($name, $config)
    {
        if (is_string($config)) {
            $config = ['api_key' => $config];
        }

        if (! is_array($config)) {
            throw MatrixNotConfiguredException::malformed($name, $config);
        }

        $config += [
            'base_url'        => $this->config('base_url', 'https://api.easypixel.ru'),
            'timeout'         => $this->config('timeout', 30),
            'connect_timeout' => $this->config('connect_timeout', 10),
        ];

        if (empty($config['api_key'])) {
            throw MatrixNotConfiguredException::missingApiKey($name);
        }

        return $config;
    }

    /**
     * Build the HTTP client through the container factory, so tests and
     * applications can swap the transport without touching this class.
     *
     * @param array $config
     * @return HttpClient
     */
    protected function makeHttpClient(array $config)
    {
        $factory = $this->app->make(EasyPixelServiceProvider::HTTP_FACTORY);

        return $factory($config);
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function config($key, $default = null)
    {
        return $this->app->make('config')->get('easypixel.' . $key, $default);
    }

    /**
     * Proxy unknown calls to the default matrix: EasyPixel::send(42).
     *
     * @param string $method
     * @param array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->matrix()->$method(...$parameters);
    }
}
