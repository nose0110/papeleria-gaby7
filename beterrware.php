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
    $cols = 'id, name, description, price, code, image';
    try {
        // created_at existe en el esquema completo; si faltara, se degrada a id
        $stmt = $pdo->query(
            "select {$cols}, created_at
             from bw_product
             where is_active = 1 and stock > 0
             order by name asc"
        );
    } catch (Throwable $e) {
        $stmt = $pdo->query(
            "select {$cols}
             from bw_product
             where is_active = 1 and stock > 0
             order by name asc"
        );
    }
    $products = $stmt->fetchAll();

    // Ultimos productos agregados (los mas recientes registrados)
    $recent = [];
    if (!empty($products)) {
        $hasDates = false;
        foreach ($products as $p) {
            if (!empty($p['created_at'])) { $hasDates = true; break; }
        }
        if ($hasDates) {
            usort($products, function ($a, $b) {
                return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
            });
        } else {
            usort($products, function ($a, $b) {
                return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
            });
        }
        $recent = array_slice($products, 0, min(8, count($products)));
        usort($products, function ($a, $b) {
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }
} catch (Throwable $e) {
    $dbError = true;
}

// base_url para las imagenes (funciona en raiz o en subcarpeta tipo /inventio-lite).
// Se calcula SIEMPRE a partir de la ubicacion REAL del archivo (no de _SERVER["SCRIPT_NAME"]).
// Motivo: al compartir el mismo prefijo de ruta (/beterrware...) con el modulo interno,
// una peticion reescrita por mod_rewrite hacia index.php puede terminar ejecutando esta
// vitrina; ahi SCRIPT_NAME es "/index.php", dirname() queda vacio y las rutas de imagen
// se arman sin prefijo. En una instalacion dentro de una subcarpeta eso apunta a una URL
// inexistente y la vitrina deja de mostrar las imagenes (mientras que la pagina de
// edicion, renderizada por Twig con su propio base_url, si las muestra).
$bwSelfDir = str_replace('\\', '/', dirname(__FILE__));
$bwDocRoot = isset($_SERVER['DOCUMENT_ROOT'])
    ? rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/')
    : '';
if ($bwDocRoot !== '' && stripos($bwSelfDir, $bwDocRoot) === 0) {
    // El proyecto esta dentro del docroot: prefijo = subcarpeta de instalacion
    $bwBase = rtrim(substr($bwSelfDir, strlen($bwDocRoot)), '/');
} else {
    // Docroot fuera/oculto (algunos hostings): se deduce del REQUEST_URI recortando
    // desde el nombre del script; si no coincide, se asume instalacion en la raiz.
    $bwBase = '';
    $reqUri = rawurldecode((string)($_SERVER['REQUEST_URI'] ?? ''));
    if ($reqUri !== '' && ($pos = stripos($reqUri, '/beterrware.php')) !== false) {
        $bwBase = rtrim(substr($reqUri, 0, $pos), '/');
    }
}

// Moneda: igual que el resto del modulo Beterrware (usa "$")
function bwMoney($v): string {
    return '$' . number_format((float)$v, 2);
}

// Minusculas sin depender de mbstring (por si el hosting no lo trae habilitado)
function bwLower(string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/**
 * URL de la imagen del producto para la vitrina.
 * Devuelve null solo si el producto nunca tuvo imagen. Si tiene valor guardado
 * se devuelve siempre la URL (normalizada), incluso cuando el archivo no este
 * presente: asi un borrado temporal, un cambio de permisos/ruta o un valor
 * guardado con prefijo ("./storage/...", "storage/beetroot/...") no hace que
 * la vitrina "esconda" imagenes que la pagina de edicion si muestra.
 */
function bwImageSrc($image, string $bwBase): ?string {
    $image = trim((string)$image);
    if ($image === '') return null;
    // Normaliza el valor guardado en BD
    $file = str_replace('\\', '/', $image);
    $file = preg_replace('#^\./#', '', $file);
    $file = ltrim($file, '/');
    $file = preg_replace('#^storage/beetroot/#', '', $file);
    if ($file === '') return null;
    return $bwBase . '/storage/beetroot/' . implode('/', array_map('rawurlencode', explode('/', $file)));
}

// Tarjeta compacta reutilizable (usada en "Ultimos productos" y en la grilla)
function bwCard(array $p, string $bwBase, bool $compact = false, bool $isNew = false): string {
    $imgSrc = bwImageSrc($p['image'] ?? null, $bwBase);
    ob_start(); ?>
    <div class="card bw-card h-100<?= $compact ? ' bw-card-sm' : '' ?>">
        <div class="bw-img-wrap<?= $compact ? ' py-3' : '' ?>">
            <?php if ($isNew): ?><span class="badge rounded-pill bw-badge-new"><i class="bi bi-stars"></i> Nuevo</span><?php endif; ?>
            <?php if ($imgSrc !== null): ?>
                <img src="<?= htmlspecialchars($imgSrc) ?>"
                     alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy"
                     onerror="this.replaceWith(Object.assign(document.createElement('i'), {className:'bi bi-image'}))">
            <?php else: ?>
                <i class="bi bi-image"></i>
            <?php endif; ?>
        </div>
        <div class="card-body d-flex flex-column">
            <h2 class="bw-name h6 mb-1"><?= htmlspecialchars($p['name']) ?></h2>
            <?php if (!$compact): ?>
                <p class="bw-desc mb-2"><?= htmlspecialchars($p['description'] ?: '') ?></p>
            <?php endif; ?>
            <div class="mt-auto d-flex justify-content-between align-items-end">
                <span class="bw-price<?= $compact ? ' small' : '' ?>"><?= bwMoney($p['price']) ?></span>
                <?php if (!empty($p['code'])): ?>
                    <span class="bw-code"><i class="bi bi-upc-scan"></i> <?= htmlspecialchars($p['code']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
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
    .bw-img-wrap { background: var(--bw-light); display: flex; align-items: center; justify-content: center; height: 190px; position: relative; }
    .bw-img-wrap img { width: 100%; height: 100%; object-fit: cover; }
    .bw-img-wrap .bi { font-size: 3rem; color: #d9a4b7; }
    .bw-badge-new {
        position: absolute; top: .5rem; left: .5rem; z-index: 2;
        background: var(--bw-primary); color: #fff; font-size: .68rem;
        padding: .3rem .6rem; box-shadow: 0 2px 6px rgba(109,16,48,.3);
    }
    .bw-recent-box {
        background: linear-gradient(135deg, #fdf2f6, #fbe4ec);
        border: 1px solid #f3c6d6; border-radius: 1.25rem; padding: 1.1rem 1.1rem .4rem;
    }
    .bw-recent-title { color: var(--bw-dark); font-weight: 800; }
    .bw-card-sm .bw-img-wrap { height: 110px; }
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

        <?php if (!empty($recent)): ?>
        <section class="bw-recent-box mb-4">
            <h2 class="bw-recent-title h5 mb-3">
                <i class="bi bi-stars"></i> Últimos productos agregados
            </h2>
            <div class="row g-2 row-cols-2 row-cols-sm-3 row-cols-lg-4 mb-2">
                <?php foreach ($recent as $r): ?>
                    <div class="col"><?= bwCard($r, $bwBase, compact: true, isNew: true) ?></div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

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
                    <?= bwCard($p, $bwBase) ?>
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
