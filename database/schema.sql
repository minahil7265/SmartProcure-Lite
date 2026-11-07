-- =====================================================================
-- SmartProcure Lite - Database schema
-- Run as MySQL root:  sudo mysql < database/schema.sql
-- Creates the database: the 14 core tables from the proposal plus
-- 5 tables for the vendor portal, settings, rate limiting and notifications.
-- =====================================================================

CREATE DATABASE IF NOT EXISTS smartprocure_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE smartprocure_db;

-- ---------------------------------------------------------------------
-- 1. departments
-- ---------------------------------------------------------------------
CREATE TABLE departments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(100) NOT NULL UNIQUE,
    description     VARCHAR(255) NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2a. vendors (created before users because vendor accounts link to it)  (deactivated, never deleted)
-- ---------------------------------------------------------------------
CREATE TABLE vendors (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    vendor_name    VARCHAR(150) NOT NULL,
    contact_person VARCHAR(100) NULL,
    email          VARCHAR(150) NULL,
    phone          VARCHAR(30) NULL,
    address        VARCHAR(255) NULL,
    status         ENUM('Active','Inactive') NOT NULL DEFAULT 'Active'
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. users
-- role: every new account is 'employee' by default.
-- is_active = 0 and approved_at IS NULL     -> waiting for admin approval
-- is_active = 0 and approved_at IS NOT NULL -> deactivated by admin
-- approved_at: tells "pending" accounts apart from "deactivated" ones.
-- vendor_id:   set only for role 'vendor'; links the login to ONE vendor
--              company. Every vendor page checks ownership against it.
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(100) NOT NULL,
    email         VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('employee','manager','procurement','admin','vendor') NOT NULL DEFAULT 'employee',
    department_id INT NULL,
    vendor_id     INT NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 0,
    approved_at   DATETIME NULL,
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_users_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. budgets  (one row per department per period)
-- ---------------------------------------------------------------------
CREATE TABLE budgets (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    department_id    INT NOT NULL,
    budget_period    VARCHAR(20) NOT NULL,
    allocated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    committed_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    CONSTRAINT uq_budget_period UNIQUE (department_id, budget_period),
    CONSTRAINT fk_budgets_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT chk_budget_allocated CHECK (allocated_amount >= 0),
    CONSTRAINT chk_budget_committed CHECK (committed_amount >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. purchase_requests
-- ---------------------------------------------------------------------
CREATE TABLE purchase_requests (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    requester_id    INT NOT NULL,
    department_id   INT NOT NULL,
    request_date    DATE NOT NULL,
    required_date   DATE NULL,
    status          ENUM('Draft','Submitted','Approved','Rejected','Cancelled') NOT NULL DEFAULT 'Draft',
    purpose         VARCHAR(500) NOT NULL,
    estimated_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    submitted_at    DATETIME NULL,  -- when it was submitted (starts the escalation clock)
    CONSTRAINT fk_requests_user FOREIGN KEY (requester_id) REFERENCES users(id),
    CONSTRAINT fk_requests_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT chk_request_total CHECK (estimated_total >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. purchase_request_items
-- ---------------------------------------------------------------------
CREATE TABLE purchase_request_items (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    request_id           INT NOT NULL,
    item_name            VARCHAR(150) NOT NULL,
    description          VARCHAR(255) NULL,
    quantity             INT NOT NULL,
    estimated_unit_price DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_request_items_request FOREIGN KEY (request_id) REFERENCES purchase_requests(id) ON DELETE CASCADE,
    CONSTRAINT chk_request_item_qty CHECK (quantity > 0),
    CONSTRAINT chk_request_item_price CHECK (estimated_unit_price >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. approvals  (decision history - never edited)
-- ---------------------------------------------------------------------
CREATE TABLE approvals (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    request_id    INT NOT NULL,
    approver_id   INT NOT NULL,
    decision      ENUM('Approved','Rejected') NOT NULL,
    comments      VARCHAR(500) NULL,
    is_escalated  TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = decided by admin after escalation
    decision_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_approvals_request FOREIGN KEY (request_id) REFERENCES purchase_requests(id),
    CONSTRAINT fk_approvals_user FOREIGN KEY (approver_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7a. rfqs  (Request for Quotation sent to chosen vendors)
-- Open: vendors may quote until the deadline. Awarded: an order exists.
-- ---------------------------------------------------------------------
CREATE TABLE rfqs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    request_id  INT NOT NULL,
    created_by  INT NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deadline    DATETIME NOT NULL,
    notes       VARCHAR(500) NULL,     -- message shown to vendors
    status      ENUM('Open','Awarded','Cancelled') NOT NULL DEFAULT 'Open',
    CONSTRAINT fk_rfqs_request FOREIGN KEY (request_id) REFERENCES purchase_requests(id),
    CONSTRAINT fk_rfqs_user FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7b. rfq_vendors  (which vendors were invited)
-- ---------------------------------------------------------------------
CREATE TABLE rfq_vendors (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    rfq_id     INT NOT NULL,
    vendor_id  INT NOT NULL,
    invited_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_rfq_vendor UNIQUE (rfq_id, vendor_id),
    CONSTRAINT fk_rfqv_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs(id),
    CONSTRAINT fk_rfqv_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. quotations
-- ---------------------------------------------------------------------
-- quality_rating is a SNAPSHOT of the vendor's delivery-reliability
-- rating (1-5) taken from order history when the quotation is submitted.
-- The screens call it "delivery reliability", because that is what it
-- measures (it is not product quality). rating_calculated keeps the
-- value from history; an officer override must give a reason.
CREATE TABLE quotations (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    request_id             INT NOT NULL,
    rfq_id                 INT NOT NULL,
    vendor_id              INT NOT NULL,
    submitted_by           INT NOT NULL,           -- the vendor user who submitted it
    quotation_date         DATE NOT NULL,
    submitted_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivery_days          INT NOT NULL,
    validity_date          DATE NOT NULL,
    warranty_months        INT NOT NULL DEFAULT 0,
    quality_rating         TINYINT NOT NULL,
    rating_calculated      TINYINT NOT NULL,
    rating_override_reason VARCHAR(255) NULL,
    status                 ENUM('Received','Selected','Rejected') NOT NULL DEFAULT 'Received',
    CONSTRAINT uq_quotation_rfq_vendor UNIQUE (rfq_id, vendor_id),
    CONSTRAINT fk_quotations_request FOREIGN KEY (request_id) REFERENCES purchase_requests(id),
    CONSTRAINT fk_quotations_rfq FOREIGN KEY (rfq_id) REFERENCES rfqs(id),
    CONSTRAINT fk_quotations_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
    CONSTRAINT fk_quotations_user FOREIGN KEY (submitted_by) REFERENCES users(id),
    CONSTRAINT chk_quotation_warranty CHECK (warranty_months >= 0),
    CONSTRAINT chk_quotation_calc CHECK (rating_calculated BETWEEN 1 AND 5),
    CONSTRAINT chk_quotation_days CHECK (delivery_days > 0),
    CONSTRAINT chk_quotation_rating CHECK (quality_rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. quotation_items
-- ---------------------------------------------------------------------
CREATE TABLE quotation_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    quotation_id    INT NOT NULL,
    request_item_id INT NOT NULL,
    quoted_quantity INT NOT NULL,
    unit_price      DECIMAL(12,2) NOT NULL,
    CONSTRAINT uq_quotation_item UNIQUE (quotation_id, request_item_id),
    CONSTRAINT fk_qitems_quotation FOREIGN KEY (quotation_id) REFERENCES quotations(id) ON DELETE CASCADE,
    CONSTRAINT fk_qitems_request_item FOREIGN KEY (request_item_id) REFERENCES purchase_request_items(id),
    CONSTRAINT chk_qitem_qty CHECK (quoted_quantity > 0),
    CONSTRAINT chk_qitem_price CHECK (unit_price >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. purchase_orders
-- ---------------------------------------------------------------------
CREATE TABLE purchase_orders (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    request_id             INT NOT NULL,
    vendor_id              INT NOT NULL,
    quotation_id           INT NOT NULL,
    created_by             INT NOT NULL,
    order_date             DATE NOT NULL,
    expected_delivery_date DATE NOT NULL,
    -- Created = sent, waiting for the vendor to accept or decline
    status                 ENUM('Created','Accepted','Declined','Partially Delivered','Delivered','Cancelled') NOT NULL DEFAULT 'Created',
    total_amount           DECIMAL(14,2) NOT NULL,
    vendor_response_at     DATETIME NULL,
    vendor_response_note   VARCHAR(500) NULL,
    CONSTRAINT fk_po_request FOREIGN KEY (request_id) REFERENCES purchase_requests(id),
    CONSTRAINT fk_po_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id),
    CONSTRAINT fk_po_quotation FOREIGN KEY (quotation_id) REFERENCES quotations(id),
    CONSTRAINT fk_po_user FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT chk_po_total CHECK (total_amount >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. purchase_order_items
-- ---------------------------------------------------------------------
CREATE TABLE purchase_order_items (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    request_item_id   INT NOT NULL,
    item_name         VARCHAR(150) NOT NULL,
    quantity_ordered  INT NOT NULL,
    unit_price        DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_poitems_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
    CONSTRAINT fk_poitems_request_item FOREIGN KEY (request_item_id) REFERENCES purchase_request_items(id),
    CONSTRAINT chk_poitem_qty CHECK (quantity_ordered > 0),
    CONSTRAINT chk_poitem_price CHECK (unit_price >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 12. deliveries
-- ---------------------------------------------------------------------
CREATE TABLE deliveries (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    purchase_order_id INT NOT NULL,
    received_date     DATE NOT NULL,
    delivery_status   ENUM('Partial','Complete') NOT NULL,
    notes             VARCHAR(500) NULL,
    recorded_by       INT NOT NULL,
    CONSTRAINT fk_deliveries_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id),
    CONSTRAINT fk_deliveries_user FOREIGN KEY (recorded_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 13. delivery_items
-- ---------------------------------------------------------------------
CREATE TABLE delivery_items (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id            INT NOT NULL,
    purchase_order_item_id INT NOT NULL,
    quantity_received      INT NOT NULL,
    CONSTRAINT uq_delivery_item UNIQUE (delivery_id, purchase_order_item_id),
    CONSTRAINT fk_ditems_delivery FOREIGN KEY (delivery_id) REFERENCES deliveries(id),
    CONSTRAINT fk_ditems_po_item FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items(id),
    CONSTRAINT chk_ditem_qty CHECK (quantity_received > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 14. audit_logs  (application user gets only SELECT + INSERT here)
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    action      VARCHAR(60) NOT NULL,
    entity_type VARCHAR(40) NULL,
    entity_id   INT NULL,
    details     TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================================
-- Support tables
-- =====================================================================

-- ---------------------------------------------------------------------
-- 15. settings  (values the admin can change in the app, e.g. weights)
-- If a key is missing, the default from config/config.php is used.
-- ---------------------------------------------------------------------
CREATE TABLE settings (
    setting_key   VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by    INT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 16. login_attempts  (failed logins, for the rate limit)
-- ---------------------------------------------------------------------
CREATE TABLE login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    email        VARCHAR(150) NOT NULL,
    ip_address   VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempts_email (email, attempted_at),
    INDEX idx_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 17. notifications  (in-app messages; email can be added later)
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    message    VARCHAR(255) NOT NULL,
    link       VARCHAR(255) NULL,
    is_read    TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id, is_read),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;
