-- KRA Nakuru Tax Filing Management System
-- Database schema for PostgreSQL (local install, database: kra_nakuru)
-- Safe to run more than once: existing tables and data are kept.

-- USERS TABLE
-- Roles: Admin (full access), Officer (manage taxpayers/returns), Citizen (self-service)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(120) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'Officer',
    taxpayer_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TAXPAYERS TABLE
-- Linked to citizen user via user_id (one-to-one)
CREATE TABLE IF NOT EXISTS taxpayers (
    id SERIAL PRIMARY KEY,
    kra_pin VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(120) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    taxpayer_type VARCHAR(20) NOT NULL DEFAULT 'Individual',
    user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TAX RETURNS TABLE
-- submitted_by tracks which citizen user filed the return
-- reviewed_by tracks which officer approved/rejected the return
CREATE TABLE IF NOT EXISTS tax_returns (
    id SERIAL PRIMARY KEY,
    taxpayer_id INT NOT NULL,
    tax_type VARCHAR(80) NOT NULL,
    tax_period VARCHAR(30) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    filing_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    notes TEXT DEFAULT NULL,
    submitted_by INT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT NULL,
    reviewed_at TIMESTAMP DEFAULT NULL
);

-- AUDIT LOG TABLE
-- Tracks all changes for accountability and record reconciliation
CREATE TABLE IF NOT EXISTS audit_log (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    old_values TEXT DEFAULT NULL,
    new_values TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- FOREIGN KEY CONSTRAINTS (added only if missing)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_user_taxpayer') THEN
        ALTER TABLE users ADD CONSTRAINT fk_user_taxpayer
            FOREIGN KEY (taxpayer_id) REFERENCES taxpayers(id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_taxpayer_user') THEN
        ALTER TABLE taxpayers ADD CONSTRAINT fk_taxpayer_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_return_taxpayer') THEN
        ALTER TABLE tax_returns ADD CONSTRAINT fk_return_taxpayer
            FOREIGN KEY (taxpayer_id) REFERENCES taxpayers(id) ON DELETE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_return_submitted_by') THEN
        ALTER TABLE tax_returns ADD CONSTRAINT fk_return_submitted_by
            FOREIGN KEY (submitted_by) REFERENCES users(id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_return_reviewed_by') THEN
        ALTER TABLE tax_returns ADD CONSTRAINT fk_return_reviewed_by
            FOREIGN KEY (reviewed_by) REFERENCES users(id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_audit_user') THEN
        ALTER TABLE audit_log ADD CONSTRAINT fk_audit_user
            FOREIGN KEY (user_id) REFERENCES users(id);
    END IF;
END $$;

-- INDEXES
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_table ON audit_log(table_name, record_id);
CREATE INDEX IF NOT EXISTS idx_return_status ON tax_returns(status);

-- ONE-TO-ONE LINK: a taxpayer record can belong to only one user account,
-- and a user account to only one taxpayer record.
-- (If this fails with "could not create unique index", two users share a
--  taxpayer_id. Fix it with the query in the notes, then run this again.)
CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_taxpayer
    ON users(taxpayer_id) WHERE taxpayer_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_taxpayers_user
    ON taxpayers(user_id) WHERE user_id IS NOT NULL;

-- Default admin login: username admin, password Admin@123
-- Change this password after first login.
INSERT INTO users (full_name, username, email, password, role)
VALUES ('System Administrator', 'admin', 'admin@example.com',
'$2y$12$BrFYBlmiXL4W12xf1PYoPeu2Ng.eNx69Wq6X6OxT8VV2kaSRI2.MC', 'Admin')
ON CONFLICT (username) DO NOTHING;

-- ===== LATER ADDITIONS =====

-- National ID for each taxpayer (citizens can log in with KRA PIN or National ID)
ALTER TABLE taxpayers ADD COLUMN IF NOT EXISTS id_number VARCHAR(20) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_taxpayers_id_number
    ON taxpayers(id_number) WHERE id_number IS NOT NULL;

-- PAYMENTS TABLE
-- A tax return can have many payments; each payment gets a receipt number
CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    return_id INT NOT NULL REFERENCES tax_returns(id) ON DELETE CASCADE,
    amount DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    mode VARCHAR(30) NOT NULL,
    receipt_no VARCHAR(30) NOT NULL UNIQUE,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    recorded_by INT REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_payments_return ON payments(return_id);

-- Daraja STK requests remain separate from confirmed payments until Safaricom
-- confirms the transaction through the callback endpoint.
CREATE TABLE IF NOT EXISTS mpesa_stk_requests (
    id SERIAL PRIMARY KEY,
    return_id INT NOT NULL REFERENCES tax_returns(id) ON DELETE CASCADE,
    requested_by INT REFERENCES users(id),
    phone VARCHAR(15) NOT NULL,
    amount DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    merchant_request_id VARCHAR(100) DEFAULT NULL,
    checkout_request_id VARCHAR(100) UNIQUE DEFAULT NULL,
    callback_token_hash CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending'
        CHECK (status IN ('Pending', 'Completed', 'Failed', 'Review')),
    result_code VARCHAR(20) DEFAULT NULL,
    result_desc TEXT DEFAULT NULL,
    mpesa_receipt_no VARCHAR(30) DEFAULT NULL,
    payment_id INT UNIQUE REFERENCES payments(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mpesa_stk_return_status
    ON mpesa_stk_requests(return_id, status);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_mpesa_stk_callback_token
    ON mpesa_stk_requests(callback_token_hash);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_mpesa_stk_receipt
    ON mpesa_stk_requests(mpesa_receipt_no) WHERE mpesa_receipt_no IS NOT NULL;

-- ===== ONE-TIME DATA REPAIR (runs automatically, once) =====
-- An early version of the signup form let a new account claim another person's
-- taxpayer record. This repair finds citizen accounts whose name does not match
-- the taxpayer record they claimed, unlinks them, and frees the KRA PIN they
-- were using as a username (the username becomes 'unlinked_<id>').
-- It runs only once: schema_fixes records that it was applied, so running this
-- script again will not repeat it. It never deletes rows, because deleting a
-- user would also delete the linked taxpayer record and its returns.
CREATE TABLE IF NOT EXISTS schema_fixes (
    name VARCHAR(80) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DO $$
DECLARE
    bad_ids INT[];
BEGIN
    IF NOT EXISTS (SELECT 1 FROM schema_fixes WHERE name = 'unlink_mismatched_citizen_accounts') THEN
        SELECT array_agg(u.id) INTO bad_ids
        FROM users u JOIN taxpayers t ON t.user_id = u.id
        WHERE u.role = 'Citizen'
          AND lower(regexp_replace(trim(u.full_name), '\s+', ' ', 'g'))
           <> lower(regexp_replace(trim(t.full_name), '\s+', ' ', 'g'));

        IF bad_ids IS NOT NULL THEN
            UPDATE taxpayers SET user_id = NULL WHERE user_id = ANY(bad_ids);
            UPDATE users SET taxpayer_id = NULL, username = 'unlinked_' || id WHERE id = ANY(bad_ids);
        END IF;

        INSERT INTO schema_fixes (name) VALUES ('unlink_mismatched_citizen_accounts');
    END IF;
END $$;


-- ===== KEEP CITIZEN ACCOUNTS IN SYNC WITH TAXPAYER RECORDS =====
-- When an officer or administrator edits a taxpayer's name, email or KRA PIN,
-- the linked citizen account is updated automatically, so the two never drift
-- apart. This works for every page that edits taxpayers, because it is done by
-- the database itself. It only affects citizen accounts, never staff accounts.
-- (Placed after the one-time data repair on purpose: the repair looks for name
--  mismatches, so the sync must not exist before it has run.)
CREATE OR REPLACE FUNCTION sync_taxpayer_to_user() RETURNS trigger AS $$
BEGIN
    IF NEW.user_id IS NOT NULL THEN
        UPDATE users
        SET full_name = NEW.full_name,
            email = NEW.email,
            username = UPPER(NEW.kra_pin)
        WHERE id = NEW.user_id AND role = 'Citizen';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sync_taxpayer_to_user ON taxpayers;
CREATE TRIGGER trg_sync_taxpayer_to_user
    AFTER UPDATE OF full_name, email, kra_pin ON taxpayers
    FOR EACH ROW
    WHEN (OLD.full_name IS DISTINCT FROM NEW.full_name
       OR OLD.email IS DISTINCT FROM NEW.email
       OR OLD.kra_pin IS DISTINCT FROM NEW.kra_pin)
    EXECUTE FUNCTION sync_taxpayer_to_user();


-- ===== FORCE A PASSWORD CHANGE WHILE THE DEFAULT ADMIN PASSWORD IS IN USE =====
-- must_change_password is switched on for the default admin account only while
-- its password is still the original default hash. After a rebuild the admin is
-- asked to choose a new password at first login. Once the password is changed
-- the hash differs, so this never switches the flag on again.
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE;
UPDATE users SET must_change_password = TRUE
 WHERE username = 'admin'
   AND password = '$2y$12$BrFYBlmiXL4W12xf1PYoPeu2Ng.eNx69Wq6X6OxT8VV2kaSRI2.MC';-- KRA Nakuru Tax Filing Management System
-- Database schema for PostgreSQL (local install, database: kra_nakuru)
-- Safe to run more than once: existing tables and data are kept.

-- USERS TABLE
-- Roles: Admin (full access), Officer (manage taxpayers/returns), Citizen (self-service)
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(120) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'Officer',
    taxpayer_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TAXPAYERS TABLE
-- Linked to citizen user via user_id (one-to-one)
CREATE TABLE IF NOT EXISTS taxpayers (
    id SERIAL PRIMARY KEY,
    kra_pin VARCHAR(30) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(120) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    taxpayer_type VARCHAR(20) NOT NULL DEFAULT 'Individual',
    user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- TAX RETURNS TABLE
-- submitted_by tracks which citizen user filed the return
-- reviewed_by tracks which officer approved/rejected the return
CREATE TABLE IF NOT EXISTS tax_returns (
    id SERIAL PRIMARY KEY,
    taxpayer_id INT NOT NULL,
    tax_type VARCHAR(80) NOT NULL,
    tax_period VARCHAR(30) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    filing_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    notes TEXT DEFAULT NULL,
    submitted_by INT DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT NULL,
    reviewed_at TIMESTAMP DEFAULT NULL
);

-- AUDIT LOG TABLE
-- Tracks all changes for accountability and record reconciliation
CREATE TABLE IF NOT EXISTS audit_log (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    table_name VARCHAR(50) NOT NULL,
    record_id INT NOT NULL,
    old_values TEXT DEFAULT NULL,
    new_values TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- FOREIGN KEY CONSTRAINTS (added only if missing)
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_user_taxpayer') THEN
        ALTER TABLE users ADD CONSTRAINT fk_user_taxpayer
            FOREIGN KEY (taxpayer_id) REFERENCES taxpayers(id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_taxpayer_user') THEN
        ALTER TABLE taxpayers ADD CONSTRAINT fk_taxpayer_user
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_return_taxpayer') THEN
        ALTER TABLE tax_returns ADD CONSTRAINT fk_return_taxpayer
            FOREIGN KEY (taxpayer_id) REFERENCES taxpayers(id) ON DELETE CASCADE;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_return_submitted_by') THEN
        ALTER TABLE tax_returns ADD CONSTRAINT fk_return_submitted_by
            FOREIGN KEY (submitted_by) REFERENCES users(id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_return_reviewed_by') THEN
        ALTER TABLE tax_returns ADD CONSTRAINT fk_return_reviewed_by
            FOREIGN KEY (reviewed_by) REFERENCES users(id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'fk_audit_user') THEN
        ALTER TABLE audit_log ADD CONSTRAINT fk_audit_user
            FOREIGN KEY (user_id) REFERENCES users(id);
    END IF;
END $$;

-- INDEXES
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_table ON audit_log(table_name, record_id);
CREATE INDEX IF NOT EXISTS idx_return_status ON tax_returns(status);

-- ONE-TO-ONE LINK: a taxpayer record can belong to only one user account,
-- and a user account to only one taxpayer record.
-- (If this fails with "could not create unique index", two users share a
--  taxpayer_id. Fix it with the query in the notes, then run this again.)
CREATE UNIQUE INDEX IF NOT EXISTS uniq_users_taxpayer
    ON users(taxpayer_id) WHERE taxpayer_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_taxpayers_user
    ON taxpayers(user_id) WHERE user_id IS NOT NULL;

-- Default admin login: username admin, password Admin@123
-- Change this password after first login.
INSERT INTO users (full_name, username, email, password, role)
VALUES ('System Administrator', 'admin', 'admin@example.com',
'$2y$12$BrFYBlmiXL4W12xf1PYoPeu2Ng.eNx69Wq6X6OxT8VV2kaSRI2.MC', 'Admin')
ON CONFLICT (username) DO NOTHING;

-- ===== LATER ADDITIONS =====

-- National ID for each taxpayer (citizens can log in with KRA PIN or National ID)
ALTER TABLE taxpayers ADD COLUMN IF NOT EXISTS id_number VARCHAR(20) DEFAULT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uniq_taxpayers_id_number
    ON taxpayers(id_number) WHERE id_number IS NOT NULL;

-- PAYMENTS TABLE
-- A tax return can have many payments; each payment gets a receipt number
CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    return_id INT NOT NULL REFERENCES tax_returns(id) ON DELETE CASCADE,
    amount DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    mode VARCHAR(30) NOT NULL,
    receipt_no VARCHAR(30) NOT NULL UNIQUE,
    paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    recorded_by INT REFERENCES users(id)
);
CREATE INDEX IF NOT EXISTS idx_payments_return ON payments(return_id);

-- Daraja STK requests remain separate from confirmed payments until Safaricom
-- confirms the transaction through the callback endpoint.
CREATE TABLE IF NOT EXISTS mpesa_stk_requests (
    id SERIAL PRIMARY KEY,
    return_id INT NOT NULL REFERENCES tax_returns(id) ON DELETE CASCADE,
    requested_by INT REFERENCES users(id),
    phone VARCHAR(15) NOT NULL,
    amount DECIMAL(12,2) NOT NULL CHECK (amount > 0),
    merchant_request_id VARCHAR(100) DEFAULT NULL,
    checkout_request_id VARCHAR(100) UNIQUE DEFAULT NULL,
    callback_token_hash CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending'
        CHECK (status IN ('Pending', 'Completed', 'Failed', 'Review')),
    result_code VARCHAR(20) DEFAULT NULL,
    result_desc TEXT DEFAULT NULL,
    mpesa_receipt_no VARCHAR(30) DEFAULT NULL,
    payment_id INT UNIQUE REFERENCES payments(id),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_mpesa_stk_return_status
    ON mpesa_stk_requests(return_id, status);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_mpesa_stk_callback_token
    ON mpesa_stk_requests(callback_token_hash);
CREATE UNIQUE INDEX IF NOT EXISTS uniq_mpesa_stk_receipt
    ON mpesa_stk_requests(mpesa_receipt_no) WHERE mpesa_receipt_no IS NOT NULL;

-- ===== ONE-TIME DATA REPAIR (runs automatically, once) =====
-- An early version of the signup form let a new account claim another person's
-- taxpayer record. This repair finds citizen accounts whose name does not match
-- the taxpayer record they claimed, unlinks them, and frees the KRA PIN they
-- were using as a username (the username becomes 'unlinked_<id>').
-- It runs only once: schema_fixes records that it was applied, so running this
-- script again will not repeat it. It never deletes rows, because deleting a
-- user would also delete the linked taxpayer record and its returns.
CREATE TABLE IF NOT EXISTS schema_fixes (
    name VARCHAR(80) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

DO $$
DECLARE
    bad_ids INT[];
BEGIN
    IF NOT EXISTS (SELECT 1 FROM schema_fixes WHERE name = 'unlink_mismatched_citizen_accounts') THEN
        SELECT array_agg(u.id) INTO bad_ids
        FROM users u JOIN taxpayers t ON t.user_id = u.id
        WHERE u.role = 'Citizen'
          AND lower(regexp_replace(trim(u.full_name), '\s+', ' ', 'g'))
           <> lower(regexp_replace(trim(t.full_name), '\s+', ' ', 'g'));

        IF bad_ids IS NOT NULL THEN
            UPDATE taxpayers SET user_id = NULL WHERE user_id = ANY(bad_ids);
            UPDATE users SET taxpayer_id = NULL, username = 'unlinked_' || id WHERE id = ANY(bad_ids);
        END IF;

        INSERT INTO schema_fixes (name) VALUES ('unlink_mismatched_citizen_accounts');
    END IF;
END $$;


-- ===== KEEP CITIZEN ACCOUNTS IN SYNC WITH TAXPAYER RECORDS =====
-- When an officer or administrator edits a taxpayer's name, email or KRA PIN,
-- the linked citizen account is updated automatically, so the two never drift
-- apart. This works for every page that edits taxpayers, because it is done by
-- the database itself. It only affects citizen accounts, never staff accounts.
-- (Placed after the one-time data repair on purpose: the repair looks for name
--  mismatches, so the sync must not exist before it has run.)
CREATE OR REPLACE FUNCTION sync_taxpayer_to_user() RETURNS trigger AS $$
BEGIN
    IF NEW.user_id IS NOT NULL THEN
        UPDATE users
        SET full_name = NEW.full_name,
            email = NEW.email,
            username = UPPER(NEW.kra_pin)
        WHERE id = NEW.user_id AND role = 'Citizen';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sync_taxpayer_to_user ON taxpayers;
CREATE TRIGGER trg_sync_taxpayer_to_user
    AFTER UPDATE OF full_name, email, kra_pin ON taxpayers
    FOR EACH ROW
    WHEN (OLD.full_name IS DISTINCT FROM NEW.full_name
       OR OLD.email IS DISTINCT FROM NEW.email
       OR OLD.kra_pin IS DISTINCT FROM NEW.kra_pin)
    EXECUTE FUNCTION sync_taxpayer_to_user();


-- ===== FORCE A PASSWORD CHANGE WHILE THE DEFAULT ADMIN PASSWORD IS IN USE =====
-- must_change_password is switched on for the default admin account only while
-- its password is still the original default hash. After a rebuild the admin is
-- asked to choose a new password at first login. Once the password is changed
-- the hash differs, so this never switches the flag on again.
ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT FALSE;
UPDATE users SET must_change_password = TRUE
 WHERE username = 'admin'
   AND password = '$2y$12$BrFYBlmiXL4W12xf1PYoPeu2Ng.eNx69Wq6X6OxT8VV2kaSRI2.MC';hhhjwkhcvjjhjjjsbn

/* ===== OLD SCHEMA (kept for reference, do not run) =====
... old schema here ...
-- -- KRA Nakuru Tax Filing Management System
-- -- Database schema for PostgreSQL (Neon cloud)
-- -- For MySQL/XAMPP, use the equivalent syntax.

-- -- USERS TABLE
-- -- Roles: Admin (full access), Officer (manage taxpayers/returns), Citizen (self-service)
-- CREATE TABLE IF NOT EXISTS users (
--     id SERIAL PRIMARY KEY,
--     full_name VARCHAR(120) NOT NULL,
--     username VARCHAR(60) NOT NULL UNIQUE,
--     email VARCHAR(120) DEFAULT NULL,
--     password VARCHAR(255) NOT NULL,
--     role VARCHAR(20) NOT NULL DEFAULT 'Officer',
--     taxpayer_id INT DEFAULT NULL,
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- );

-- -- TAXPAYERS TABLE
-- -- Linked to citizen user via user_id (one-to-one)
-- CREATE TABLE IF NOT EXISTS taxpayers (
--     id SERIAL PRIMARY KEY,
--     kra_pin VARCHAR(30) NOT NULL UNIQUE,
--     full_name VARCHAR(150) NOT NULL,
--     phone VARCHAR(30) DEFAULT NULL,
--     email VARCHAR(120) DEFAULT NULL,
--     address VARCHAR(255) DEFAULT NULL,
--     taxpayer_type VARCHAR(20) NOT NULL DEFAULT 'Individual',
--     user_id INT DEFAULT NULL,
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- );

-- -- TAX RETURNS TABLE
-- -- submitted_by tracks which citizen user filed the return
-- -- reviewed_by tracks which officer approved/rejected the return
-- CREATE TABLE IF NOT EXISTS tax_returns (
--     id SERIAL PRIMARY KEY,
--     taxpayer_id INT NOT NULL,
--     tax_type VARCHAR(80) NOT NULL,
--     tax_period VARCHAR(30) NOT NULL,
--     amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
--     filing_date DATE NOT NULL,
--     status VARCHAR(20) NOT NULL DEFAULT 'Pending',
--     notes TEXT DEFAULT NULL,
--     submitted_by INT DEFAULT NULL,
--     reviewed_by INT DEFAULT NULL,
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
--     updated_at TIMESTAMP DEFAULT NULL,
--     reviewed_at TIMESTAMP DEFAULT NULL
-- );

-- -- FOREIGN KEY CONSTRAINTS
-- ALTER TABLE users ADD CONSTRAINT fk_user_taxpayer FOREIGN KEY (taxpayer_id) REFERENCES taxpayers(id);
-- ALTER TABLE taxpayers ADD CONSTRAINT fk_taxpayer_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
-- ALTER TABLE tax_returns ADD CONSTRAINT fk_return_taxpayer FOREIGN KEY (taxpayer_id) REFERENCES taxpayers(id) ON DELETE CASCADE;
-- ALTER TABLE tax_returns ADD CONSTRAINT fk_return_submitted_by FOREIGN KEY (submitted_by) REFERENCES users(id);
-- ALTER TABLE tax_returns ADD CONSTRAINT fk_return_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id);

-- -- AUDIT LOG TABLE
-- -- Tracks all changes for accountability and record reconciliation
-- CREATE TABLE IF NOT EXISTS audit_log (
--     id SERIAL PRIMARY KEY,
--     user_id INT NOT NULL,
--     action VARCHAR(50) NOT NULL,
--     table_name VARCHAR(50) NOT NULL,
--     record_id INT NOT NULL,
--     old_values TEXT DEFAULT NULL,
--     new_values TEXT DEFAULT NULL,
--     ip_address VARCHAR(45) DEFAULT NULL,
--     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
-- );
-- ALTER TABLE audit_log ADD CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id);
-- CREATE INDEX idx_audit_user ON audit_log(user_id);
-- CREATE INDEX idx_audit_table ON audit_log(table_name, record_id);
-- CREATE INDEX idx_return_status ON tax_returns(status);

-- -- Default admin login: username admin, password Admin@123
-- INSERT INTO users (full_name, username, email, password, role)
-- VALUES ('System Administrator', 'admin', 'admin@example.com',
-- '$2y$12$BrFYBlmiXL4W12xf1PYoPeu2Ng.eNx69Wq6X6OxT8VV2kaSRI2.MC', 'Admin')
-- ON CONFLICT (username) DO NOTHING;

===== END OLD SCHEMA ===== */
