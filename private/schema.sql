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

-- Stage 2: tech profiles and insurance.
CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(64) PRIMARY KEY,
    v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tech_profiles (
    user_id INTEGER PRIMARY KEY,
    headline VARCHAR(160) NULL,
    bio TEXT NULL,
    skills TEXT NULL,                  -- comma-separated skill keys
    certifications TEXT NULL,          -- one per line
    street VARCHAR(160) NULL,          -- private: used only for distance
    city VARCHAR(80) NULL,
    state VARCHAR(2) NULL,
    zip VARCHAR(10) NULL,
    lat DOUBLE NULL,
    lng DOUBLE NULL,
    radius_miles INTEGER NOT NULL DEFAULT 50,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS insurance_docs (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    carrier VARCHAR(120) NOT NULL,
    coverage_amount INTEGER NOT NULL,  -- general liability, whole dollars
    expires_on DATE NOT NULL,
    file_name VARCHAR(80) NOT NULL,    -- stored name in private/uploads
    original_name VARCHAR(190) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',   -- pending | approved | rejected
    admin_note VARCHAR(300) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
