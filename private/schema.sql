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

-- Stage 3: work orders.
CREATE TABLE IF NOT EXISTS work_orders (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    provider_id INTEGER NOT NULL,
    tech_id INTEGER NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NOT NULL,
    skills TEXT NOT NULL,              -- comma-separated skill keys
    street VARCHAR(160) NOT NULL,      -- shown to the tech only once assigned
    city VARCHAR(80) NOT NULL,
    state VARCHAR(2) NOT NULL,
    zip VARCHAR(10) NOT NULL,
    lat DOUBLE NOT NULL,
    lng DOUBLE NOT NULL,
    start_at DATETIME NOT NULL,        -- local time at the job site
    est_hours DECIMAL(5,1) NULL,
    pay_cents INTEGER NOT NULL,        -- what the tech receives
    fee_cents INTEGER NOT NULL,        -- platform fee, paid by the provider on top
    min_coverage INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'open',   -- open | assigned | in_progress | submitted | approved | cancelled
    checked_in_at DATETIME NULL,
    submitted_at DATETIME NULL,
    completion_notes TEXT NULL,
    approved_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A tech asking for a job (kind=request) or a provider offering one (kind=offer).
CREATE TABLE IF NOT EXISTS wo_requests (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    work_order_id INTEGER NOT NULL,
    tech_id INTEGER NOT NULL,
    kind VARCHAR(8) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',   -- pending | accepted | declined | closed
    note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    decided_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stage 4: chat. One thread per work order and tech.
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    work_order_id INTEGER NOT NULL,
    tech_id INTEGER NOT NULL,          -- identifies the thread with the provider
    sender_id INTEGER NOT NULL,
    body TEXT NOT NULL,
    via VARCHAR(8) NOT NULL DEFAULT 'web',   -- web | sms
    flagged TINYINT NOT NULL DEFAULT 0,      -- contains a phone number or email address
    read_at DATETIME NULL,                   -- when the other participant first saw it
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stage 6: texting.
CREATE TABLE IF NOT EXISTS sms_prefs (
    user_id INTEGER PRIMARY KEY,
    phone VARCHAR(16) NOT NULL,          -- E.164, e.g. +18595551234. Never shown to other users.
    opted_in_at DATETIME NULL,
    opted_out_at DATETIME NULL,
    verified_at DATETIME NULL,
    verify_code VARCHAR(64) NULL,        -- hash of the 6-digit code
    verify_sent_at DATETIME NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every text in or out. Outbound rows also tell us which job thread a reply belongs to.
CREATE TABLE IF NOT EXISTS sms_log (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NULL,
    direction VARCHAR(4) NOT NULL,       -- out | in
    phone VARCHAR(16) NOT NULL,
    work_order_id INTEGER NULL,
    tech_id INTEGER NULL,
    body TEXT NOT NULL,
    twilio_sid VARCHAR(64) NULL,
    status VARCHAR(24) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Account basics: password reset, email verification, reminder emails.
CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    token_hash VARCHAR(64) NOT NULL,     -- sha256 of the token in the emailed link
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS email_verifications (
    user_id INTEGER PRIMARY KEY,
    token_hash VARCHAR(64) NULL,
    sent_at DATETIME NULL,
    verified_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reminders_sent (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    ref_id INTEGER NOT NULL,             -- e.g. the insurance document
    kind VARCHAR(32) NOT NULL,           -- e.g. ins_30, ins_7, ins_expired
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stage 5: Stripe payments. One row per Checkout attempt, and the latest non-expired row is the job payment.
CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    work_order_id INTEGER NOT NULL,
    provider_id INTEGER NOT NULL,
    tech_id INTEGER NULL,
    checkout_session_id VARCHAR(255) NOT NULL,
    payment_intent_id VARCHAR(255) NULL,
    charge_id VARCHAR(255) NULL,
    amount_cents INTEGER NOT NULL,        -- job price, goes to the tech
    fee_cents INTEGER NOT NULL,           -- platform fee
    tax_cents INTEGER NOT NULL DEFAULT 0, -- sales tax collected by Stripe Tax
    total_cents INTEGER NOT NULL,         -- what the provider was charged
    status VARCHAR(16) NOT NULL,          -- pending | paid | refunded | disputed | expired
    payout_status VARCHAR(16) NOT NULL DEFAULT 'none',  -- none | waiting | sent | failed
    transfer_id VARCHAR(255) NULL,
    paid_at DATETIME NULL,
    transferred_at DATETIME NULL,
    refunded_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stripe_accounts (
    user_id INTEGER PRIMARY KEY,
    account_id VARCHAR(255) NOT NULL,
    transfers_active TINYINT NOT NULL DEFAULT 0,
    payouts_enabled TINYINT NOT NULL DEFAULT 0,
    details_submitted TINYINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stripe_events (
    id VARCHAR(255) PRIMARY KEY,
    type VARCHAR(80) NOT NULL,
    received_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- Completion proof: photos of the finished work and the signed work order, uploaded by the tech.
CREATE TABLE IF NOT EXISTS job_files (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    work_order_id INTEGER NOT NULL,
    uploaded_by INTEGER NOT NULL,
    kind VARCHAR(16) NOT NULL,            -- photo | signoff
    request_id INTEGER NULL,              -- which requested photo this answers, if any
    file_name VARCHAR(80) NOT NULL,
    original_name VARCHAR(190) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    bytes INTEGER NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- Photos the provider asks for (one row per requested shot), e.g. "New access point mounted".
CREATE TABLE IF NOT EXISTS job_photo_requests (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    work_order_id INTEGER NOT NULL,
    label VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Each tech's photo of themselves. Required before they can take jobs.
CREATE TABLE IF NOT EXISTS tech_photos (
    user_id INTEGER PRIMARY KEY,
    file_name VARCHAR(80) NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- Driver's license identity check. The admin compares it with the tech's photo and name.
-- The license image is deleted when the check is approved, so only the result is kept.
CREATE TABLE IF NOT EXISTS id_checks (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    file_name VARCHAR(80) NULL,
    mime VARCHAR(40) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',   -- pending | approved | rejected
    note VARCHAR(500) NULL,
    reviewed_by INTEGER NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- Optional GPS location the tech shares at check-in and when submitting the work.
CREATE TABLE IF NOT EXISTS job_locations (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    work_order_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    event VARCHAR(16) NOT NULL,           -- check_in | submit
    lat DOUBLE NOT NULL,
    lng DOUBLE NOT NULL,
    accuracy_m INTEGER NULL,
    miles_from_site DOUBLE NOT NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- Optional detailed instructions from the provider, printed as a separate page after the work order.
CREATE TABLE IF NOT EXISTS job_instructions (
    work_order_id INTEGER PRIMARY KEY,
    body TEXT NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- Provider's reusable job boilerplate: scope, skills, pay, requested photos, instructions.
CREATE TABLE IF NOT EXISTS job_templates (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    provider_id INTEGER NOT NULL,
    name VARCHAR(120) NOT NULL,
    data TEXT NOT NULL,                   -- JSON of the job form fields
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- Tech's phone for voice calls from providers. Shown only on jobs the tech is booked for, and only if they agree.
CREATE TABLE IF NOT EXISTS tech_contact (
    user_id INTEGER PRIMARY KEY,
    phone VARCHAR(20) NOT NULL,
    show_to_providers TINYINT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- Remote support details for a booked job: the buyer's session link or code, and the tech's laptop remote ID.
CREATE TABLE IF NOT EXISTS job_remote (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    work_order_id INTEGER NOT NULL,
    side VARCHAR(10) NOT NULL,            -- provider | tech
    tool VARCHAR(40) NOT NULL,
    link VARCHAR(500) NULL,
    code VARCHAR(80) NULL,
    note VARCHAR(500) NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
;

-- Provider (buyer) company logo, printed large on their work orders.
CREATE TABLE IF NOT EXISTS provider_logos (
    user_id INTEGER PRIMARY KEY,
    file_name VARCHAR(80) NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
