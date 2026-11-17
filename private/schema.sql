-- Stage 1: accounts. Applied automatically by bootstrap.php when the tables are missing.
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    role VARCHAR(16) NOT NULL,              -- tech | provider | admin
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    company VARCHAR(160) NULL,              -- providers
    status VARCHAR(16) NOT NULL DEFAULT 'active',   -- active | suspended
    terms_accepted_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    ip VARCHAR(64) NOT NULL,
    success TINYINT NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Everything users do, for the admin dashboard. Never shown to users.
CREATE TABLE IF NOT EXISTS activity_log (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NULL,
    action VARCHAR(64) NOT NULL,
    detail VARCHAR(500) NULL,
    ip VARCHAR(64) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
