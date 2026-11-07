-- =====================================================================
-- SmartProcure Lite - application database user (least privilege)
--
-- 1. Replace CHANGE_THIS_PASSWORD below with your own password.
-- 2. Put the SAME password in config/config.php.
-- 3. Run as MySQL root:  sudo mysql < database/create_app_user.sql
--
-- The app user can only SELECT and INSERT on approvals, deliveries,
-- rfq_vendors and audit_logs, so decision history and audit records
-- cannot be changed or deleted by the application.
-- =====================================================================

CREATE USER IF NOT EXISTS 'procure_user'@'localhost'
    IDENTIFIED BY 'CHANGE_THIS_PASSWORD';

GRANT SELECT, INSERT, UPDATE, DELETE ON smartprocure_db.departments            TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON smartprocure_db.users                  TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON smartprocure_db.budgets                TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON smartprocure_db.purchase_requests      TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON smartprocure_db.purchase_request_items TO 'procure_user'@'localhost';
GRANT SELECT, INSERT                 ON smartprocure_db.approvals              TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON smartprocure_db.vendors                TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON smartprocure_db.rfqs                   TO 'procure_user'@'localhost';
GRANT SELECT, INSERT                 ON smartprocure_db.rfq_vendors            TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON smartprocure_db.quotations             TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON smartprocure_db.quotation_items        TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON smartprocure_db.purchase_orders        TO 'procure_user'@'localhost';
GRANT SELECT, INSERT                 ON smartprocure_db.purchase_order_items   TO 'procure_user'@'localhost';
GRANT SELECT, INSERT                 ON smartprocure_db.deliveries             TO 'procure_user'@'localhost';
GRANT SELECT, INSERT                 ON smartprocure_db.delivery_items         TO 'procure_user'@'localhost';
GRANT SELECT, INSERT                 ON smartprocure_db.audit_logs             TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON smartprocure_db.settings               TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, DELETE         ON smartprocure_db.login_attempts         TO 'procure_user'@'localhost';
GRANT SELECT, INSERT, UPDATE         ON smartprocure_db.notifications          TO 'procure_user'@'localhost';

FLUSH PRIVILEGES;

SHOW GRANTS FOR 'procure_user'@'localhost';
