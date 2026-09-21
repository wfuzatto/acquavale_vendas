<?php
declare(strict_types=1);

namespace AcquaVale;

final class Auth {
    public static function login(string $email,string $password): bool {
        $expected=\env('ADMIN_EMAIL','admin@acquavale.local') ?? '';
        $plain=\env('ADMIN_PASSWORD','') ?? '';
        $hash=\env('ADMIN_PASSWORD_HASH','') ?? '';
        $ok=$hash!=='' ? password_verify($password,$hash) : ($plain!=='' && hash_equals($plain,$password));
        if (hash_equals(strtolower($expected),strtolower(trim($email))) && $ok) {
            session_regenerate_id(true);
            $_SESSION['admin']=['email'=>$expected,'at'=>time()];
            return true;
        }
        return false;
    }
    public static function check(): bool { return !empty($_SESSION['admin']['email']); }
    public static function require(): void { if (!self::check()) \redirect('/admin.php?action=login'); }
    public static function logout(): void { unset($_SESSION['admin']); session_regenerate_id(true); }
}
