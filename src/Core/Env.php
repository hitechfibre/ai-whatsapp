<?php

namespace HitechFibre\Core;

/**
 * Loads .env file and merges values into Config.
 * Simple key=value parser — no library dependency.
 */
class Env
{
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded || !file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and blank lines
            if (str_starts_with($line, '#') || $line === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');

            $key   = trim($key);
            $value = trim($value);

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Don't overwrite existing env vars (allows docker-compose to override)
            if (!isset($_ENV[$key]) && !isset($_SERVER[$key])) {
                putenv("{$key}={$value}");
                $_ENV[$key]    = $value;
                $_SERVER[$key] = $value;
            }
        }

        self::$loaded = true;
        self::applyToConfig();
    }

    /**
     * Map ENV vars into the Config dot-notation keys.
     */
    private static function applyToConfig(): void
    {
        $map = [
            // App
            'APP_ENV'             => 'app.env',
            'APP_DEBUG'           => 'app.debug',
            'APP_WEBHOOK_SECRET'  => 'app.webhook_secret',

            // Database
            'DB_DRIVER'           => 'database.driver',
            'DB_HOST'             => 'database.host',
            'DB_PORT'             => 'database.port',
            'DB_NAME'             => 'database.name',
            'DB_USER'             => 'database.user',
            'DB_PASS'             => 'database.pass',
            'DB_PATH'             => 'database.path',

            // Redis
            'REDIS_HOST'          => 'redis.host',
            'REDIS_PORT'          => 'redis.port',
            'REDIS_PASSWORD'      => 'redis.password',
            'REDIS_DB'            => 'redis.db',

            // Splynx
            'SPLYNX_URL'          => 'splynx.url',
            'SPLYNX_API_KEY'      => 'splynx.api_key',
            'SPLYNX_API_SECRET'   => 'splynx.api_secret',

            // respond.io
            'RESPONDIO_API_KEY'   => 'respond_io.api_key',
            'RESPONDIO_INBOX_ID'  => 'respond_io.inbox_id',
            'RESPONDIO_TEAM_TECH'     => 'teams.tech_support',
            'RESPONDIO_TEAM_ACCOUNTS' => 'teams.accounts',
            'RESPONDIO_TEAM_SALES'    => 'teams.sales',

            // OpenAI
            'OPENAI_API_KEY'      => 'openai.api_key',
            'OPENAI_MODEL'        => 'openai.model',
            'OPENAI_ENABLED'      => 'openai.enabled',

            // Business hours
            'BH_TIMEZONE'         => 'business_hours.timezone',
            'BH_MON_FRI_START'    => 'business_hours.mon_fri_start',
            'BH_MON_FRI_END'      => 'business_hours.mon_fri_end',
            'BH_SAT_START'        => 'business_hours.sat_start',
            'BH_SAT_END'          => 'business_hours.sat_end',

            // Admin
            'ADMIN_USERNAME'      => 'admin.username',
            'ADMIN_PASSWORD'      => 'admin.password',
            'ADMIN_SECRET_PATH'   => 'admin.secret_path',
        ];

        foreach ($map as $envKey => $configKey) {
            $val = getenv($envKey);
            if ($val !== false && $val !== '') {
                // Coerce booleans
                if (in_array(strtolower($val), ['true', 'false'])) {
                    $val = strtolower($val) === 'true';
                }
                Config::set($configKey, $val);
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $val = getenv($key);
        return $val !== false ? $val : $default;
    }
}
