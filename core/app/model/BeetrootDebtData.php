<?php
/**
 * Modelo del modulo Beterrware: deudas (bw_debt) y abonos (bw_payment).
 */
class BeetrootDebtData {
        public static $tablename = "bw_debt";
        public $id;
        public $client_id;
        public $product_id;
        public $amount;
        public $quantity;
        public $paid;
        public $note;
        public $status;
        public $created_at;

        private static function db(): \PDO {
                return Database::getPdo();
        }

        public function create(array $d): void {
                $stmt = self::db()->prepare(
                        "insert into ".self::$tablename." (client_id, product_id, amount, quantity, paid, note, status, created_at)
                         values (:client_id, :product_id, :amount, :quantity, 0, :note, 'pendiente', NOW())"
                );

                // Total de unidades = suma de las lineas (o la cantidad suelta si no hay lineas).
                $totalQty = (int)($d['quantity'] ?? 0);
                if (!empty($d['items']) && is_array($d['items'])) {
                        $totalQty = array_sum(array_map(fn($it) => (int)($it['quantity'] ?? 0), $d['items']));
                }

                $stmt->execute([
                        'client_id' => (int)$d['client_id'],
                        'product_id' => !empty($d['product_id']) ? (int)$d['product_id'] : null,
                        'amount' => (float)($d['amount'] ?? 0),
                        'quantity' => max(1, $totalQty),
                        'note' => $d['note'] ?? '',
                ]);
                $this->id = self::db()->lastInsertId();

                self::saveItems($this->id, $d['items'] ?? []);
        }

        /** Guarda las lineas de productos del pedido (hasta muchos productos por cliente). */
        public static function saveItems(int $debtId, array $items): void {
                $pdo = self::db();
                $del = $pdo->prepare("delete from bw_debt_item where debt_id = :id");
                $del->execute(['id' => $debtId]);

                $ins = $pdo->prepare(
                        "insert into bw_debt_item (debt_id, product_id, quantity, price) values (:debt_id, :product_id, :quantity, :price)"
                );
                foreach ($items as $it) {
                        if (empty($it['product_id']) || (int)($it['quantity'] ?? 0) <= 0) continue;
                        $ins->execute([
                                'debt_id' => $debtId,
                                'product_id' => (int)$it['product_id'],
                                'quantity' => (int)$it['quantity'],
                                'price' => (float)($it['price'] ?? 0),
                        ]);
                }
        }

        /** Lineas de un pedido con nombre/codigo/imagen del producto. */
        public static function getItemsByDebt($debtId): array {
                $stmt = self::db()->prepare(
                        "select i.*, p.name as product_name, p.code as product_code, p.image as product_image
                         from bw_debt_item i
                         join bw_product p on p.id = i.product_id
                         where i.debt_id = :id
                         order by p.name asc"
                );
                $stmt->execute(['id' => $debtId]);
                return $stmt->fetchAll(\PDO::FETCH_OBJ);
        }

        public static function getItemsByDebts(array $debtIds): array {
                if (empty($debtIds)) return [];
                $pdo = self::db();
                $placeholders = implode(',', array_fill(0, count($debtIds), '?'));
                $stmt = $pdo->prepare(
                        "select i.*, p.name as product_name, p.code as product_code, p.image as product_image
                         from bw_debt_item i
                         join bw_product p on p.id = i.product_id
                         where i.debt_id in ($placeholders)
                         order by p.name asc"
                );
                $stmt->execute(array_values($debtIds));
                $grouped = [];
                foreach ($stmt->fetchAll(\PDO::FETCH_OBJ) as $item) {
                        $grouped[$item->debt_id][] = $item;
                }
                return $grouped;
        }

