<?php
declare(strict_types=1);

namespace AcquaVale;

use PDO;
use RuntimeException;

final class Database {
    private static ?PDO $pdo=null;
    private const PREFIX='acquavale_vendas_';

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
        self::migrateLegacyTableNames(self::$pdo);
        self::runMigrations(self::$pdo);

        return self::$pdo;
    }

    private static function migrateLegacyTableNames(PDO $pdo): void {
        $map=[
            'products'=>self::PREFIX.'products',
            'orders'=>self::PREFIX.'orders',
            'order_items'=>self::PREFIX.'order_items',
            'visitors'=>self::PREFIX.'visitors',
            'tickets'=>self::PREFIX.'tickets',
            'ticket_redemptions'=>self::PREFIX.'ticket_redemptions',
            'integration_receipts'=>self::PREFIX.'integration_receipts',
            'audit_log'=>self::PREFIX.'audit_log',
            'ticket_integrations'=>self::PREFIX.'ticket_integrations',
            'app_migrations'=>self::PREFIX.'app_migrations',
        ];

        $database=(string)$pdo->query("SELECT DATABASE()")->fetchColumn();
        if ($database==='') return;

        $exists=$pdo->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
             LIMIT 1"
        );

        $renames=[];
        foreach ($map as $legacy=>$prefixed) {
            $exists->execute([$database,$legacy]);
            $legacyExists=(bool)$exists->fetchColumn();

            $exists->execute([$database,$prefixed]);
            $prefixedExists=(bool)$exists->fetchColumn();

            if ($legacyExists && $prefixedExists) {
                // A previous release may have left an empty legacy migration
                // ledger beside the populated prefixed ledger. Keep the empty
                // table untouched and continue using the prefixed one.
                if ($legacy==='app_migrations' && !$pdo->query("SELECT 1 FROM `{$legacy}` LIMIT 1")->fetchColumn()) {
                    continue;
                }
                throw new RuntimeException(
                    "Conflito de tabelas: existem '{$legacy}' e '{$prefixed}'. " .
                    "A migração automática foi interrompida para evitar perda de dados."
                );
            }

            if ($legacyExists) {
                $renames[]="{$legacy} TO {$prefixed}";
            }
        }

        if ($renames) {
            $pdo->exec("RENAME TABLE ".implode(', ',$renames));
        }
    }

    private static function runMigrations(PDO $pdo): void {
        $migrations=self::PREFIX.'app_migrations';

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$migrations} (
                migration VARCHAR(190) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        self::migrate($pdo, '20260921_fix_locker_utf8', function(PDO $pdo): void {
            $update=$pdo->prepare(
                "UPDATE acquavale_vendas_products
                 SET name=?, description=?
                 WHERE sku='LOCKER-DIA'"
            );
            $update->execute([
                'Locker diário',
                'Locação de armário durante o período de visita.'
            ]);
        });

        self::migrate($pdo, '20260921_claim_consumer', function(PDO $pdo): void {
            $column=$pdo->query("SHOW COLUMNS FROM acquavale_vendas_orders LIKE 'integration_claim_consumer'")->fetch();
            if (!$column) {
                $pdo->exec("ALTER TABLE acquavale_vendas_orders ADD COLUMN integration_claim_consumer VARCHAR(100) NULL AFTER integration_claim_token");
            }
        });

        self::migrate($pdo, '20260921_order_reservation_gate', function(PDO $pdo): void {
            $columns=[
                'expresso_reservation_id' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_reservation_id VARCHAR(190) NULL AFTER buyer_phone",
                'expresso_reservation_code' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_reservation_code VARCHAR(100) NULL AFTER expresso_reservation_id",
                'expresso_guest_name' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_guest_name VARCHAR(190) NULL AFTER expresso_reservation_code",
                'expresso_guest_cpf' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_guest_cpf VARCHAR(40) NULL AFTER expresso_guest_name",
                'expresso_checkin_date' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_checkin_date VARCHAR(40) NULL AFTER expresso_guest_cpf",
                'expresso_checkout_date' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_checkout_date VARCHAR(40) NULL AFTER expresso_checkin_date",
                'expresso_adults' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_adults VARCHAR(20) NULL AFTER expresso_checkout_date",
                'expresso_children' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_children VARCHAR(20) NULL AFTER expresso_adults",
                'expresso_uh' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_uh VARCHAR(80) NULL AFTER expresso_children",
                'expresso_reservation_snapshot' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN expresso_reservation_snapshot JSON NULL AFTER expresso_uh",
                'reservation_verified_at' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN reservation_verified_at DATETIME NULL AFTER expresso_reservation_snapshot",
            ];

            foreach ($columns as $column=>$sql) {
                $check=$pdo->query("SHOW COLUMNS FROM acquavale_vendas_orders LIKE ".$pdo->quote($column))->fetch();
                if (!$check) $pdo->exec($sql);
            }

            $index=$pdo->query("SHOW INDEX FROM acquavale_vendas_orders WHERE Key_name='idx_orders_expresso_reservation'")->fetch();
            if (!$index) {
                $pdo->exec("CREATE INDEX idx_orders_expresso_reservation ON acquavale_vendas_orders(expresso_reservation_code)");
            }
        });

        self::migrate($pdo, '20260922_visitor_push_tracking', function(PDO $pdo): void {
            $columns=[
                'integration_push_attempts' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN integration_push_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER integration_processed_at",
                'integration_push_last_at' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN integration_push_last_at DATETIME NULL AFTER integration_push_attempts",
                'integration_push_last_error' => "ALTER TABLE acquavale_vendas_orders ADD COLUMN integration_push_last_error TEXT NULL AFTER integration_push_last_at",
            ];
            foreach ($columns as $column=>$sql) {
                $check=$pdo->query("SHOW COLUMNS FROM acquavale_vendas_orders LIKE ".$pdo->quote($column))->fetch();
                if (!$check) $pdo->exec($sql);
            }
        });

        self::migrate($pdo, '20260921_ticket_integrations', function(PDO $pdo): void {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS acquavale_vendas_ticket_integrations (
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
                    CONSTRAINT fk_acquavale_vendas_ticket_integrations_ticket FOREIGN KEY(ticket_id) REFERENCES acquavale_vendas_tickets(id) ON DELETE CASCADE,
                    UNIQUE KEY uq_ticket_consumer(ticket_id,consumer),
                    INDEX idx_ticket_integrations_state(state,updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        });
    }

    private static function migrate(PDO $pdo,string $migration,callable $callback): void {
        $migrations=self::PREFIX.'app_migrations';

        $check=$pdo->prepare("SELECT 1 FROM {$migrations} WHERE migration=? LIMIT 1");
        $check->execute([$migration]);
        if ($check->fetchColumn()) return;

        $pdo->beginTransaction();
        try {
            $callback($pdo);
            $save=$pdo->prepare("INSERT INTO {$migrations}(migration,applied_at) VALUES(?,NOW())");
            $save->execute([$migration]);
            // MySQL commits DDL implicitly, which may end the transaction.
            if ($pdo->inTransaction()) $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}
