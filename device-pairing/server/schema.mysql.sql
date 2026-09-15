-- StreamBox schema for MySQL / MariaDB.
-- Import once on your live host, then point DB_DSN in config.local.php at it.
-- SQLite needs none of this: config.php creates its own tables on first run.

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_admin      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at    INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS device_codes (
  device_code  VARCHAR(64)  NOT NULL PRIMARY KEY,
  user_code    VARCHAR(9)   NOT NULL UNIQUE,
  status       VARCHAR(16)  NOT NULL DEFAULT 'pending',
  device_name  VARCHAR(60)  NULL,
  user_id      INT UNSIGNED NULL,
  paired_at    INT UNSIGNED NULL,
  revoked_at   INT UNSIGNED NULL,
  last_seen    INT UNSIGNED NULL,
  expires_at   INT UNSIGNED NOT NULL,
  created_at   INT UNSIGNED NOT NULL,
  INDEX idx_devices_user (user_id),
  CONSTRAINT fk_device_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS broadcasts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type       VARCHAR(16)  NOT NULL,
  title      VARCHAR(120) NULL,
  body       VARCHAR(600) NULL,
  media_url  VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS verify_attempts (
  ip           VARCHAR(45)  NOT NULL,
  attempted_at INT UNSIGNED NOT NULL,
  INDEX idx_attempts (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
