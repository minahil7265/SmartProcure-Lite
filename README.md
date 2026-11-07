# SmartProcure Lite

Intelligent Purchasing & Vendor Evaluation System
PHP 8 · MySQL · PDO · HTML5 · CSS3 · JavaScript · Chart.js (no frameworks)

**The flow**

```text
Employee raises a request ──► Manager approves (or Admin, if escalated)
        ──► Procurement officer sends an RFQ to chosen vendors, with a deadline
        ──► Vendors log in and submit sealed quotations (price, delivery, warranty)
        ──► After the deadline: officer compares independent scores and selects
        ──► Purchase order sent ──► Vendor accepts or declines
        ──► Officer records deliveries (partial or complete)
```

Budgets, dashboards, in-app notifications and an audit log cover the whole flow.

---

## 1. Project structure

Only `public/` is reachable from the browser. Configuration, logic and SQL sit
outside it, as in the course guides.

```text
/var/www/smartprocure/
├── config/
│   ├── config.example.php   ← copy to config.php and edit (all settings live here)
│   └── database.php         ← PDO connection ($pdo)
├── includes/
│   ├── bootstrap.php        ← every page loads this one file
│   ├── constants.php        ← roles and statuses
│   ├── session.php          ← safe session cookie, idle timeout, destroySession()
│   ├── auth.php             ← requireLogin(), requireRole(), requireVendor()
│   ├── csrf.php             ← csrfField(), verifyCsrf()
│   ├── helpers.php          ← e(), url(), redirect(), flash(), money()
│   ├── navigation.php       ← sidebar links per role
│   ├── header.php / footer.php  ← layout, breadcrumbs, navigation
│   └── partials/            ← shared request and vendor forms
├── app/                     ← business rules
│   ├── audit.php            ← audit log
│   ├── approvals.php        ← who may decide; escalation to the admin
│   ├── budget.php           ← budget periods, available amount
│   ├── deliveries.php       ← partial deliveries (transactions)
│   ├── notifications.php    ← in-app notifications
│   ├── orders.php           ← create / cancel / vendor accept-decline (transactions)
│   ├── ratelimit.php        ← login rate limit
│   ├── reliability.php      ← delivery-reliability rating from order history
│   ├── requests.php         ← request validation and access rules
│   ├── rfqs.php             ← RFQs, invitations, sealed vendor quotations
│   ├── scoring.php          ← eligibility + independent scoring
│   ├── settings.php         ← admin-editable settings (scoring weights)
│   └── vendors.php
├── public/                  ← DocumentRoot
│   ├── index.php, login.php, signup.php, logout.php, dashboard.php,
│   │   profile.php, notifications.php
│   ├── requests/   (index, create, edit, view, action)
│   ├── approvals/  (index, review)             ← managers; admin for escalations
│   ├── rfqs/       (index, create, view, override, cancel)   ← procurement
│   ├── orders/     (index, create, view, cancel)
│   ├── deliveries/ (create)
│   ├── vendors/    (index, create, edit, view)
│   ├── vendor/     (rfqs, rfq, orders, order) ← the VENDOR PORTAL
│   ├── admin/      (users, user_create, user_edit, user_action, departments,
│   │                department_edit, department_delete, budgets, budget_edit,
│   │                settings, audit_logs)
│   └── assets/     (css/style.css, js/app.js)
├── database/
│   ├── schema.sql           ← database + 19 tables
│   ├── create_app_user.sql  ← least-privilege MySQL user
│   └── seed_demo.sql        ← optional departments, budgets, vendors
├── scripts/
│   ├── create_admin.php     ← creates the first admin from the terminal
│   └── db_check.php         ← tests the connection with PDO and MySQLi
└── tests/
    └── run_tests.php        ← 55 tests: scoring, reliability, escalation, deliveries...
```

---

## 2. Install and run (Ubuntu)

> **Already installed the earlier version?** The database changed (19 tables
> instead of 14), so start fresh. This deletes the old test data:
>
> ```bash
> sudo mysql -e "DROP DATABASE IF EXISTS smartprocure_db; DROP USER IF EXISTS 'procure_user'@'localhost';"
> sudo rm -rf /var/www/smartprocure
> ```
>
> Then follow every step below again.

