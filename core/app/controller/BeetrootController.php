<?php
namespace App\Controller;

use App\Service\BeetrootInstaller;
use App\Service\BeetrootService;
use ViewEngine;
use Req;
use Upload;

/**
 * Modulo "Beterrware": seccion completamente aparte para que la mama registre
 * sus productos (nombre, descripcion, stock, precio, codigo), sus clientes,
 * los pedidos/deudas de cada cliente y sus abonos.
 *
 * No comparte datos ni logica con los modulos del nucleo: usa sus propias
 * tablas (bw_*) y sus propias vistas (public/beetroot/*).
 */
class BeetrootController {
        private $service;
        private $baseFolder;

        public function __construct() {
                BeetrootInstaller::ensure();
                $this->service = new BeetrootService();
                $this->baseFolder = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        }

        private function redirect(string $path): void {
                header('Location: ' . $this->baseFolder . $path);
        }

        // ---------- Panel ----------

        public function index() {
                ViewEngine::render('beetroot/dashboard.html.twig', [
                        'summary' => $this->service->getSummary(),
                        'debts' => $this->service->attachDetails($this->service->getAllDebts()),
                ]);
        }

        // ---------- Productos ----------

        public function products() {
                ViewEngine::render('beetroot/products.html.twig', [
                        'products' => $this->service->searchProducts((string)Req::get('q', '')),
                        'q' => (string)Req::get('q', ''),
                ]);
        }

        public function newProduct() {
                ViewEngine::render('beetroot/product-form.html.twig', ['mode' => 'new']);
        }

        public function createProduct() {
                $errors = Req::validate(['name' => 'required', 'price' => 'required']);
                if (!empty($errors)) {
                        ViewEngine::render('beetroot/product-form.html.twig', ['mode' => 'new', 'errors' => $errors, 'old' => Req::post()]);
                        return;
                }

                $data = Req::post();
                $data['image'] = $this->handleImageUpload();
                $this->service->createProduct($data);
                $_SESSION['success'] = 'Producto agregado correctamente';
                $this->redirect('/beterrware/products');
        }

        public function editProduct($vars) {
                $product = $this->service->getProductById($vars['id']);
                if (!$product) {
                        $this->redirect('/beterrware/products');
                        return;
                }
                ViewEngine::render('beetroot/product-form.html.twig', ['mode' => 'edit', 'product' => $product]);
        }

        public function updateProduct($vars) {
                $errors = Req::validate(['name' => 'required', 'price' => 'required']);
                if (!empty($errors)) {
                        ViewEngine::render('beetroot/product-form.html.twig', [
                                'mode' => 'edit',
                                'product' => $this->service->getProductById($vars['id']),
                                'errors' => $errors,
                        ]);
                        return;
                }

                $data = Req::post();
                $data['image'] = $this->handleImageUpload();
                $this->service->updateProduct($vars['id'], $data);
                $_SESSION['updated'] = 'Producto actualizado correctamente';
                $this->redirect('/beterrware/products');
        }

        public function deleteProduct($vars) {
                $this->service->deleteProduct($vars['id']);
                $_SESSION['deleted'] = 'Producto eliminado correctamente';
                $this->redirect('/beterrware/products');
        }

        // ---------- Clientes ----------

        public function clients() {
                ViewEngine::render('beetroot/clients.html.twig', [
                        'clients' => $this->service->getAllClients(),
                ]);
        }

        public function newClient() {
                ViewEngine::render('beetroot/client-form.html.twig', ['mode' => 'new']);
        }

        public function createClient() {
                $errors = Req::validate(['name' => 'required']);
                if (!empty($errors)) {
                        ViewEngine::render('beetroot/client-form.html.twig', ['mode' => 'new', 'errors' => $errors, 'old' => Req::post()]);
                        return;
                }

                $this->service->createClient(Req::post());
                $_SESSION['success'] = 'Cliente agregado correctamente';
                $this->redirect('/beterrware/clients');
        }

