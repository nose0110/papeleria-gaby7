<?php
/**
 * VITRINA PUBLICA BETERRWARE — /beterrware.php
 *
 * Pagina de solo-lectura para compartir (WhatsApp, redes, etc.):
 * muestra unicamente los productos Beterrware ACTIVOS y CON STOCK,
 * con imagen, descripcion y precio.
 *
 * - Fuera del ecosistema Inventio Lite: no usa login, ni sesion,
 *   ni el layout principal, ni toca ninguna ruta/controlador existente.
 * - Solo lectura: no hay formularios POST, no modifica nada en la BD.
 * - Reutiliza las credenciales via .env / variables de entorno (mismo
 *   patron que core/controller/Database.php) leyendo con PDO directo.
 */

define('BW_ROOT', dirname(__FILE__));

// --- Configuracion minima de errores (oculta detalles al publico) ---
error_reporting(E_ALL);
ini_set('display_errors', 0); // nunca mostrar errores en una pagina publica

// --- Carga .env (si existe) sin depender del core ---
if (is_file(BW_ROOT . '/core/controller/Env.php')) {
    require_once BW_ROOT . '/core/controller/Env.php';
    Env::load();
}

$bwEnv = function ($key, $default) {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
};

$dbUser = $bwEnv('DB_USER', 'root');
$dbPass = $bwEnv('DB_PASS', '');
$dbHost = $bwEnv('DB_HOST', 'localhost');
$dbName = $bwEnv('DB_NAME', 'inventiolite');

// --- Obtener SOLO productos activos con stock > 0 (solo lectura) ---
$products = [];
$dbError = false;
try {
    if (defined('BW_TEST_PDO')) {
        // Solo para pruebas locales: inyeccion de PDO (no usa credenciales reales)
        $pdo = BW_TEST_PDO;
    } else {
        $pdo = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
    // SELECT puro: esta pagina no puede escribir nada.
    $stmt = $pdo->query(
        "select name, description, price, code, image
         from bw_product
         where is_active = 1 and stock > 0
         order by name asc"
    );
    $products = $stmt->fetchAll();
} catch (Throwable $e) {
    $dbError = true;
}

// base_url para las imagenes (funciona en raiz o en subcarpeta tipo /inventio-lite)
$bwBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');

// Moneda: igual que el resto del modulo Beterrware (usa "$")
function bwMoney($v): string {
    return '$' . number_format((float)$v, 2);
}

// Minusculas sin depender de mbstring (por si el hosting no lo trae habilitado)
function bwLower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, follow">
<title>Vitrina Beterrware — Productos disponibles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
    :root { --bw-primary: #b91c4c; --bw-dark: #6d1030; --bw-light: #fdf2f6; }
    body { background: #faf6f8; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    .bw-hero {
        background: linear-gradient(135deg, var(--bw-primary), var(--bw-dark));
        color: #fff; padding: 2.2rem 1rem 2.6rem; text-align: center;
        border-bottom-left-radius: 1.5rem; border-bottom-right-radius: 1.5rem;
    }
    .bw-hero h1 { font-weight: 800; letter-spacing: .5px; }
    .bw-hero .bi-carrot-fill { font-size: 2.4rem; }
    .bw-card {
        border: 0; border-radius: 1rem; overflow: hidden; height: 100%;
        box-shadow: 0 2px 10px rgba(109, 16, 48, .08);
        transition: transform .15s ease, box-shadow .15s ease;
        background: #fff;
    }
    .bw-card:hover { transform: translateY(-4px); box-shadow: 0 8px 22px rgba(109, 16, 48, .16); }
    .bw-img-wrap { background: var(--bw-light); display: flex; align-items: center; justify-content: center; height: 190px; }
    .bw-img-wrap img { width: 100%; height: 100%; object-fit: cover; }
    .bw-img-wrap .bi { font-size: 3rem; color: #d9a4b7; }
    .bw-price { color: var(--bw-primary); font-weight: 800; font-size: 1.25rem; }
    .bw-name { font-weight: 700; color: #2d2d2d; }
    .bw-desc { color: #6b6b6b; font-size: .9rem; min-height: 2.6em; }
    .bw-code { font-size: .75rem; color: #9aa0a6; }
    .bw-search { max-width: 480px; }
    .bw-empty { color: #8a8a8a; }
    footer { color: #9aa0a6; font-size: .8rem; }
</style>
</head>
<body>

<header class="bw-hero">
    <div><i class="bi bi-carrot-fill"></i></div>
    <h1>Beterrware</h1>
    <p class="mb-0 opacity-75">Productos frescos disponibles ahora mismo</p>
</header>

<main class="container py-4">

    <?php if ($dbError): ?>
        <div class="alert alert-warning text-center my-4">
            <i class="bi bi-exclamation-triangle"></i>
            La vitrina no está disponible en este momento. Vuelve a intentar más tarde.
        </div>
    <?php else: ?>

        <?php if (count($products) > 4): ?>
        <div class="text-center mb-4">
            <div class="input-group bw-search mx-auto">
                <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                <input type="search" id="bwSearch" class="form-control" placeholder="Buscar producto..." autocomplete="off">
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($products)): ?>
            <div class="bw-empty text-center py-5">
                <i class="bi bi-basket" style="font-size:2.5rem;"></i>
                <p class="mt-2 mb-0">Por ahora no hay productos disponibles. ¡Vuelve pronto!</p>
            </div>
        <?php else: ?>
            <div class="row g-3" id="bwGrid">
                <?php foreach ($products as $p): ?>
                <div class="col-6 col-md-4 col-lg-3 bw-item" data-search="<?= htmlspecialchars(bwLower($p['name'] . ' ' . $p['description'] . ' ' . $p['code']), ENT_QUOTES) ?>">
                    <div class="card bw-card">
                        <div class="bw-img-wrap">
                            <?php if ($p['image'] && is_file(BW_ROOT . '/storage/beetroot/' . $p['image'])): ?>
                                <img src="<?= htmlspecialchars($bwBase . '/storage/beetroot/' . $p['image']) ?>"
                                     alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <i class="bi bi-image"></i>
                            <?php endif; ?>
                        </div>
                        <div class="card-body d-flex flex-column">
                            <h2 class="bw-name h6 mb-1"><?= htmlspecialchars($p['name']) ?></h2>
                            <p class="bw-desc mb-2"><?= htmlspecialchars($p['description'] ?: '') ?></p>
                            <div class="mt-auto d-flex justify-content-between align-items-end">
                                <span class="bw-price"><?= bwMoney($p['price']) ?></span>
                                <?php if ($p['code']): ?>
                                    <span class="bw-code"><i class="bi bi-upc-scan"></i> <?= htmlspecialchars($p['code']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="text-center bw-empty mt-4"><i class="bi bi-info-circle"></i> Precios de lista. Consulta disponibilidad y pedidos directamente con nosotros.</p>
        <?php endif; ?>
    <?php endif; ?>

</main>

<footer class="text-center pb-4">
    Vitrina Beterrware · <?= date('Y') ?>
</footer>

<script>
// Buscador local (solo filtra lo ya mostrado; no consulta nada al servidor)
(function () {
    const input = document.getElementById('bwSearch');
    if (!input) return;
    const items = document.querySelectorAll('.bw-item');
    input.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        items.forEach(function (el) {
            el.style.display = (!q || el.dataset.search.indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
</script>
</body>
</html>
