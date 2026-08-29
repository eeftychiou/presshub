# PressHub Local WordPress Development & Testing Environment

A self-contained, high-performance local WordPress development environment tailored for `presshub-ai-editor` and companion workflows. Powered by the official WordPress Core SQLite database engine on PHP 8.3 with zero external database services required.

---

## Quick Reference

| Service / Resource | Location / Command | Credentials / Details |
| :--- | :--- | :--- |
| **Site URL** | `http://127.0.0.1:8888` | Front-end |
| **Admin Dashboard** | `http://127.0.0.1:8888/wp-admin/` | User: `admin` / Pass: `password123` |
| **SQLite Database** | `dev-env/wordpress/wp-content/database/.ht.sqlite` | SQLite3 / PDO |
| **Core Debug Log** | `dev-env/wordpress/wp-content/debug.log` | `WP_DEBUG_LOG` |
| **PressHub Debug Log** | `dev-env/wordpress/wp-content/uploads/presshub-ai/presshub-debug.log` | Structured JSON log |
| **Token & Activity DB**| `wp_presshub_ai_token_logs` table | In SQLite DB |

---

## Quick Start Commands

### 1. Launch Dev Web Server
Starts the built-in PHP web server on `http://127.0.0.1:8888`:
```bash
php dev-env/scripts/server.php
```

### 2. Stream Live Debug Logs
Streams both WordPress `debug.log` and PressHub `presshub-debug.log` in real time:
```bash
# View recent 50 lines
php dev-env/scripts/tail-logs.php

# Stream live (follow)
php dev-env/scripts/tail-logs.php --follow

# Filter by level (DEBUG, INFO, WARNING, ERROR)
php dev-env/scripts/tail-logs.php --level=ERROR

# Filter by source (wp or presshub)
php dev-env/scripts/tail-logs.php --source=presshub
```

### 3. Inspect Token & Activity Database Logs
Inspect LLM token usage, latencies, model requests, errors, and metadata in `wp_presshub_ai_token_logs`:
```bash
# View recent 25 requests
php dev-env/scripts/view-token-logs.php

# View usage statistics & breakdowns
php dev-env/scripts/view-token-logs.php --stats

# Filter by provider or status
php dev-env/scripts/view-token-logs.php --provider=openai
php dev-env/scripts/view-token-logs.php --status=error

# View full record details with JSON metadata
php dev-env/scripts/view-token-logs.php --detail=1
```

### 4. Execute SQL Queries & Table Inspection
Directly query the SQLite database:
```bash
# List all tables and row counts
php dev-env/scripts/query-db.php --tables

# Inspect table schema
php dev-env/scripts/query-db.php --schema wp_presshub_ai_token_logs

# Execute raw SQL query
php dev-env/scripts/query-db.php "SELECT * FROM wp_options WHERE option_name LIKE 'presshub%' LIMIT 5"
```

### 5. WP-CLI
Run WP-CLI commands against the local WordPress installation:
```bash
# Via wrapper batch file
dev-env\bin\wp plugin list
dev-env\bin\wp user list
dev-env\bin\wp eval "echo get_option('presshub_ai_log_level');"

# Or directly via PHP
php dev-env/bin/wp-cli.phar --path=dev-env/wordpress plugin list
```

### 6. Run Live Integration & Unit Tests
```bash
# Run live WordPress integration test suite
php dev-env/scripts/run-integration-tests.php

# Run full unit test suite (46 test files)
php presshub-ai-editor/tests/run-all-tests.php
```

### 7. Reset & Reseed Database
Instantly wipe and restore a clean WordPress installation with admin user and sample post:
```bash
php dev-env/scripts/reset-db.php
```

### 8. GitHub Issue Tracking (`gh`)
Track identified bugs and fixes on GitHub:
```bash
# File a new issue for a bug found
gh issue create --title "bug: Description" --body "Steps, logs, and details"

# List open issues
gh issue list

# Close issue after verification in local dev-env
gh issue close <id> --comment "Verified in local dev-env"
```

---

## Directory Structure

```
Presshub/
├── dev-env/
│   ├── bin/
│   │   ├── wp-cli.phar             # WP-CLI executable
│   │   └── wp.bat                  # WP-CLI Windows batch runner
│   ├── scripts/
│   │   ├── setup.php               # Idempotent environment setup script
│   │   ├── server.php              # Dev server runner
│   │   ├── router.php              # PHP server router for WP rewrites/REST/AJAX
│   │   ├── tail-logs.php           # Log viewer and live streamer
│   │   ├── query-db.php            # Direct SQL query CLI tool
│   │   ├── view-token-logs.php     # Token & activity log inspector
│   │   ├── run-integration-tests.php # Live WordPress integration tests
│   │   └── reset-db.php            # Database reset/reseed utility
│   ├── wordpress/                  # Local WordPress instance
│   │   ├── wp-config.php           # Config with WP_DEBUG, WP_DEBUG_LOG, SAVEQUERIES
│   │   ├── wp-content/
│   │   │   ├── db.php              # SQLite DB drop-in
│   │   │   ├── database/           # SQLite DB (.ht.sqlite)
│   │   │   ├── debug.log           # Core debug log
│   │   │   └── plugins/
│   │   │       └── presshub-ai-editor -> [Junction to /presshub-ai-editor]
│   └── README.md
├── presshub-ai-editor/             # Plugin source code
└── presshub-workflow/              # Workflow Node/MCP components
```
