<?php

namespace Baja;

use Baja\Model\Config;
use Baja\Model\ConfigQuery;

/**
 * System configuration helper class
 *
 * Provides easy access to configuration values stored in the config table.
 * Values are stored as JSON and automatically decoded/encoded.
 */
class SysConfig
{
    /**
     * In-memory cache for config values
     */
    private static array $cache = [];

    /**
     * Get a configuration value
     *
     * @param string $key The configuration key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The configuration value (decoded from JSON) or default
     */
    public static function get(string $key, $default = null)
    {
        // Check cache first
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $config = ConfigQuery::create()->findPk($key);
        if (!$config) {
            return $default;
        }

        $value = $config->getConfigValue();
        if ($value === null) {
            self::$cache[$key] = $default;
            return $default;
        }

        // Decode JSON value
        $decoded = json_decode($value, true);
        self::$cache[$key] = $decoded;
        return $decoded;
    }

    /**
     * Set a configuration value
     *
     * @param string $key The configuration key
     * @param mixed $value The value to store (will be JSON encoded)
     * @param string|null $description Optional description
     * @param int|null $updatedBy User ID who made the change
     * @return Config The config record
     */
    public static function set(string $key, $value, ?string $description = null, ?int $updatedBy = null): Config
    {
        $config = ConfigQuery::create()->findPk($key);

        if (!$config) {
            $config = new Config();
            $config->setConfigKey($key);
        }

        $config->setConfigValue(json_encode($value));

        if ($description !== null) {
            $config->setDescription($description);
        }

        $config->setUpdatedAt(new \DateTime());

        if ($updatedBy !== null) {
            $config->setUpdatedBy($updatedBy);
        }

        $config->save();

        // Update cache
        self::$cache[$key] = $value;

        return $config;
    }

    /**
     * Check if a configuration key exists
     *
     * @param string $key The configuration key
     * @return bool True if the key exists
     */
    public static function has(string $key): bool
    {
        if (array_key_exists($key, self::$cache)) {
            return true;
        }

        return ConfigQuery::create()->findPk($key) !== null;
    }

    /**
     * Delete a configuration value
     *
     * @param string $key The configuration key
     * @return bool True if deleted, false if not found
     */
    public static function delete(string $key): bool
    {
        $config = ConfigQuery::create()->findPk($key);
        if ($config) {
            $config->delete();
            unset(self::$cache[$key]);
            return true;
        }
        return false;
    }

    /**
     * Clear the in-memory cache
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Get all configuration values
     *
     * @return array Associative array of key => value
     */
    public static function getAll(): array
    {
        $configs = ConfigQuery::create()->find();
        $result = [];

        foreach ($configs as $config) {
            $value = $config->getConfigValue();
            $result[$config->getConfigKey()] = $value !== null ? json_decode($value, true) : null;
        }

        return $result;
    }
}
