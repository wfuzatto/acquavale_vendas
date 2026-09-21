<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_ROOT', APP_ROOT . '/storage');

function loadEnv(string $file): void {
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, "\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}
loadEnv(APP_ROOT . '/.env');

function env(string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

date_default_timezone_set(env('APP_TIMEZONE', 'America/Sao_Paulo') ?: 'America/Sao_Paulo');

foreach ([STORAGE_ROOT.'/logs', STORAGE_ROOT.'/private/visitors', STORAGE_ROOT.'/cache'] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

ini_set('log_errors','1');
ini_set('error_log',STORAGE_ROOT.'/logs/app.log');
if (env('APP_DEBUG','false') === 'true') {
    ini_set('display_errors','1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors','0');
}

spl_autoload_register(function(string $class): void {
    $prefix='AcquaVale\\';
    if (!str_starts_with($class,$prefix)) return;
    $relative=substr($class,strlen($prefix));
    $file=APP_ROOT.'/app/'.str_replace('\\','/',$relative).'.php';
    if (is_file($file)) require $file;
});

require_once APP_ROOT.'/app/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('acquavale_session');
    session_set_cookie_params([
        'httponly'=>true,
        'secure'=>env('SESSION_SECURE','false') === 'true',
        'samesite'=>'Lax',
        'path'=>'/'
    ]);
    session_start();
}
