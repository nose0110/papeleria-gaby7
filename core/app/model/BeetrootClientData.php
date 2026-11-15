<?php
/**
 * Modelo del modulo Beterrware: cliente propio de la seccion (tabla bw_client).
 * No toca la tabla `person` del nucleo de Inventio Lite.
 */
class BeetrootClientData {
        public static $tablename = "bw_client";
        public $id;
        public $name;
        public $phone;
        public $address;
        public $note;
        public $created_at;

        private static function db(): \PDO {
                return Database::getPdo();
        }

        public function save(array $d): void {
                if (!empty($this->id)) {
                        $stmt = self::db()->prepare(
                                "update ".self::$tablename." set name=:name, phone=:phone, address=:address, note=:note where id=:id"
                        );
                        $stmt->execute([
                                'name' => $d['name'] ?? '',
                                'phone' => $d['phone'] ?? '',
                                'address' => $d['address'] ?? '',
                                'note' => $d['note'] ?? '',
                                'id' => $this->id,
                        ]);
                        return;
                }

                $stmt = self::db()->prepare(
                        "insert into ".self::$tablename." (name, phone, address, note, created_at)
                         values (:name, :phone, :address, :note, NOW())"
                );
                $stmt->execute([
                        'name' => $d['name'] ?? '',
                        'phone' => $d['phone'] ?? '',
                        'address' => $d['address'] ?? '',
                        'note' => $d['note'] ?? '',
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

        /**
         * Deudas con nombre de producto y saldo pendiente calculado en SQL.
         * $onlyPending: solo las que aun deben dinero.
         */
        public static function getDebtsByClient($clientId, bool $onlyPending = false): array {
                $sql = "select d.*, p.name as product_name,
                               (d.amount - d.paid) as pending
                        from bw_debt d
                        left join bw_product p on p.id = d.product_id
                        where d.client_id = :cid";
                if ($onlyPending) {
                        $sql .= " and d.status = 'pendiente'";
                }
                $sql .= " order by d.created_at desc";
                $stmt = self::db()->prepare($sql);
                $stmt->execute(['cid' => $clientId]);
                return $stmt->fetchAll(\PDO::FETCH_OBJ);
        }

        public static function getTotalPendingByClient($clientId): float {
                $stmt = self::db()->prepare("select coalesce(sum(amount - paid), 0) as total from bw_debt where client_id = :cid and status = 'pendiente'");
                $stmt->execute(['cid' => $clientId]);
                return (float)$stmt->fetch()->total;
        }

        public function delete(): void {
                $pdo = self::db();
                $stmt = $pdo->prepare("delete from bw_payment where debt_id in (select id from bw_debt where client_id = :cid)");
                $stmt->execute(['cid' => $this->id]);
                $stmt = $pdo->prepare("delete from bw_debt where client_id = :cid");
                $stmt->execute(['cid' => $this->id]);
                $stmt = $pdo->prepare("delete from ".self::$tablename." where id = :id");
                $stmt->execute(['id' => $this->id]);
        }
}
