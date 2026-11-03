-- =====================================================================
-- Modulo "Beterrware" (seccion independiente para la gestion de la mama)
-- Tablas propias, sin FK hacia las tablas del nucleo de Inventio Lite.
-- Importar UNA SOLA VEZ sobre la base de datos ya existente:
--   mysql -u root inventiolite < schema_beetroot.sql
-- El modulo tambien crea las tablas automaticamente al abrirlo por primera vez.
-- =====================================================================

CREATE TABLE IF NOT EXISTS bw_product (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description TEXT,
  stock INT NOT NULL DEFAULT 0,
  price FLOAT NOT NULL DEFAULT 0,
  code VARCHAR(50),
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bw_client (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  phone VARCHAR(50),
  address VARCHAR(255),
  note TEXT,
  created_at DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bw_debt (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  client_id INT NOT NULL,
  product_id INT,
  amount FLOAT NOT NULL DEFAULT 0,          -- deuda original en dinero
  quantity INT NOT NULL DEFAULT 1,          -- numero de unidades pedidas
  paid FLOAT NOT NULL DEFAULT 0,            -- total abonado (suma de abonos)
  note VARCHAR(255),
  status ENUM('pendiente','pagada') NOT NULL DEFAULT 'pendiente',
  created_at DATETIME,
  INDEX idx_client (client_id),
  INDEX idx_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bw_payment (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  debt_id INT NOT NULL,
  amount FLOAT NOT NULL,                    -- monto del abono
  note VARCHAR(255),
  created_at DATETIME,
  INDEX idx_debt (debt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