        public function editClient($vars) {
                $client = $this->service->getClientById($vars['id']);
                if (!$client) {
                        $this->redirect('/beterrware/clients');
                        return;
                }
                ViewEngine::render('beetroot/client-form.html.twig', ['mode' => 'edit', 'client' => $client]);
        }

        public function updateClient($vars) {
                $errors = Req::validate(['name' => 'required']);
                if (!empty($errors)) {
                        ViewEngine::render('beetroot/client-form.html.twig', [
                                'mode' => 'edit',
                                'client' => $this->service->getClientById($vars['id']),
                                'errors' => $errors,
                        ]);
                        return;
                }

                $this->service->updateClient($vars['id'], Req::post());
                $_SESSION['updated'] = 'Cliente actualizado correctamente';
                $this->redirect('/beterrware/clients');
        }

        public function deleteClient($vars) {
                $this->service->deleteClient($vars['id']);
                $_SESSION['deleted'] = 'Cliente eliminado correctamente';
                $this->redirect('/beterrware/clients');
        }

        /** Ficha del cliente: productos pedidos, cantidades y dinero pendiente + abonos. */
        public function showClient($vars) {
                $client = $this->service->getClientById($vars['id']);
                if (!$client) {
                        $this->redirect('/beterrware/clients');
                        return;
                }

                $debts = $this->service->getClientDebts($client->id);
                $debtsWithPayments = $this->service->attachDetails($debts);

                ViewEngine::render('beetroot/client-show.html.twig', [
                        'client' => $client,
                        'debts' => $debtsWithPayments,
                        'total_pending' => $this->service->getClientTotalPending($client->id),
                        'products' => $this->service->getActiveProducts(),
                ]);
        }

        // ---------- Deudas / Pedidos ----------

        public function debts() {
                $debts = $this->service->getAllDebts();

                ViewEngine::render('beetroot/debts.html.twig', [
                        'debts' => $this->service->attachDetails($debts),
                        'clients' => $this->service->getAllClients(),
                        'products' => $this->service->getActiveProducts(),
                ]);
        }

        public function newDebt() {
                ViewEngine::render('beetroot/debt-form.html.twig', [
                    'mode' => 'new',
                    'clients' => $this->service->getAllClients(),
                    'products' => $this->service->getActiveProducts(),
                ]);
        }

        public function createDebt() {
                $errors = Req::validate(['client_id' => 'required']);
                $items = $this->parseItems(Req::post());

                // El total de la deuda se calcula a partir de la lista de productos.
                $calculated = 0.0;
                foreach ($items as $it) {
                        $calculated += $it['price'] * $it['quantity'];
                }
                if ($calculated > 0) {
                        $_POST['amount'] = round($calculated, 2);
                } elseif (empty($_POST['amount'])) {
                        $_POST['amount'] = 0;
                }

                if (empty($errors) && empty($items)) {
                        $errors['items'] = 'Agrega al menos un producto a la lista del pedido.';
                }
                if (!empty($errors)) {
                        ViewEngine::render('beetroot/debt-form.html.twig', [
                                'mode' => 'new',
                                'clients' => $this->service->getAllClients(),
                                'products' => $this->service->getActiveProducts(),
                                'errors' => $errors,
                                'old' => Req::post(),
                                'old_items' => $items,
                        ]);
                        return;
                }

                $data = Req::post();
                $data['items'] = $items;
                $this->service->createDebt($data);
                $_SESSION['success'] = 'Pedido registrado correctamente';
                $this->redirect('/beterrware/debts');
        }

        /** Convierte los arrays items[product_id][] / items[quantity][] en lineas limpias. */
        private function parseItems(?array $post): array {
                $raw = $post['items']['product_id'] ?? [];
                $qtys = $post['items']['quantity'] ?? [];
                $prices = $post['items']['price'] ?? [];
                if (!is_array($raw)) $raw = [];

                $items = [];
                foreach ($raw as $i => $pid) {
                        $pid = (int)$pid;
                        $qty = max(1, (int)($qtys[$i] ?? 1));
                        if ($pid <= 0) continue;
                        $items[] = [
                                'product_id' => $pid,
                                'quantity' => $qty,
                                'price' => (float)($prices[$i] ?? 0),
                        ];
                }
                return $items;
        }

