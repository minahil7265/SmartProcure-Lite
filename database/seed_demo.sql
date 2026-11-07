-- =====================================================================
-- SmartProcure Lite - optional demo data (departments, budgets, vendors)
--
-- No user accounts are created here. Create the first admin with:
--     php scripts/create_admin.php
-- and register everyone else through the signup page.
--
-- Run:  sudo mysql smartprocure_db < database/seed_demo.sql
-- Budgets use the current year, matching budget_period_format 'Y'
-- in config/config.php.
-- =====================================================================

INSERT INTO departments (department_name, description) VALUES
('Information Technology', 'Computers, networking and software'),
('Finance', 'Accounts, payroll and audit'),
('Human Resources', 'Hiring, training and staff welfare'),
('Operations', 'Facilities, transport and maintenance');

INSERT INTO budgets (department_id, budget_period, allocated_amount, committed_amount)
SELECT id, DATE_FORMAT(CURDATE(), '%Y'), 5000000, 0 FROM departments;

INSERT INTO vendors (vendor_name, contact_person, email, phone, address, status) VALUES
('TechZone Traders', 'Ali Raza', 'sales@techzone.example', '042-111-222-333', 'Hall Road, Lahore', 'Active'),
('Digital Hub Pvt Ltd', 'Sana Khan', 'info@digitalhub.example', '042-35761234', 'Gulberg III, Lahore', 'Active'),
('Prime Computers', 'Usman Tariq', 'orders@primecomp.example', '051-2287654', 'Blue Area, Islamabad', 'Active'),
('Old Office Supplies', 'Bilal Ahmed', 'contact@oldoffice.example', '042-37001122', 'Anarkali, Lahore', 'Inactive');
