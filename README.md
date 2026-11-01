# 🔧 Marina's Hardware — POS & Inventory Management System

Green & white POS + inventory system for a hardware store.
Cashier checkout with thermal receipts, live stock, low-stock notifications,
approval workflow, sales reports (daily/weekly/monthly/overall), audit trail.

---

## 🚀 Deploying online (cPanel / shared hosting)

### 1. Requirements
- PHP **8.1+** (check your hosting's PHP version in cPanel)
- MySQL 5.7+ / MariaDB
- Apache with `mod_rewrite` not required — plain PHP files

### 2. Upload
Upload the whole `Pos_inventory` folder into `public_html/`
(so it becomes `public_html/Pos_inventory`).

### 3. Database
1. cPanel → **MySQL Databases** → create a database (e.g. `user_posinventory`)
   and a DB user with password. Add the user to the database with ALL PRIVILEGES.
2. Open **phpMyAdmin** → select the database → Import → choose `posdatabase.sql`.
   > ⚠️ `posdatabase.sql` DROPS existing tables before importing.
   > Only import on a FRESH install — importing again erases all data.

### 4. Configure `db.php` (the only file you must edit)
```php
define('DB_HOST', 'localhost');        // usually 'localhost' on shared hosting
define('DB_NAME', 'your_db_name');     // e.g. user_posinventory
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('VOID_PASSWORD', 'admin123');   // ← CHANGE: password cashiers need to void a cart item
```

### 5. First login & lock down
| Account | Password | Role |
|---|---|---|
| `superadmin` | `admin123` | Super Admin |
| `admin1` | `admin123` | Admin |
| `cashier1` | `cashier123` | Cashier |

1. Log in as `superadmin`, open 🔑 (user menu) → **Change password** — change it.
2. **Users** page → deactivate accounts you don't need, create real cashier accounts.
3. Change `VOID_PASSWORD` in `db.php`.

### 6. HTTPS
Once your hosting has an SSL certificate (most free ones do — Cloudflare,
Let's Encrypt), the session cookie automatically becomes `secure`.
The system **auto-enforces HTTPS on any real domain** (localhost/XAMPP is exempt):
non-HTTPS requests are 301-redirected to HTTPS and an HSTS header is sent.
You still need an SSL certificate on the host (usually free via cPanel AutoSSL/Let's Encrypt).

Force HTTPS in cPanel or `.htaccess`:
```apache
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
```

### 7. Protected files (safe to leave online)
- `seed_data.php` — wipes & regenerates demo sales. Locked to **super admin + confirmation**.
- `test_approvals_direct.php`, `diagnostic.php` — locked to admin login.
- You may also simply **delete** these three files in production.

---

## 🖥️ Running locally (XAMPP)
1. Copy the folder to `C:\xampp\htdocs\`.
2. Start Apache + MySQL in the XAMPP Control Panel.
3. Import `posdatabase.sql` via phpMyAdmin (`localhost/phpmyadmin`).
4. Open `http://localhost/Pos_inventory/Login.php`.
5. Optional demo sales for the Reports page: `seed_data.php` as super admin.

---

## ✨ Features
- **Cashier POS** — barcode/SKU scan, live available stock (reserves what's in
  the cart), editable quantities, discount, cash/GCash, quick-cash buttons,
  change computation, 80mm official receipt (TIN + VAT breakdown) with
  preview & reprint, shift clock in/out, receipt history per shift.
- **Approvals** — stock-in / price change / damage reports with admin decision;
  requester gets notified.
- **Inventory** — auto SKU generation (MH-<category>-#####), categories,
  reorder levels with low-stock notifications, adjustments with audit.
- **Reports** — daily / weekly / monthly / overall / custom range, KPIs,
  top products, per cashier & per category, CSV export, print view.
- **Users & security** — roles (super_admin/admin/cashier), login throttle,
  server-side void authorization, hardened session cookies, audit trail.

## 📁 Structure
```
Login.php / admin.php / super_admin.php / cashier.php   entry points (Vue 3)
auth.php                  login/logout/register/change password
api_checkout.php          checkout (transaction-safe, row-locked stock)
api_print.php             official receipt (preview=1 skips auto-print)
api_receipts.php          cashier shift receipt history
api_session.php           clock in/out + void authorization
api_inventory_v2.php      products/categories/stock adjustments
api_approvals_v2.php      stock request workflow
api_notifications.php     notification feed
api_users.php             user management (super admin)
api_audit.php             audit trail
reports.php               sales reports + CSV export
db.php                    DB config + helpers ★ edit for hosting
posdatabase.sql           schema + seed data (DROPS tables on import!)
seed_data.php             demo sales generator (super admin only)
```
