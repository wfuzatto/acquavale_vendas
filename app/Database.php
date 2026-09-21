<?php
declare(strict_types=1);

namespace AcquaVale;

use PDO;

final class Database {
    private static ?PDO $pdo=null;

    public static function connection(): PDO {
        if (self::$pdo) return self::$pdo;
        $host=\env('DB_HOST','127.0.0.1');
        $port=\env('DB_PORT','3306');
        $db=\env('DB_DATABASE','acquavale_vendas');
        $user=\env('DB_USERNAME','root');
        $pass=\env('DB_PASSWORD','');
        self::$pdo=new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user,$pass,
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
        );
        return self::$pdo;
    }
}
