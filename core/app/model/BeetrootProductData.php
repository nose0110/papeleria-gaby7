<?php
/**
 * Modelo del modulo Beterrware: producto propio de la seccion (tabla bw_product).
 * No toca la tabla `product` del nucleo de Inventio Lite.
 */
class BeetrootProductData {
        public static $tablename = "bw_product";
        public $id;
        public $name;
        public $description;
        public $stock;
        public $price;
        public $code;
        public $is_active;
        public $created_at;

        private static function db(): \PDO {
                return Database::getPdo();
        }

        public function save(array $d): void {
                if (!empty($this->id)) {
                        $stmt = self::db()->prepare(
                                "update ".self::$tablename." set name=:name, description=:description, stock=:stock,
                                 price=:price, code=:code where id=:id"
                        );
                        $stmt->execute([
                                'name' => $d['name'] ?? '',
                                'description' => $d['description'] ?? '',
                                'stock' => (int)($d['stock'] ?? 0),
                                'price' => (float)($d['price'] ?? 0),
                                'code' => $d['code'] ?? '',
                                'id' => $this->id,
                        ]);
                        return;
                }

                $stmt = self::db()->prepare(
                        "insert into ".self::$tablename." (name, description, stock, price, code, is_active, created_at)
                         values (:name, :description, :stock, :price, :code, 1, NOW())"
                );
                $stmt->execute([
                        'name' => $d['name'] ?? '',
                        'description' => $d['description'] ?? '',
                        'stock' => (int)($d['stock'] ?? 0),
                        'price' => (float)($d['price'] ?? 0),
                        'code' => $d['code'] ?? '',
                ]);
                $this->id = self::db()->lastInsertId();
        }

        public static function getById($id) {
                $stmt = self::db()->prepare("select * from ".self::$tablename." where id = :id");
                $stmt->execute(['id' => $id]);
                $stmt->setFetchMode(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
                $found = $stmt->fetch();
                return $found ?: null;
        }

        public static function getAll(): array {
                $stmt = self::db()->query("select * from ".self::$tablename." order by name asc");
                return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
        }

        public static function getActive(): array {
                $stmt = self::db()->query("select * from ".self::$tablename." where is_active = 1 order by name asc");
                return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
        }

        public function deactivate(): void {
                $stmt = self::db()->prepare("update ".self::$tablename." set is_active = 0 where id = :id");
                $stmt->execute(['id' => $this->id]);
        }

        public function reactivate(): void {
                $stmt = self::db()->prepare("update ".self::$tablename." set is_active = 1 where id = :id");
                $stmt->execute(['id' => $this->id]);
        }

        /** Descuenta unidades al registrar una deuda (el inventario es solo del modulo). */
        public function addStock(int $delta): void {
                $stmt = self::db()->prepare("update ".self::$tablename." set stock = greatest(0, stock + :delta) where id = :id");
                $stmt->execute(['delta' => $delta, 'id' => $this->id]);
        }
}
