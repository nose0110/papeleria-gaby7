<?php
/**
 * Cargador minimo de variables de entorno (.env)
 * No depende de vlucas/phpdotenv: lee KEY=VALUE del archivo .env y los
 * expone con putenv() para que getenv() (y Database.php) los encuentre.
 * Prioridad: variables reales del servidor (Hostinger PHP env vars) > .env > defaults.
 */
class Env {

	private static $loaded = false;

	public static function load($path = null){
		if(self::$loaded){
			return;
		}
		self::$loaded = true;

		if($path === null){
			$path = (defined('ROOT') ? ROOT : dirname(__DIR__, 2)) . '/.env';
		}

		if(!is_file($path)){
			return;
		}

		$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		foreach($lines as $line){
			$line = trim($line);
			// saltar comentarios y lineas sin '='
			if($line === '' || $line[0] === '#' || strpos($line, '=') === false){
				continue;
			}
			list($key, $value) = explode('=', $line, 2);
			$key   = trim($key);
			$value = trim($value);

			// quitar comillas simples o dobles alrededor del valor
			if(strlen($value) >= 2){
				if(($value[0] === '"' && substr($value, -1) === '"') ||
				   ($value[0] === "'" && substr($value, -1) === "'")){
					$value = substr($value, 1, -1);
				}
			}

			if($key === ''){
				continue;
			}
			// no pisar variables ya definidas a nivel de servidor
			if(getenv($key) === false){
				putenv("$key=$value");
				$_ENV[$key] = $value;
			}
		}
	}
}