        public static function getById($id) {
                $stmt = self::db()->prepare("select * from ".self::$tablename." where id = :id");
                $stmt->execute(['id' => $id]);
                $stmt->setFetchMode(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
                $found = $stmt->fetch();
                return $found ?: null;
        }

        public static function getAllWithDetails(): array {
                $stmt = self::db()->query(
                        "select d.*, c.name as client_name, p.name as product_name,
                                (d.amount - d.paid) as pending
                         from ".self::$tablename." d
                         join bw_client c on c.id = d.client_id
                         left join bw_product p on p.id = d.product_id
                         order by d.status asc, d.created_at desc"
                );
                return $stmt->fetchAll(\PDO::FETCH_OBJ);
        }

        /** Recalcula el estado (pagada/pendiente) segun amount vs paid. */
        public static function refreshStatus($id): void {
                $stmt = self::db()->prepare(
                        "update ".self::$tablename." set status = case when paid >= amount then 'pagada' else 'pendiente' end where id = :id"
                );
                $stmt->execute(['id' => $id]);
        }

        /** Edita el monto original de la deuda / cantidad / nota (editar el pendiente). */
        public function update(array $d): void {
                $stmt = self::db()->prepare(
                        "update ".self::$tablename." set amount=:amount, quantity=:quantity, note=:note where id=:id"
                );

                $totalQty = (int)($d['quantity'] ?? 1);
                if (!empty($d['items']) && is_array($d['items'])) {
                        $totalQty = array_sum(array_map(fn($it) => (int)($it['quantity'] ?? 0), $d['items']));
                }

                $stmt->execute([
                        'amount' => (float)($d['amount'] ?? 0),
                        'quantity' => max(1, $totalQty),
                        'note' => $d['note'] ?? '',
                        'id' => $this->id,
                ]);

                if (isset($d['items']) && is_array($d['items'])) {
                        self::saveItems($this->id, $d['items']);
                }

                self::refreshStatus($this->id);
        }

        public function delete(): void {
                $pdo = self::db();
                $stmt = $pdo->prepare("delete from bw_payment where debt_id = :id");
                $stmt->execute(['id' => $this->id]);
                $stmt = $pdo->prepare("delete from bw_debt_item where debt_id = :id");
                $stmt->execute(['id' => $this->id]);
                $stmt = $pdo->prepare("delete from ".self::$tablename." where id = :id");
                $stmt->execute(['id' => $this->id]);
        }

        // ---------------- Abonos ----------------

        public static function addPayment(int $debtId, float $amount, string $note = ''): void {
                $stmt = self::db()->prepare("insert into bw_payment (debt_id, amount, note, created_at) values (:debt_id, :amount, :note, NOW())");
                $stmt->execute(['debt_id' => $debtId, 'amount' => $amount, 'note' => $note]);

                $stmt = self::db()->prepare("update bw_debt set paid = paid + :amount where id = :id");
                $stmt->execute(['amount' => $amount, 'id' => $debtId]);

                self::refreshStatus($debtId);
        }

        public static function getPaymentsByDebt($debtId): array {
                $stmt = self::db()->prepare("select * from bw_payment where debt_id = :id order by created_at desc");
                $stmt->execute(['id' => $debtId]);
                return $stmt->fetchAll(\PDO::FETCH_OBJ);
        }

        /** Elimina un abono y reintegra su monto al acumulado de la deuda. */
        public static function deletePayment(int $paymentId): void {
                $pdo = self::db();
                $stmt = $pdo->prepare("select * from bw_payment where id = :id");
                $stmt->execute(['id' => $paymentId]);
                $payment = $stmt->fetch(\PDO::FETCH_OBJ);
                if (!$payment) return;

                $stmt = $pdo->prepare("update bw_debt set paid = greatest(0, paid - :amount) where id = :id");
                $stmt->execute(['amount' => $payment->amount, 'id' => $payment->debt_id]);

                $stmt = $pdo->prepare("delete from bw_payment where id = :id");
                $stmt->execute(['id' => $paymentId]);

                self::refreshStatus($payment->debt_id);
        }
}
