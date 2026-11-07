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
        public $image;
        public $is_active;
        public $created_at;

        private static function db(): \PDO {
                return Database::getPdo();
        }

        /** Asegura que la carpeta de imagenes del modulo exista (propia, no comparte con el nucleo). */
        public static function ensureStorageDir(): string {
                $dir = getcwd() . '/storage/beetroot';
                if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                }
                return $dir;
        }

        /** Buscador para el selector de productos: por nombre o codigo (insensible a mayusculas). */
        public static function search(string $term, int $limit = 5000): array {
                $sql = "select * from ".self::$tablename." where is_active = 1";
                $params = [];
                $term = trim($term);
                if ($term !== '') {
                        $sql .= " and (lower(name) like :t or lower(code) like :t or lower(description) like :t)";
                        $params[':t'] = '%' . mb_strtolower($term) . '%';
                }
                $sql .= " order by name asc limit " . max(1, $limit);
                $stmt = self::db()->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
        }

        public function save(array $d): void {
                if (!empty($this->id)) {
                        $stmt = self::db()->prepare(
                                "update ".self::$tablename." set name=:name, description=:description, stock=:stock,
                                 price=:price, code=:code, image=:image where id=:id"
                        );
                        $stmt->execute([
                                'name' => $d['name'] ?? '',
                                'description' => $d['description'] ?? '',
                                'stock' => (int)($d['stock'] ?? 0),
                                'price' => (float)($d['price'] ?? 0),
                                'code' => $d['code'] ?? '',
                                // Si no se subio nueva imagen, conserva la anterior.
                                'image' => !empty($d['image']) ? $d['image'] : $this->image,
                                'id' => $this->id,
                        ]);
                        return;
                }

                $stmt = self::db()->prepare(
                        "insert into ".self::$tablename." (name, description, stock, price, code, image, is_active, created_at)
                         values (:name, :description, :stock, :price, :code, :image, 1, NOW())"
                );
                $stmt->execute([
                        'name' => $d['name'] ?? '',
                        'description' => $d['description'] ?? '',
                        'stock' => (int)($d['stock'] ?? 0),
                        'price' => (float)($d['price'] ?? 0),
                        'code' => $d['code'] ?? '',
                        'image' => $d['image'] ?? null,
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
