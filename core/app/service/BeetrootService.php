<?php
namespace App\Service;

/**
 * Servicio del modulo Beterrware (seccion aparte para la gestion de la mama).
 * Toda la logica habla unicamente con las tablas bw_*, sin tocar los servicios
 * ni tablas del nucleo de Inventio Lite.
 */
class BeetrootService {

        // ---------- Dashboard ----------

        public function getSummary(): array {
                $pdo = \Database::getPdo();

                $clients = (int)$pdo->query("select count(*) from bw_client")->fetchColumn();
                $products = (int)$pdo->query("select count(*) from bw_product where is_active = 1")->fetchColumn();
                $totalPending = (float)$pdo->query("select coalesce(sum(amount - paid), 0) from bw_debt where status = 'pendiente'")->fetchColumn();
                $pendingDebts = (int)$pdo->query("select count(*) from bw_debt where status = 'pendiente'")->fetchColumn();

                return [
                        'clients' => $clients,
                        'products' => $products,
                        'total_pending' => $totalPending,
                        'pending_debts' => $pendingDebts,
                ];
        }

        // ---------- Productos ----------

        public function getAllProducts(): array {
                return \BeetrootProductData::getAll();
        }

        public function getActiveProducts(): array {
                return \BeetrootProductData::getActive();
        }

        public function getProductById($id) {
                return \BeetrootProductData::getById($id);
        }

        public function createProduct(array $data): void {
                $product = new \BeetrootProductData();
                $product->save($data);
        }

        public function updateProduct($id, array $data): void {
                $product = \BeetrootProductData::getById($id);
                if (!$product) return;
                $product->save($data);
        }

        public function deleteProduct($id): void {
                $product = \BeetrootProductData::getById($id);
                if (!$product) return;
                $product->deactivate();
        }

        // ---------- Clientes ----------

        public function getAllClients(): array {
                return \BeetrootClientData::getAll();
        }

        public function getClientById($id) {
                return \BeetrootClientData::getById($id);
        }

        public function createClient(array $data): void {
                $client = new \BeetrootClientData();
                $client->save($data);
        }

        public function updateClient($id, array $data): void {
                $client = \BeetrootClientData::getById($id);
                if (!$client) return;
                $client->save($data);
        }

        public function deleteClient($id): void {
                $client = \BeetrootClientData::getById($id);
                if (!$client) return;
                $client->delete();
        }

        public function getClientDebts($clientId, bool $onlyPending = false): array {
                return \BeetrootClientData::getDebtsByClient($clientId, $onlyPending);
        }

        public function getClientTotalPending($clientId): float {
                return \BeetrootClientData::getTotalPendingByClient($clientId);
        }

        // ---------- Deudas / Pedidos ----------

        public function getAllDebts(): array {
                return \BeetrootDebtData::getAllWithDetails();
        }

        public function getDebtById($id) {
                return \BeetrootDebtData::getById($id);
        }

        /** Registra un pedido con deuda: resta stock si el producto existe. */
        public function createDebt(array $data): void {
                $debt = new \BeetrootDebtData();
                $debt->create($data);

                if (!empty($data['product_id'])) {
                        $product = \BeetrootProductData::getById((int)$data['product_id']);
                        if ($product) {
                                $product->addStock(-max(1, (int)($data['quantity'] ?? 1)));
                        }
                }
        }

        public function updateDebt($id, array $data): void {
                $debt = \BeetrootDebtData::getById($id);
                if (!$debt) return;
                $debt->update($data);
        }

        public function deleteDebt($id): void {
                $debt = \BeetrootDebtData::getById($id);
                if (!$debt) return;

                // Devuelve las unidades al inventario del modulo
                if ($debt->product_id) {
                        $product = \BeetrootProductData::getById($debt->product_id);
                        if ($product) {
                                $product->addStock((int)$debt->quantity);
                        }
                }
                $debt->delete();
        }

        // ---------- Abonos ----------

        public function addPayment(int $debtId, array $data): void {
                \BeetrootDebtData::addPayment($debtId, (float)($data['amount'] ?? 0), $data['note'] ?? '');
        }

        public function getPaymentsByDebt($debtId): array {
                return \BeetrootDebtData::getPaymentsByDebt($debtId);
        }

        public function deletePayment(int $paymentId): void {
                \BeetrootDebtData::deletePayment($paymentId);
        }
}
