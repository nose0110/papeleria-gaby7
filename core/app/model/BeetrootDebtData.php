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
                $stmt->execute([
                        'client_id' => (int)$d['client_id'],
                        'product_id' => !empty($d['product_id']) ? (int)$d['product_id'] : null,
                        'amount' => (float)($d['amount'] ?? 0),
                        'quantity' => (int)($d['quantity'] ?? 1),
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
                $stmt->execute([
                        'amount' => (float)($d['amount'] ?? 0),
                        'quantity' => (int)($d['quantity'] ?? 1),
                        'note' => $d['note'] ?? '',
                        'id' => $this->id,
                ]);
                self::refreshStatus($this->id);
        }

        public function delete(): void {
                $pdo = self::db();
                $stmt = $pdo->prepare("delete from bw_payment where debt_id = :id");
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