### Step 1: Packages (skip if you already did the Ubuntu guide)

```bash
sudo apt update
sudo apt install apache2 mysql-server php libapache2-mod-php php-mysql php-cli php-mbstring php-xml -y
sudo systemctl enable --now apache2 mysql
php -v
```

### Step 2: Copy the project

From the folder where you extracted the zip:

```bash
sudo cp -r smartprocure /var/www/smartprocure
sudo chown -R $USER:www-data /var/www/smartprocure
sudo chmod -R 755 /var/www/smartprocure
cd /var/www/smartprocure
```

### Step 3: Create the database and tables

```bash
sudo mysql < database/schema.sql
```

### Step 4: Create the application database user

Choose a password, put it in the SQL file, then run it:

```bash
nano database/create_app_user.sql      # replace CHANGE_THIS_PASSWORD
sudo mysql < database/create_app_user.sql
```

### Step 5: Configuration

```bash
cp config/config.example.php config/config.php
nano config/config.php                 # put the SAME password under 'db' => 'password'
chmod 640 config/config.php
```

Other settings in that file: currency, timezone, idle timeout, budget period
format, and the scoring weights (50 / 30 / 20).

### Step 6: Demo data (optional but recommended)

```bash
sudo mysql smartprocure_db < database/seed_demo.sql
```

This adds 4 departments, a budget for each for this year, and 4 vendors
(one inactive). It creates no user accounts.

### Step 7: Create the first administrator

```bash
php scripts/create_admin.php
```

### Step 8: Check the connection

```bash
php scripts/db_check.php
```

Expected:

```text
[OK]   PDO connected. Tables found: 19 (expected 19)
[OK]   MySQLi connected. Administrators: 1
[OK]   The app user cannot delete audit logs (as required).
```

### Step 9: Apache virtual host

```bash
sudo nano /etc/apache2/sites-available/smartprocure.conf
```

```apache
<VirtualHost *:80>
    ServerName smartprocure.local
    DocumentRoot /var/www/smartprocure/public

    <Directory /var/www/smartprocure/public>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/smartprocure_error.log
    CustomLog ${APACHE_LOG_DIR}/smartprocure_access.log combined
</VirtualHost>
```

