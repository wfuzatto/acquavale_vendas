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

        self::migrate($pdo, '20260921_fix_locker_utf8', function(PDO $pdo): void {
            $update=$pdo->prepare(
                "UPDATE products
                 SET name=?, description=?
                 WHERE sku='LOCKER-DIA'"
            );
            $update->execute([
                'Locker diário',
                'Locação de armário durante o período de visita.'
            ]);
        });

        self::migrate($pdo, '20260921_claim_consumer', function(PDO $pdo): void {
            $column=$pdo->query("SHOW COLUMNS FROM orders LIKE 'integration_claim_consumer'")->fetch();
            if (!$column) {
                $pdo->exec("ALTER TABLE orders ADD COLUMN integration_claim_consumer VARCHAR(100) NULL AFTER integration_claim_token");
            }
        });

        self::migrate($pdo, '20260921_order_reservation_gate', function(PDO $pdo): void {
            $columns=[
                'expresso_reservation_id' => "ALTER TABLE orders ADD COLUMN expresso_reservation_id VARCHAR(190) NULL AFTER buyer_phone",
                'expresso_reservation_code' => "ALTER TABLE orders ADD COLUMN expresso_reservation_code VARCHAR(100) NULL AFTER expresso_reservation_id",
                'expresso_guest_name' => "ALTER TABLE orders ADD COLUMN expresso_guest_name VARCHAR(190) NULL AFTER expresso_reservation_code",
                'expresso_guest_cpf' => "ALTER TABLE orders ADD COLUMN expresso_guest_cpf VARCHAR(40) NULL AFTER expresso_guest_name",
                'expresso_checkin_date' => "ALTER TABLE orders ADD COLUMN expresso_checkin_date VARCHAR(40) NULL AFTER expresso_guest_cpf",
                'expresso_checkout_date' => "ALTER TABLE orders ADD COLUMN expresso_checkout_date VARCHAR(40) NULL AFTER expresso_checkin_date",
                'expresso_adults' => "ALTER TABLE orders ADD COLUMN expresso_adults VARCHAR(20) NULL AFTER expresso_checkout_date",
                'expresso_children' => "ALTER TABLE orders ADD COLUMN expresso_children VARCHAR(20) NULL AFTER expresso_adults",
                'expresso_uh' => "ALTER TABLE orders ADD COLUMN expresso_uh VARCHAR(80) NULL AFTER expresso_children",
                'expresso_reservation_snapshot' => "ALTER TABLE orders ADD COLUMN expresso_reservation_snapshot JSON NULL AFTER expresso_uh",
                'reservation_verified_at' => "ALTER TABLE orders ADD COLUMN reservation_verified_at DATETIME NULL AFTER expresso_reservation_snapshot",
            ];

            foreach ($columns as $column=>$sql) {
                $check=$pdo->query("SHOW COLUMNS FROM orders LIKE ".$pdo->quote($column))->fetch();
                if (!$check) $pdo->exec($sql);
            }

            $index=$pdo->query("SHOW INDEX FROM orders WHERE Key_name='idx_orders_expresso_reservation'")->fetch();
            if (!$index) {
                $pdo->exec("CREATE INDEX idx_orders_expresso_reservation ON orders(expresso_reservation_code)");
            }
        });

        self::migrate($pdo, '20260921_ticket_integrations', function(PDO $pdo): void {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS ticket_integrations (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ticket_id BIGINT UNSIGNED NOT NULL,
                    consumer VARCHAR(100) NOT NULL,
                    state ENUM('pending','imported','syncing','confirmed','failed') NOT NULL DEFAULT 'pending',
                    external_reservation_id VARCHAR(190) NULL,
                    hcp_visitor_id VARCHAR(190) NULL,
                    hcp_reference VARCHAR(190) NULL,
                    message TEXT NULL,
                    details JSON NULL,
                    last_attempt_at DATETIME NULL,
                    confirmed_at DATETIME NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_ticket_integrations_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
                    UNIQUE KEY uq_ticket_consumer(ticket_id,consumer),
                    INDEX idx_ticket_integrations_state(state,updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        });
    }

    private static function migrate(PDO $pdo,string $migration,callable $callback): void {
        $check=$pdo->prepare("SELECT 1 FROM app_migrations WHERE migration=? LIMIT 1");
        $check->execute([$migration]);
        if ($check->fetchColumn()) return;

        $pdo->beginTransaction();
        try {
            $callback($pdo);
            $save=$pdo->prepare("INSERT INTO app_migrations(migration,applied_at) VALUES(?,NOW())");
            $save->execute([$migration]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
