<?php
/**
 * Modelo de datos del catálogo de artículos y productos del almacén.
 */
class ProductData {
	public static $tablename = "product";
	public $id;
	public $image;
	public $barcode;
	public $name;
	public $description;
	public $inventary_min;
	public $price_in;
	public $price_out;
	public $unit;
	public $presentation;
	public $user_id;
	public $category_id;
	public $created_at;
	public $is_active;


	public function __construct(){
		$this->name = "";
		$this->price_in = "";
		$this->price_out = "";
		$this->unit = "";
		$this->user_id = "";
		$this->presentation = "0";
		$this->created_at = "NOW()";
	}

	private static function db(): \PDO {
		return Database::getPdo();
	}

	public function getCategory(){ return CategoryData::getById($this->category_id);}

	public function add(){
		$stmt = self::db()->prepare(
			"insert into ".self::$tablename." (barcode,name,description,price_in,price_out,user_id,presentation,unit,category_id,inventary_min,created_at) ".
			"values (:barcode,:name,:description,:price_in,:price_out,:user_id,:presentation,:unit,:category_id,:inventary_min,NOW())"
		);
		$stmt->execute($this->bindableFields());
		$this->id = self::db()->lastInsertId();
		return $this->id;
	}

	public function add_with_image(){
		$stmt = self::db()->prepare(
			"insert into ".self::$tablename." (barcode,image,name,description,price_in,price_out,user_id,presentation,unit,category_id,inventary_min,created_at) ".
			"values (:barcode,:image,:name,:description,:price_in,:price_out,:user_id,:presentation,:unit,:category_id,:inventary_min,NOW())"
		);
		$stmt->execute($this->bindableFields() + ['image' => $this->image]);
		$this->id = self::db()->lastInsertId();
		return $this->id;
	}

	private function bindableFields(): array {
		return [
			'barcode' => $this->barcode,
			'name' => $this->name,
			'description' => $this->description,
			'price_in' => $this->price_in,
			'price_out' => $this->price_out,
			'user_id' => $this->user_id,
			'presentation' => $this->presentation,
			'unit' => $this->unit,
			'category_id' => ($this->category_id === '' || $this->category_id === 'NULL') ? null : $this->category_id,
			'inventary_min' => ($this->inventary_min === '' || $this->inventary_min === '""') ? null : $this->inventary_min,
		];
	}

	public static function delById($id){
		$stmt = self::db()->prepare("delete from ".self::$tablename." where id = :id");
		$stmt->execute(['id' => $id]);
	}
	public function del(){
		self::delById($this->id);
	}

	// partiendo de que ya tenemos creado un objecto ProductData previamente utilizamos el contexto
	public function update(){
		// UPDATE dinámico: construye el SET solo con los campos que se pasan,
		// evitando HY093 (numero de parametros != numero de tokens) cuando este
		// archivo queda desincronizado con ProductService.php en el servidor.
		return $this->updateFields([
			'barcode'       => $this->barcode,
			'name'          => $this->name,
			'price_in'      => $this->price_in,
			'price_out'     => $this->price_out,
			'unit'          => $this->unit,
			'presentation'  => $this->presentation,
			'category_id'   => ($this->category_id === '' || $this->category_id === 'NULL') ? null : $this->category_id,
			'inventary_min' => ($this->inventary_min === '' || $this->inventary_min === '""') ? null : $this->inventary_min,
			'description'   => $this->description,
			'is_active'     => $this->is_active,
		]);
	}

	public function updateFields(array $fields){
		if (($this->id ?? null) === null || $this->id === '') return false;

		// Lista blanca de columnas actualizables (nunca se inyectan nombres arbitrarios).
		$allowed = ['barcode','image','name','description','price_in','price_out','unit',
				'presentation','category_id','inventary_min','is_active'];
		$set = [];
		$params = [];
		foreach ($fields as $col => $val) {
			if (!in_array($col, $allowed, true)) continue;
			$set[] = "$col = :$col";
			$params[":$col"] = $val;
		}
		if (!$set) return false;

		$params[':id'] = $this->id;
		$stmt = self::db()->prepare("update ".self::$tablename." set ".implode(', ', $set)." where id = :id");
		return $stmt->execute($params);
	}

	public function del_category(){
		$stmt = self::db()->prepare("update ".self::$tablename." set category_id=NULL where id = :id");
		$stmt->execute(['id' => $this->id]);
	}

	public function update_image(){
		$stmt = self::db()->prepare("update ".self::$tablename." set image=:image where id = :id");
		$stmt->execute(['image' => $this->image, 'id' => $this->id]);
	}

	public static function getById($id){
		$stmt = self::db()->prepare("select * from ".self::$tablename." where id = :id");
		$stmt->execute(['id' => $id]);
		$stmt->setFetchMode(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
		return $stmt->fetch() ?: null;
	}

	public static function getAll(){
		$stmt = self::db()->query("select * from ".self::$tablename);
		return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
	}

	public static function getAllByPage($start_from,$limit){
		$stmt = self::db()->prepare("select * from ".self::$tablename." where id >= :start_from limit ".((int)$limit));
		$stmt->execute(['start_from' => (int)$start_from]);
		return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
	}

	public static function getLike($p){
		$stmt = self::db()->prepare("select * from ".self::$tablename." where barcode like :p or name like :p or id like :p");
		$stmt->execute(['p' => '%'.$p.'%']);
		return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
	}

	public static function getAllByUserId($user_id){
		$stmt = self::db()->prepare("select * from ".self::$tablename." where user_id = :user_id order by created_at desc");
		$stmt->execute(['user_id' => $user_id]);
		return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
	}

	public static function getAllByCategoryId($category_id){
		$stmt = self::db()->prepare("select * from ".self::$tablename." where category_id = :category_id order by created_at desc");
		$stmt->execute(['category_id' => $category_id]);
		return $stmt->fetchAll(\PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, self::class);
	}

}

?>
