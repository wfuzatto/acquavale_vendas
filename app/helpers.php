<?php
declare(strict_types=1);

use AcquaVale\Database;

function db(): PDO { return Database::connection(); }

function e(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
}

function money(float|string|int $v): string {
    return 'R$ '.number_format((float)$v, 2, ',', '.');
}

function base_path(): string {
    $path=trim((string)cfg('app.base_path',''));
    if ($path==='' || $path==='/') return '';
    return '/'.trim($path,'/');
}

function url(string $path=''): string {
    $path='/'.ltrim($path,'/');
    return base_path().($path==='/' ? '/' : $path);
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_validate(): void {
    $token=$_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
}

function redirect(string $target): never {
    header('Location: '.$target);
    exit;
}

function random_code(string $prefix='AQV'): string {
    return $prefix.'-'.strtoupper(bin2hex(random_bytes(5)));
}

function api_bearer(): ?string {
    $h=$_SERVER['HTTP_AUTHORIZATION'] ?? '';
    return preg_match('/Bearer\s+(.+)/i',$h,$m) ? trim($m[1]) : null;
}

function require_api_key(): void {
    $expected=(string)cfg('api.key','');
    $provided=api_bearer() ?? ($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($expected==='' || $provided==='' || !hash_equals($expected,$provided)) {
        json_response(['ok'=>false,'error'=>'unauthorized'],401);
    }
}

function json_input(): array {
    $raw=file_get_contents('php://input') ?: '';
    if ($raw==='') return $_POST;
    $d=json_decode($raw,true);
    return is_array($d) ? $d : $_POST;
}

function json_response(array $data,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_document(string $v): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/','',$v) ?? '');
}

function client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}
