CREATE TABLE IF NOT EXISTS products (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sku VARCHAR(40) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 description TEXT NULL,
 product_type ENUM('ticket','locker','extra') NOT NULL DEFAULT 'ticket',
 price DECIMAL(12,2) NOT NULL DEFAULT 0,
 ncm VARCHAR(10) NULL,
 cest VARCHAR(10) NULL,
 duration_days SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 validation_mode ENUM('once_total','once_per_day','unlimited_validity') NOT NULL DEFAULT 'once_total',
 requires_visitor TINYINT(1) NOT NULL DEFAULT 1,
 active TINYINT(1) NOT NULL DEFAULT 1,
 sort_order INT NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_products_active_sort (active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_code VARCHAR(32) NOT NULL UNIQUE,
 buyer_email VARCHAR(190) NOT NULL,
 buyer_phone VARCHAR(40) NOT NULL,
 status ENUM('pending_payment','paid','cancelled','refunded') NOT NULL DEFAULT 'pending_payment',
 payment_status ENUM('pending','approved','declined','refunded') NOT NULL DEFAULT 'pending',
 subtotal DECIMAL(12,2) NOT NULL,
 total DECIMAL(12,2) NOT NULL,
 paid_at DATETIME NULL,
 integration_status ENUM('not_ready','pending','claimed','processed','error') NOT NULL DEFAULT 'not_ready',
 integration_claim_token CHAR(64) NULL,
 integration_claimed_at DATETIME NULL,
 integration_processed_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 INDEX idx_orders_status_created(status,created_at),
 INDEX idx_orders_integration(integration_status,integration_claimed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 product_name VARCHAR(150) NOT NULL,
 unit_price DECIMAL(12,2) NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 ncm VARCHAR(10) NULL,
 cest VARCHAR(10) NULL,
 created_at DATETIME NOT NULL,
 CONSTRAINT fk_order_items_order FOREIGN KEY(order_id) REFERENCES orders(id),
 CONSTRAINT fk_order_items_product FOREIGN KEY(product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS visitors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 first_name VARCHAR(100) NOT NULL,
 last_name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL,
 phone VARCHAR(40) NOT NULL,
 document_type ENUM('CPF','RG','CNH') NOT NULL,
 document_number VARCHAR(40) NOT NULL,
 sex ENUM('feminino','masculino','outro','nao_informado') NOT NULL DEFAULT 'nao_informado',
 photo_path VARCHAR(255) NOT NULL,
 biometric_consent_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL,
 CONSTRAINT fk_visitors_order FOREIGN KEY(order_id) REFERENCES orders(id),
 INDEX idx_visitors_document(document_type,document_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tickets (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 product_id BIGINT UNSIGNED NOT NULL,
 visitor_id BIGINT UNSIGNED NOT NULL,
 ticket_code VARCHAR(40) NOT NULL UNIQUE,
 valid_from DATE NOT NULL,
 valid_to DATE NOT NULL,
 validation_mode ENUM('once_total','once_per_day','unlimited_validity') NOT NULL,
 status ENUM('pending','active','blocked','cancelled','used') NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL,
 CONSTRAINT fk_tickets_order FOREIGN KEY(order_id) REFERENCES orders(id),
 CONSTRAINT fk_tickets_product FOREIGN KEY(product_id) REFERENCES products(id),
 CONSTRAINT fk_tickets_visitor FOREIGN KEY(visitor_id) REFERENCES visitors(id),
 INDEX idx_tickets_validity(status,valid_from,valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ticket_redemptions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 visit_date DATE NOT NULL,
 gate_code VARCHAR(80) NULL,
 idempotency_key VARCHAR(100) NULL,
 validated_at DATETIME NOT NULL,
 source_ip VARCHAR(64) NULL,
 CONSTRAINT fk_redemptions_ticket FOREIGN KEY(ticket_id) REFERENCES tickets(id),
 UNIQUE KEY uq_ticket_day(ticket_id,visit_date),
 UNIQUE KEY uq_redemption_idempotency(idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS integration_receipts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 consumer VARCHAR(100) NOT NULL,
 external_reference VARCHAR(190) NULL,
 processed_at DATETIME NOT NULL,
 CONSTRAINT fk_integration_receipts_order FOREIGN KEY(order_id) REFERENCES orders(id),
 UNIQUE KEY uq_order_consumer(order_id,consumer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor VARCHAR(120) NOT NULL,
 action VARCHAR(120) NOT NULL,
 entity_type VARCHAR(80) NULL,
 entity_id VARCHAR(80) NULL,
 metadata JSON NULL,
 ip VARCHAR(64) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