```bash
sudo a2ensite smartprocure.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### Step 10: Local domain

```bash
echo "127.0.0.1 smartprocure.local" | sudo tee -a /etc/hosts
getent hosts smartprocure.local
```

Open **http://smartprocure.local** and log in with the admin you created.

### Step 11: Run the tests

```bash
php tests/run_tests.php
```

---

## 3. First run and demonstration

Use a different browser (or a private window) for each user, or log out between users.

### 3.1 Create the accounts (as admin)

*Users → Add user*. Staff need a department; vendors need a vendor company and no department.

| Name | Role | Department / company |
|---|---|---|
| Employee | Employee | Information Technology |
| Manager | Manager | Information Technology |
| Officer | Procurement Officer | Information Technology |
| Vendor A | Vendor | TechZone Traders |
| Vendor B | Vendor | Digital Hub Pvt Ltd |
| Vendor C | Vendor | Prime Computers |

Signing up on the public page also works for staff: the account starts as an
inactive **employee** until the admin approves it. Vendors cannot sign up.

### 3.2 The full flow

1. **Employee:** *New request* → "Desktop computer", quantity 20, estimated unit price
   5,500, needed by 14 days from today → **Submit for approval**.
2. **Manager:** *Approvals* → **Review** → **Approve**.
3. **Officer:** *RFQs* → **Create RFQ** → tick the three vendors → set the deadline
   **5 minutes from now** (for a quick demo) → **Send RFQ**.
4. **Each vendor:** *My RFQs* → **Submit quotation**:

   | Vendor | Unit price | Delivery days | Warranty |
   |---|---|---|---|
   | A | 5,000 | 8 | 12 |
   | B | 6,000 | 6 | 24 |
   | C | 2,000 | 30 | 6 |

5. **Officer**, before the deadline: the RFQ shows "3 of 3 vendors" but **no prices**
   (sealed bids). After the deadline it shows the scores:
   A **92.00**, B **87.83**, C **76.00** (new vendors all have reliability 3 / 5).
6. **Officer:** **Select vendor** for A → **Create and send purchase order**.
7. **Vendor A:** *My orders* → **Decline** with a reason. The budget is released.
8. **Officer:** back on the RFQ, select B → **Vendor B** accepts.
9. **Officer:** on the order, **Record delivery** 15 → *Partially Delivered*,
   then 5 → *Delivered*.
10. **Admin:** *Audit log* → filter `vendor_selected` to see the saved ranking,
    weights and formula version.

### 3.3 Show the other features

- **Escalation:** create a manager in *Finance* (the only one there) and let them
  submit a request. It reaches the admin's *Escalated approvals* after
  `escalation_days` (3). For a live demo, set `'escalation_days' => 0` in
  `config/config.php`, then set it back.
- **Scoring weights:** *Scoring settings* → e.g. 40 / 40 / 20 → the comparison changes.
  Weights that do not add up to 100 are refused.
- **Rate limit:** type a wrong password 5 times → the 6th try is refused, even with
  the right password, for 15 minutes.
- **Vendor security:** log in as vendor C and open an RFQ number it was not
  invited to: it shows "not found", exactly like an RFQ that does not exist.

---

## 4. Where each requirement is implemented

| Requirement | Where |
|---|---|
| Login, logout, password hashing | `public/login.php`, `logout.php`, `password_hash` / `password_verify` |
| Default role employee, admin approval of new accounts | `users.role DEFAULT 'employee'`, `is_active DEFAULT 0`; `admin/users.php` + `user_action.php` |
| Roles: Employee, Manager, Procurement Officer, Administrator | `includes/constants.php`, `requireRole()` on every page |
| Role-based redirect and navigation | `dashboard.php`, `includes/navigation.php` |
| Session security | `includes/session.php`: HttpOnly, SameSite, strict mode, ID regenerated at login, idle timeout |
| Logout ends session and cookie | `destroySession()`: clears `$_SESSION`, expires cookie, `session_destroy()`; POST + CSRF; no-cache headers |
| CSRF | `includes/csrf.php`; `verifyCsrf()` on every POST |
| SQL injection | PDO prepared statements everywhere, `ATTR_EMULATE_PREPARES = false` |
| XSS | every output goes through `e()` (`htmlspecialchars`) |
| Breadcrumbs and navigation | `includes/header.php` (`$breadcrumbs` set on every page) |
| Departments and budgets | `admin/departments.php`, `admin/budgets.php`, `app/budget.php` |
| Requests with several items, statuses | `requests/*`, estimated total recalculated on the server |
| Approvals, comment required on rejection, no self-approval | `approvals/review.php` |
| Vendors (inactive kept in history) | `vendors/*` (no delete; status Active/Inactive) |
| Purchase orders, one active per request, transaction + row locks | `app/orders.php` |
| Partial deliveries, never above remaining | `app/deliveries.php`, `deliveries/create.php` |
| Overdue orders highlighted | `isOverdue()`, orders list, dashboard |
| Dashboards with Chart.js | `dashboard.php` + `assets/js/app.js` |
| Audit log that cannot be edited | `app/audit.php`; app user has only SELECT + INSERT (`create_app_user.sql`) |
| PDO and MySQLi | app uses PDO; `scripts/db_check.php` shows both |
| Vendor role and portal (RFQs, quotations, PO accept/decline) | `public/vendor/*`, `app/rfqs.php`, `app/orders.php` |
| Ownership checks on every vendor page | `requireVendor()`, `vendorIsInvited()`, order `vendor_id` check; vendor ID only from the session |
| Sealed bids, deadline enforced on the server | `rfqs/view.php` loads prices only after the deadline; `saveVendorQuotation()` re-checks it inside a locked transaction |
| Escalation to admin (single-manager departments) | `app/approvals.php`, `approvals/index.php`, `approvals/review.php` |
| Independent scoring, weights editable by admin | `app/scoring.php`, `admin/settings.php` (sum 100), weights + formula version in the audit log |
| Delivery-reliability rating from history, override with reason | `app/reliability.php`, `rfqs/override.php` |
| Login rate limit | `app/ratelimit.php`, `login.php` |
| In-app notifications | `app/notifications.php`, `notifications.php` |
| Testing | `tests/run_tests.php` |

---

## 5. Who can do what

| Page | Employee | Manager | Procurement | Admin | Vendor |
|---|---|---|---|---|---|
| Own requests (create, edit, cancel) | ✓ | ✓ | ✓ | | |
| Approve / reject (own department, not own) | | ✓ | | escalated only | |
| RFQs, comparison, purchase orders, deliveries | | | ✓ | orders: view only | |
| Vendor directory | | | ✓ | view only | |
| Users, departments, budgets, scoring settings, audit log | | | | ✓ | |
| Own RFQs and quotations, own orders (accept/decline) | | | | | ✓ |

Every page checks the role on the server. Hiding a link is only for convenience.
A vendor never sees requests, budgets, estimates, other vendors or the audit log.

---

## 6. Troubleshooting

| Problem | Fix |
|---|---|
| "Missing config/config.php" | `cp config/config.example.php config/config.php` |
| "Database connection failed" | Password in `config.php` must match `create_app_user.sql`. Run `php scripts/db_check.php`. |
| Error log shows `SQLSTATE[HY000] [2002]` | MySQL is not running or not reachable: `sudo systemctl status mysql`, then `sudo systemctl start mysql`. |
| Error log shows `[1045] Access denied` | Wrong database password: make `config.php` match the one in `create_app_user.sql`. |
| "Form expired" page | Reload the page and submit again (CSRF token changed after login/logout). |
| Charts or font not showing | Chart.js and the font load from the internet; the rest works offline. |
| Apache prints "AH00558: Could not reliably determine the server's fully qualified domain name" | Harmless notice. To silence it: `echo "ServerName localhost" \| sudo tee /etc/apache2/conf-available/servername.conf && sudo a2enconf servername && sudo systemctl reload apache2` |
| `Call to undefined function mb_strlen` | `sudo apt install php-mbstring && sudo systemctl reload apache2` |
| "Too many failed attempts" | Wait 15 minutes, or clear the counter: `sudo mysql smartprocure_db -e "DELETE FROM login_attempts"` |
| A vendor cannot log in | The vendor company is *Inactive*, or the account is not linked to a company (admin: *Users → Edit*). |
| No vendors can be ticked on *Create RFQ* | Only active vendors with a login account can be invited. Admin: *Users → Add user → role Vendor*. |
| Comparison shows "Bids are sealed" | The deadline has not passed yet. Prices appear after it. |
| Cannot record a delivery | The vendor has not accepted the order yet. |
| Page shows PHP code / 404 | Check `DocumentRoot` points to `/var/www/smartprocure/public`. |
| See real errors | `sudo tail -f /var/log/apache2/smartprocure_error.log` or set `'debug' => true` in config.php while developing |

---

## 7. Settings in `config/config.php`

| Setting | Default | Meaning |
|---|---|---|
| `scoring` weights | 50 / 30 / 20 | Starting weights; the admin changes them on *Scoring settings* |
| `scoring.fallback_delivery_days` | 14 | Days assumed when a request has no needed-by date |
| `reliability.min_orders` / `default_rating` | 3 / 3 | Vendors with fewer completed orders get the default rating |
| `reliability.thresholds` | 95 / 85 / 70 / 50 % | On-time % needed for ratings 5 / 4 / 3 / 2 (below = 1) |
| `rfq.min_vendors` | 2 | Vendors to invite at least |
| `escalation_days` | 3 | Days before a request with no other manager goes to the admin |
| `rate_limit` | 5 per email, 20 per IP, 15 min | Failed logins before the login is locked |
| `session.idle_timeout` | 1800 | Seconds of inactivity before automatic logout |

---

## 8. Git

`config/config.php` is in `.gitignore`, so your real password never goes to GitHub.
Commit `config.example.php` instead.

```bash
git init
git add .
git commit -m "SmartProcure Lite"
```
=======
# SmartProcure-Lite
SmartProcure Lite is a web-based procurement management system that streamlines purchase requests, vendor management, quotation handling, purchase orders, approval workflows, budget tracking, and audit logging using PHP and MySQL.
>>>>>>> origin/main
