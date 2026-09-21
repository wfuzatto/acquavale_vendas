<?php
declare(strict_types=1);

namespace AcquaVale;

use PDO;
use RuntimeException;

final class Database {
    private static ?PDO $pdo=null;

    public static function connection(): PDO {
        if (self::$pdo) return self::$pdo;

        $host=(string)\cfg('db.host','localhost');
        $port=(int)\cfg('db.port',3306);
        $name=(string)\cfg('db.name','');
        $user=(string)\cfg('db.user','');
        $pass=(string)\cfg('db.password','');

        if ($name==='' || $user==='') {
            throw new RuntimeException('Banco de dados ainda não configurado. Execute install.php.');
        }

        self::$pdo=new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES=>false,
            ]
        );
        return self::$pdo;
    }
}
