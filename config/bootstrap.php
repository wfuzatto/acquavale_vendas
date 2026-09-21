<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_ROOT', APP_ROOT . '/storage');
define('LOCAL_CONFIG_FILE', APP_ROOT . '/config/config.local.php');
define('INSTALL_LOCK_FILE', STORAGE_ROOT . '/install.lock');

$GLOBALS['aqv_config'] = [];
if (is_file(LOCAL_CONFIG_FILE)) {
    $loaded = require LOCAL_CONFIG_FILE;
    if (!is_array($loaded)) {
        throw new RuntimeException('config/config.local.php deve retornar um array.');
    }
    $GLOBALS['aqv_config'] = $loaded;
}

function cfg(string $path, mixed $default = null): mixed {
    $value = $GLOBALS['aqv_config'] ?? [];
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) return $default;
        $value = $value[$part];
    }
    return $value;
}

function installed(): bool {
    return is_file(LOCAL_CONFIG_FILE) && is_file(INSTALL_LOCK_FILE);
}

if (PHP_SAPI !== 'cli' && !installed()) {
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== 'install.php') {
        header('Location: install.php');
        exit;
    }
}

date_default_timezone_set((string)cfg('app.timezone', 'America/Sao_Paulo'));

foreach ([STORAGE_ROOT.'/logs', STORAGE_ROOT.'/private/visitors', STORAGE_ROOT.'/cache'] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
}

ini_set('log_errors', '1');
ini_set('error_log', STORAGE_ROOT.'/logs/app.log');
if ((bool)cfg('app.debug', false)) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

spl_autoload_register(function(string $class): void {
    $prefix='AcquaVale\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative=substr($class, strlen($prefix));
    $file=APP_ROOT.'/app/'.str_replace('\\','/',$relative).'.php';
    if (is_file($file)) require $file;
});

require_once APP_ROOT.'/app/helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('acquavale_session');
    session_set_cookie_params([
        'httponly'=>true,
        'secure'=>(bool)cfg('app.session_secure', true),
        'samesite'=>'Lax',
        'path'=>base_path() ?: '/',
    ]);
    session_start();
}
