<?php

declare(strict_types=1);

namespace Pagekit\Module\Loader;

/**
 * Module configuration taken from the process environment.
 *
 * Registered last in the loader chain, so a variable that is set outranks the
 * module defaults and config.php alike. Container deployments configure an
 * installation this way; a classic installation sets nothing and the loader
 * stays inert.
 *
 * Values are read with getenv(), not from $_ENV, which is only populated when
 * the variables_order INI setting includes "E". A variable that is set but
 * empty counts as set and overrides with the empty value.
 */
final class EnvConfigLoader extends ConfigLoader
{
    /**
     * MySQL connection parameters, keyed by the variable that supplies them.
     * The port is handled apart from these because it needs an integer.
     */
    private const MYSQL_PARAMS = [
        'PAGEKIT_DB_HOST' => 'host',
        'PAGEKIT_DB_NAME' => 'dbname',
        'PAGEKIT_DB_USER' => 'user',
        'PAGEKIT_DB_PASSWORD' => 'password',
        'PAGEKIT_DB_PREFIX' => 'prefix',
    ];

    /**
     * The connections the database module ships, and therefore the accepted
     * values of PAGEKIT_DB_DRIVER.
     */
    private const CONNECTIONS = ['mysql', 'sqlite'];

    public function __construct()
    {
        parent::__construct(self::readEnvironment());
    }

    /**
     * @return array<string, mixed> module name => configuration override
     */
    private static function readEnvironment(): array
    {
        $values = [];

        if (($debug = self::env('PAGEKIT_DEBUG')) !== null) {
            $values['application'] = ['debug' => filter_var($debug, FILTER_VALIDATE_BOOLEAN)];
        }

        if (($secret = self::env('PAGEKIT_SECRET')) !== null) {
            $values['system'] = ['secret' => $secret];
        }

        if (($database = self::readDatabase()) !== []) {
            $values['database'] = $database;
        }

        if (($key = self::env('PAGEKIT_WEATHER_API_KEY')) !== null) {
            // The dashboard module stores its key under the literal, flat key
            // "weather.key". Arr::get() answers a flat key before it treats the
            // dot as a path, so the override has to use the same shape to be
            // the value that is found.
            $values['system/dashboard'] = ['weather.key' => $key];
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readDatabase(): array
    {
        $database = [];

        if (($driver = self::env('PAGEKIT_DB_DRIVER')) !== null) {
            $database['default'] = self::connection($driver);
        }

        $mysql = [];

        foreach (self::MYSQL_PARAMS as $name => $key) {
            if (($value = self::env($name)) !== null) {
                $mysql[$key] = $value;
            }
        }

        if (($port = self::env('PAGEKIT_DB_PORT')) !== null) {
            $mysql['port'] = (int) $port;
        }

        if ($mysql !== []) {
            $database['connections']['mysql'] = $mysql;
        }

        if (($path = self::env('PAGEKIT_DB_PATH')) !== null) {
            $database['connections']['sqlite']['path'] = $path;
        }

        return $database;
    }

    /**
     * The connection a driver name selects.
     *
     * An unknown name would otherwise surface much later, as a missing key
     * while the connection is being built.
     *
     * @throws \InvalidArgumentException
     */
    private static function connection(string $driver): string
    {
        if (!in_array($driver, self::CONNECTIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'PAGEKIT_DB_DRIVER must be one of "%s", got "%s".',
                implode('", "', self::CONNECTIONS),
                $driver
            ));
        }

        return $driver;
    }

    /**
     * The value of a variable, or null when it is not set at all.
     */
    private static function env(string $name): ?string
    {
        $value = getenv($name);

        return $value === false ? null : $value;
    }
}
