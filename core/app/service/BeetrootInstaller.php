<?php
namespace App\Service;

use Database;

/**
 * Instalador del modulo Beterrware.
 *
 * Crea las tablas propias (bw_*) si no existen, para que la seccion funcione
 * como un ecosistema aparte sin tocar el schema ni el codigo del nucleo.
 */
class BeetrootInstaller {
        private static bool $done = false;

        public static function ensure(): void {
                if (self::$done) return;
                self::$done = true;

                $pdo = \Database::getPdo();

                $pdo->exec("CREATE TABLE IF NOT EXISTS bw_product (
                        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(120) NOT NULL,
                        description TEXT,
                        stock INT NOT NULL DEFAULT 0,
                        price FLOAT NOT NULL DEFAULT 0,
                        code VARCHAR(50),
                        image VARCHAR(255),
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at DATETIME
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Migracion liviana: si la tabla ya existia sin columna image, se agrega.
                $cols = $pdo->query("SHOW COLUMNS FROM bw_product LIKE 'image'")->fetchAll();
                if (empty($cols)) {
                        $pdo->exec("ALTER TABLE bw_product ADD COLUMN image VARCHAR(255) AFTER code");
                }

                $pdo->exec("CREATE TABLE IF NOT EXISTS bw_client (
                        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(120) NOT NULL,
                        phone VARCHAR(50),
                        address VARCHAR(255),
                        note TEXT,
                        created_at DATETIME
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS bw_debt (
                        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        client_id INT NOT NULL,
                        product_id INT,
                        amount FLOAT NOT NULL DEFAULT 0,
                        quantity INT NOT NULL DEFAULT 1,
                        paid FLOAT NOT NULL DEFAULT 0,
                        note VARCHAR(255),
                        status ENUM('pendiente','pagada') NOT NULL DEFAULT 'pendiente',
                        created_at DATETIME,
                        INDEX idx_client (client_id),
                        INDEX idx_product (product_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Pedido con multiples productos: cada deuda puede tener varias lineas
                // (producto + cantidad + precio unitario). La suma de las lineas es el
                // monto de la deuda; si no hay lineas se usa el campo product_id/quantity.
                $pdo->exec("CREATE TABLE IF NOT EXISTS bw_debt_item (
                        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        debt_id INT NOT NULL,
                        product_id INT NOT NULL,
                        quantity INT NOT NULL DEFAULT 1,
                        price FLOAT NOT NULL DEFAULT 0,
                        INDEX idx_debt (debt_id),
                        INDEX idx_product (product_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $pdo->exec("CREATE TABLE IF NOT EXISTS bw_payment (
                        id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                        debt_id INT NOT NULL,
                        amount FLOAT NOT NULL,
                        note VARCHAR(255),
                        created_at DATETIME,
                        INDEX idx_debt (debt_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
}
