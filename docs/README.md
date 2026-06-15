# Restaurant SaaS API Documentation

Full API reference and Postman collection for the Restaurant SaaS backend.

## Files

| File | Description |
|------|-------------|
| [API.md](./API.md) | Complete API reference (149 endpoints) with Arabic JSON examples |
| [postman/Restaurant-SaaS-API.postman_collection.json](./postman/Restaurant-SaaS-API.postman_collection.json) | Postman Collection v2.1 |
| [postman/Restaurant-SaaS-Environment.postman_environment.json](./postman/Restaurant-SaaS-Environment.postman_environment.json) | Environment variables |

## Import into Postman

1. Open Postman → **Import**
2. Select both files under `docs/postman/`
3. Choose environment **Restaurant SaaS — Local**
4. Set `base_url` to `http://localhost:8000/api/v1`
5. Run **`00 · Quick Start Flows`** top to bottom — each subfolder saves tokens and IDs for the next step

## Postman folder structure (frontend order)

| Folder | When to use |
|--------|-------------|
| **00 · Quick Start Flows** | End-to-end scenarios — run subfolders 01→08 in order |
| **01 · Onboarding & Auth** | Register, login, PIN device login, kitchen secret login |
| **02 · Tenant Setup** | Settings, branches, staff, shifts, subscription, audit log, ETA |
| **03 · Menu & Floor Setup** | Categories, items, tables |
| **04 · Daily Operations (POS)** | Orders, kitchen, payments, print, invoices |
| **05 · Reports** | Daily sales, cash summary, branch comparison |
| **06 · Delivery & QR** | Customers, riders, public QR menu |
| **07 · Inventory** | Ingredients, movements, stock counts, recipes, suppliers, POs |
| **08 · Intelligence** | Loyalty, marketing, analytics, AI summary |
| **09 · Platform Admin** | Super-admin tenant management |
| **10 · Webhooks (Inbound)** | WhatsApp, Paymob, Fawry, aggregators |

## Environment variables

| Variable | Purpose |
|----------|---------|
| `base_url` | API root (`http://localhost:8000/api/v1`) |
| `tenant_subdomain` | Tenant slug — send as `X-Tenant-Subdomain` header on all tenant routes |
| `tenant_token` | Sanctum Bearer token (auto-set after register/login) |
| `kitchen_device_secret` | Kitchen display secret (auto-set after onboarding; shown once) |
| `admin_token` | Platform admin token |
| `branch_id`, `order_id`, `menu_item_id`, … | Resource IDs (auto-set by test scripts) |

## Regenerate after API changes

```bash
php tools/generate-api-documentation.php
```

The generator reads all `api/v1/*` routes and Form Request validation rules automatically.

## Interactive docs (Scribe)

When the app is running, Scribe HTML docs are also available at `/docs`.
