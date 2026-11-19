# StockWise – Inventory & Sales Management System

> A complete, production-ready PHP 8+ Point-of-Sale and Inventory Management System built as a final project for **IT412 – Systems Administration and Maintenance**.

---

## ✨ Feature Overview

| Module | Description |
|---|---|
| **Dashboard** | Live KPI cards (revenue, stock, low-stock), 30-day sales chart, top sellers |
| **POS** | Real-time cart, discount, change calculation, receipt printing |
| **Products** | Full CRUD, image upload, SKU auto-generation, category & supplier linking |
| **Categories** | CRUD with product-count protection on delete |
| **Inventory** | Stock-In / Stock-Out / Adjust, full movement history with filters |
| **Sales** | Transaction ledger, receipt viewer, date/cashier filters, role-aware |
| **Suppliers** | CRUD with product association safety check |
| **Reports** | Sales Revenue, Inventory Valuation, Low Stock Audit, Out of Stock, Product Performance, Stock Movement — all with CSV export |
| **Users** | Admin CRUD, role assignment (admin / manager / cashier), activate/deactivate |
| **Audit Log** | Tamper-resistant activity trail with user/action/IP/date filters |
| **Profile** | Self-service profile & password change |
| **Settings** | Admin store configuration |

---

## 🛠 Tech Stack

- **Backend:** Native PHP 8+ (no frameworks)
- **Database:** MariaDB 10.5+ / MySQL 8+ — PDO with prepared statements
- **Frontend:** Bootstrap 5 · Bootstrap Icons · Chart.js
- **Server:** Apache 2.4 with `.htaccess` rewrites
- **Auth:** `password_hash()` / `password_verify()` · Session-based with CSRF tokens
- **Roles:** `admin` · `manager` · `cashier`

---

## 📂 Directory Structure

```
stockwise/
├── ajax/
│   ├── complete_sale.php     # Atomic checkout endpoint (transaction + inventory)
│   ├── get_product.php       # Single product JSON fetch
│   └── search_products.php   # POS product search JSON
├── assets/
│   ├── css/style.css
│   ├── js/app.js
│   └── images/products/      # Uploaded product images (writable)
├── database/
│   ├── stockwise.sql         # Full schema + seed data (categories, products, sales)
│   └── seed_passwords.php    # One-time CLI seeder for default user accounts
├── includes/
│   ├── config.php            # DB connection (PDO), app constants, session config
│   ├── auth.php              # requireLogin(), requireRole(), logAudit()
│   ├── functions.php         # Helpers: formatCurrency(), CSRF, flash messages, pagination
│   ├── helpers.php           # Alias shim (backward compat)
│   ├── header.php            # Sidebar + topbar + flash messages HTML
│   └── footer.php            # Modals, Bootstrap JS, Chart.js, app.js
├── logs/                     # PHP error log (server-writable; excluded from git)
│   └── .gitkeep
├── .gitignore
├── .htaccess                 # Security headers, directory restrictions
├── audit_log.php
├── categories.php
├── dashboard.php
├── index.php                 # Redirect to dashboard/login
├── inventory.php
├── login.php
├── logout.php
├── pos.php
├── products.php
├── profile.php
├── reports.php
├── sales.php
├── settings.php
├── stock_in.php
├── suppliers.php
└── users.php
```

---

## 🚀 Installation Guide (Ubuntu Server – LAMP)

### Prerequisites

```bash
sudo apt update && sudo apt install -y apache2 php8.2 php8.2-mysql php8.2-mbstring \
  php8.2-gd php8.2-xml libapache2-mod-php8.2 mariadb-server
sudo a2enmod rewrite php8.2
sudo systemctl restart apache2
```

### Step 1 – Copy Files

```bash
sudo cp -r stockwise/ /var/www/html/stockwise
sudo chown -R www-data:www-data /var/www/html/stockwise
sudo chmod -R 755 /var/www/html/stockwise
sudo chmod -R 775 /var/www/html/stockwise/assets/images/products
sudo chmod -R 775 /var/www/html/stockwise/logs
```

### Step 2 – Create the Database

```bash
sudo mysql -u root -p
```

```sql
CREATE DATABASE stockwise CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'stockwise_user'@'localhost' IDENTIFIED BY 'YourSecurePassword123!';
GRANT ALL PRIVILEGES ON stockwise.* TO 'stockwise_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Step 3 – Import the Schema & Seed Data

```bash
mysql -u stockwise_user -p stockwise < /var/www/html/stockwise/database/stockwise.sql
```

> ✅ This imports all tables **and** default users (see credentials below).

### Step 4 – Configure Database Connection

Edit `includes/config.php` and update:

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'stockwise');
define('DB_USER', 'stockwise_user');
define('DB_PASS', 'YourSecurePassword123!');
```

### Step 5 – Enable Apache Rewrite (if needed)

In `/etc/apache2/sites-available/000-default.conf`, ensure:

```apache
<Directory /var/www/html>
    AllowOverride All
</Directory>
```

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

### Step 6 – Visit the Application

Open your browser: `http://your-server-ip/stockwise/`

---

## 🔑 Default Credentials

> ⚠️ **Change all passwords immediately after first login.**

| Role | Username | Password |
|---|---|---|
| Administrator | `admin` | `Admin@1234!` |
| Manager | `manager` | `Manager@1234!` |
| Cashier | `cashier` | `Cashier@1234!` |

---

## 🔐 Role Permissions Matrix

| Feature | Admin | Manager | Cashier |
|---|:---:|:---:|:---:|
| Dashboard | ✅ | ✅ | ✅ |
| POS / New Sale | ✅ | ✅ | ✅ |
| View All Sales | ✅ | ✅ | Own only |
| Products CRUD | ✅ | ✅ | View only |
| Categories CRUD | ✅ | ✅ | — |
| Inventory Management | ✅ | ✅ | — |
| Supplier Management | ✅ | ✅ | — |
| Reports & Analytics | ✅ | ✅ | — |
| User Management | ✅ | — | — |
| Audit Log | ✅ | — | — |
| Settings | ✅ | — | — |

---

## 🗄 Database Schema

| Table | Purpose |
|---|---|
| `users` | Authentication, roles, active/inactive |
| `categories` | Product classification |
| `suppliers` | Vendor contacts |
| `products` | SKU, pricing, stock levels, images |
| `inventory_movements` | Every stock change with reason + user |
| `sales` | Transaction header (receipt, totals, cashier) |
| `sale_items` | Line items per sale |
| `audit_logs` | Security trail for all system actions |

---

## 🧰 Optional: Seed Passwords Manually (CLI)

If you prefer to hash passwords at runtime instead of using the SQL file:

```bash
cd /var/www/html/stockwise
php database/seed_passwords.php
```

Then **delete** the script:
```bash
rm database/seed_passwords.php
```

---

## 📋 License

Academic project — IT412 Systems Administration and Maintenance.  
Not licensed for commercial redistribution.
