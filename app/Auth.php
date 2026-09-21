<?php
declare(strict_types=1);

namespace AcquaVale;

final class Auth {
    public static function login(string $email,string $password): bool {
        $expected=(string)\cfg('admin.email','');
        $hash=(string)\cfg('admin.password_hash','');
        if ($expected!=='' && $hash!=='' && hash_equals(strtolower($expected),strtolower(trim($email))) && password_verify($password,$hash)) {
            session_regenerate_id(true);
            $_SESSION['admin']=['email'=>$expected,'at'=>time()];
            return true;
        }
        return false;
    }

    public static function check(): bool {
        return !empty($_SESSION['admin']['email']);
    }

    public static function require(): void {
        if (!self::check()) \redirect(\url('admin.php?action=login'));
    }

    public static function logout(): void {
        unset($_SESSION['admin']);
        session_regenerate_id(true);
    }
}
