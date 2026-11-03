<?php
declare(strict_types=1);

/**
 * Test bootstrap: a tiny CakePHP app (tests/test_app) on a throwaway SQLite database, with the
 * plugin's real migration applied and a `users` table standing in for the site's.
 */

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Datasource\ConnectionManager;
use Cake\Database\Connection;
use Cake\Database\Driver\Sqlite;
use Cake\Utility\Security;
use Migrations\TestSuite\Migrator;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
define('ROOT', dirname(__DIR__));
define('APP_DIR', 'src');
define('APP', ROOT . DS . 'tests' . DS . 'test_app' . DS . 'src' . DS);
define('CONFIG', ROOT . DS . 'tests' . DS . 'test_app' . DS . 'config' . DS);
define('WWW_ROOT', ROOT . DS . 'tests' . DS . 'test_app' . DS . 'webroot' . DS);
define('TESTS', ROOT . DS . 'tests' . DS);
define('TMP', sys_get_temp_dir() . DS . 'cake_mcp_tests' . DS);
define('LOGS', TMP . 'logs' . DS);
define('CACHE', TMP . 'cache' . DS);
define('CAKE_CORE_INCLUDE_PATH', ROOT . DS . 'vendor' . DS . 'cakephp' . DS . 'cakephp');
define('CORE_PATH', CAKE_CORE_INCLUDE_PATH . DS);
define('CAKE', CORE_PATH . 'src' . DS);

require ROOT . DS . 'vendor' . DS . 'autoload.php';
require CORE_PATH . 'config' . DS . 'bootstrap.php';

foreach ([TMP, LOGS, CACHE] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
}

date_default_timezone_set('UTC');
Configure::write('debug', true);
Configure::write('App', [
    'namespace' => 'App',
    'encoding' => 'UTF-8',
    'defaultLocale' => 'en_US',
    'defaultTimezone' => 'UTC',
    'base' => false,
    'dir' => 'src',
    'webroot' => 'webroot',
    'wwwRoot' => WWW_ROOT,
    'fullBaseUrl' => 'https://site.test',
    'imageBaseUrl' => 'img/',
    'cssBaseUrl' => 'css/',
    'jsBaseUrl' => 'js/',
    'paths' => [
        'plugins' => [ROOT . DS . 'plugins' . DS],
        'templates' => [ROOT . DS . 'tests' . DS . 'test_app' . DS . 'templates' . DS],
        'locales' => [],
    ],
]);
Configure::write('Session', ['defaults' => 'php']);
Security::setSalt('cake-mcp-test-salt-cake-mcp-test-salt');

Cache::setConfig([
    '_cake_core_' => ['engine' => 'Array', 'prefix' => 'cake_core_'],
    '_cake_model_' => ['engine' => 'Array', 'prefix' => 'cake_model_'],
    '_cake_routes_' => ['engine' => 'Array', 'prefix' => 'cake_routes_'],
]);

$database = TMP . 'test.sqlite';
if (is_file($database)) {
    unlink($database);
}
ConnectionManager::setConfig('test', [
    'className' => Connection::class,
    'driver' => Sqlite::class,
    'database' => $database,
    'timezone' => 'UTC',
    'quoteIdentifiers' => true,
    'cacheMetadata' => false,
]);
ConnectionManager::alias('test', 'default');

Plugin::getCollection()->add(new \CakeMcp\Plugin());
(new Migrator())->run(['plugin' => 'CakeMcp', 'connection' => 'test']);

ConnectionManager::get('test')->execute(
    'CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email VARCHAR(255),
        username VARCHAR(255),
        password VARCHAR(255),
        role VARCHAR(20),
        active BOOLEAN NOT NULL DEFAULT 1
    )'
);
