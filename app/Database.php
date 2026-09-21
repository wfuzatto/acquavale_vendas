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

        self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        self::runMigrations(self::$pdo);

        return self::$pdo;
    }

    private static function runMigrations(PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_migrations (
                migration VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $migration='20260921_fix_locker_utf8';
        $check=$pdo->prepare("SELECT 1 FROM app_migrations WHERE migration=? LIMIT 1");
        $check->execute([$migration]);
        if ($check->fetchColumn()) return;

        $pdo->beginTransaction();
        try {
            $update=$pdo->prepare(
                "UPDATE products
                 SET name=?, description=?
                 WHERE sku='LOCKER-DIA'"
            );
            $update->execute([
                'Locker diário',
                'Locação de armário durante o período de visita.'
            ]);

            $save=$pdo->prepare("INSERT INTO app_migrations(migration,applied_at) VALUES(?,NOW())");
            $save->execute([$migration]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
