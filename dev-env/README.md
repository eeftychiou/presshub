# PressHub Local WordPress Development Environment

Self-contained local WordPress environment powered by SQLite. Zero external database services or Docker containers required.

---

## ⚡ Quick Start

### 1. Provision / Setup
```bash
php dev-env/scripts/setup.php
```

### 2. Start Web Server
```bash
php dev-env/scripts/server.php
```
- **Front-end**: `http://127.0.0.1:8888`
- **WP Admin**: `http://127.0.0.1:8888/wp-admin/` (`admin` / `password123`)

---

## 🪵 Debugging & Logs

```bash
# Unified log monitor
php dev-env/scripts/tail-logs.php --follow
php dev-env/scripts/tail-logs.php --level=ERROR
php dev-env/scripts/tail-logs.php --source=presshub
```

---

## 🗄️ Database & Token Logs

```bash
# Inspect token logs and usage
php dev-env/scripts/view-token-logs.php
php dev-env/scripts/view-token-logs.php --stats
php dev-env/scripts/view-token-logs.php --detail=1

# Execute SQL queries
php dev-env/scripts/query-db.php "SELECT * FROM wp_options WHERE option_name LIKE 'presshub%' LIMIT 5"
php dev-env/scripts/query-db.php --tables

# Reset / reseed database
php dev-env/scripts/reset-db.php
```

---

## 🧪 Integration Tests

```bash
php dev-env/scripts/run-integration-tests.php
```
