<?php

/**
 * Clase ViewEngine
 *
 * Wrapper de Twig para renderizar las vistas de los modulos ya migrados.
 * Inyecta globals compartidos por el layout portado (public/layouts/main.html.twig):
 * base_url, curr_user (para el sidebar) y los mensajes flash de exito/error que
 * ya usa el layout legacy (Session/SweetAlert vía $_SESSION["success"|"updated"|"deleted"|"error"]).
 */
class ViewEngine {
	private static ?\Twig\Environment $twig = null;

	public static function init(): \Twig\Environment {
		if (self::$twig === null) {
			$loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../../public');
			self::$twig = new \Twig\Environment($loader, [
				'cache' => false,
				'debug' => true,
			]);

			// base_url: se calcula a partir de la ubicacion REAL del proyecto, no de
			// SCRIPT_NAME. Motivo: cuando la peticion llega por una ruta amigable
			// (/beterrware/products) y el controlador termina usando el layout portado
			// (public/layouts/main.html.twig) con <base href="{{ base_url }}/"> y assets
			// relativos ("assets/css/style.css"), un SCRIPT_NAME de "/index.php" deja el
			// prefijo vacio y el navegador resuelve todo contra la carpeta equivocada.
			// Al renombrar/mover el proyecto (inventario live -> Papeleria Gabi) eso hace
			// que unas navegaciones carguen los recursos y otras no (de ahi que en el
			// telefono no se vea lo mismo que en el PC). Ahora siempre da el prefijo real.
			$veSelfDir = str_replace('\\', '/', dirname(__DIR__, 2)); // raiz del proyecto
			$veDocRoot = isset($_SERVER['DOCUMENT_ROOT'])
					? rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/')
					: '';
			if ($veDocRoot !== '' && stripos($veSelfDir, $veDocRoot) === 0) {
					$baseUrl = rtrim(substr($veSelfDir, strlen($veDocRoot)), '/');
			} else {
					// Docroot fuera del proyecto (ciertos hostings): se deduce del REQUEST_URI
					// recortando desde la primera coincidencia con la raiz real.
					$baseUrl = rtrim(dirname(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
					$reqUri = rawurldecode((string)($_SERVER['REQUEST_URI'] ?? ''));
					if (($baseUrl === '' || $baseUrl === '.') && $reqUri !== '') {
						$pos = strpos($reqUri, '/index.php');
						if ($pos !== false) $baseUrl = rtrim(substr($reqUri, 0, $pos), '/');
					}
			}
			self::$twig->addGlobal('base_url', $baseUrl);

			self::$twig->addGlobal('csrf_token', Session::csrfToken());
			self::$twig->addFunction(new \Twig\TwigFunction('csrf_token', function() {
				return Session::csrfToken();
			}));
			self::$twig->addFunction(new \Twig\TwigFunction('csrf_field', function() {
				return '<input type="hidden" name="csrf_token" value="' . Session::csrfToken() . '">';
			}, ['is_safe' => ['html']]));
		}
		return self::$twig;
	}

	/**
	 * Modulos con CSS mobile exclusivo (carpeta de vistas => archivo CSS).
	 * Solo afecta a las vistas del modulo indicado; el resto del sistema
	 * conserva exactamente los mismos estilos de siempre.
	 */
	private const MOBILE_CSS = [
		'beetroot/' => 'assets/css/beetroot-mobile.css',
	];

	public static function render(string $template, array $data = []): void {
		$twig = self::init();

		// Inyecta el CSS mobile exclusivo segun la carpeta de la vista
		$mobileCss = null;
		foreach (self::MOBILE_CSS as $folder => $css) {
			if (strpos($template, $folder) === 0) { $mobileCss = $css; break; }
		}
		$twig->addGlobal('module_mobile_css', $mobileCss);
		$twig->addGlobal('curr_user', isset($_SESSION['user_id']) ? UserData::getById($_SESSION['user_id']) : null);

		foreach (['success', 'updated', 'deleted', 'error'] as $key) {
			$twig->addGlobal('flash_' . $key, $_SESSION[$key] ?? null);
			unset($_SESSION[$key]);
		}

		echo $twig->render($template, $data);
	}
}