        public function editDebt($vars) {
                $debt = $this->service->getDebtById($vars['id']);
                if (!$debt) {
                        $this->redirect('/beterrware/debts');
                        return;
                }
                $debt->items = \BeetrootDebtData::getItemsByDebt($debt->id);
                ViewEngine::render('beetroot/debt-edit.html.twig', [
                        'debt' => $debt,
                        'products' => $this->service->getActiveProducts(),
                ]);
        }

        public function updateDebt($vars) {
                $errors = Req::validate(['amount' => 'required']);
                $items = $this->parseItems(Req::post());
                $debt = $this->service->getDebtById($vars['id']);
                if (!$debt) {
                        $this->redirect('/beterrware/debts');
                        return;
                }
                if (!empty($errors)) {
                        $debt->items = \BeetrootDebtData::getItemsByDebt($debt->id);
                        ViewEngine::render('beetroot/debt-edit.html.twig', [
                                'debt' => $debt,
                                'products' => $this->service->getActiveProducts(),
                                'errors' => $errors,
                        ]);
                        return;
                }

                $data = Req::post();
                $data['items'] = $items;
                $this->service->updateDebt($vars['id'], $data);
                $_SESSION['updated'] = 'Deuda actualizada correctamente';
                $this->redirect('/beterrware/debts');
        }

        public function deleteDebt($vars) {
                $this->service->deleteDebt($vars['id']);
                $_SESSION['deleted'] = 'Deuda eliminada correctamente';
                $this->redirect('/beterrware/debts');
        }

        // ---------- Abonos ----------

        public function addPayment($vars) {
                $errors = Req::validate(['amount' => 'required']);
                if (!empty($errors)) {
                        $_SESSION['error'] = 'El monto del abono es obligatorio.';
                        $this->redirect('/beterrware/debts');
                        return;
                }

                $amount = (float)Req::post('amount');
                if ($amount <= 0) {
                        $_SESSION['error'] = 'El monto del abono debe ser mayor a 0.';
                        $this->redirect('/beterrware/debts');
                        return;
                }

                $debt = $this->service->getDebtById($vars['id']);
                if (!$debt) {
                        $_SESSION['error'] = 'La deuda no existe.';
                        $this->redirect('/beterrware/debts');
                        return;
                }

                $this->service->addPayment((int)$vars['id'], Req::post());
                $_SESSION['success'] = 'Abono registrado correctamente';
                $this->redirect('/beterrware/debts');
        }

        public function deletePayment($vars) {
                $this->service->deletePayment((int)$vars['id']);
                $_SESSION['deleted'] = 'Abono eliminado correctamente';
                $this->redirect('/beterrware/debts');
        }

        // ---------- Utilidades ----------

        /** Endpoint JSON del buscador de productos (usado por el carrito del pedido). */
        public function productSearch() {
                if (!isset($_SESSION['user_id'])) {
                        http_response_code(401);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([]);
                        return;
                }
                $this->service->productSearch();
        }

        /** Sube la imagen del producto a storage/beetroot/ (carpetas propias del modulo). */
        private function handleImageUpload(): ?string {
                if (empty($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
                        return null;
                }

                \BeetrootProductData::ensureStorageDir();

                $image = new Upload($_FILES['image']);
                if (!$image->uploaded) {
                        return null;
                }

                $image->allowed = ['image/*'];
                $image->max_filesize = 5 * 1024 * 1024; // 5 MB
                $image->image_resize = true;
                $image->image_x = 600;
                $image->image_ratio_y = true;
                $image->image_convert = 'jpg';

                $image->Process('storage/beetroot/');
                return $image->processed ? $image->file_dst_name : null;
        }
}
