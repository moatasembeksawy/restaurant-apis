# Restaurant SaaS API Documentation

Full API reference and Postman collection for the Restaurant SaaS backend.

## Files

| File | Description |
|------|-------------|
| [API.md](./API.md) | Complete generated API reference with Arabic JSON examples |
| [postman/Restaurant-SaaS-API.postman_collection.json](./postman/Restaurant-SaaS-API.postman_collection.json) | Postman Collection v2.1 |
| [postman/Restaurant-SaaS-Environment.postman_environment.json](./postman/Restaurant-SaaS-Environment.postman_environment.json) | Environment variables |

## Import into Postman

1. Open Postman → **Import**
2. Select both files under `docs/postman/`
3. Choose environment **Restaurant SaaS — Local**
4. Set `base_url` to `http://localhost:8000/api/v1`
5. Run **`00 · Quick Start Flows`** top to bottom — each subfolder saves tokens and IDs for the next step
6. Run **`05 · Offline POS (snapshot → sync → retry)`** twice unchanged to verify that the second upload is reported as a duplicate
7. For kitchen-without-internet, run **`05b · LAN hub (POS → kitchen → cloud sync)`** against a mini-PC with `POS_HUB_ENABLED=true`

## Postman folder structure (frontend order)

| Folder | When to use |
|--------|-------------|
| **00 · Quick Start Flows** | End-to-end scenarios, including the transfer request → send → receive cycle |
| **01 · Onboarding & Auth** | Register, login, PIN device login, kitchen secret login |
| **02 · Tenant Setup** | Settings, branches, staff, shifts, drawer movements, subscription, audit log, ETA |
| **03 · Menu & Floor Setup** | Categories, items, tables, printers, optional stations and routing |
| **04 · Daily Operations (POS)** | Business days, orders, offline POS synchronization, kitchen, payments, routed print jobs, invoices |
| **05 · Finance & Reports** | Expense categories, expense approval, expense summary, sales and branch reports |
| **06 · Delivery & QR** | Customers, riders, public QR menu |
| **07 · Inventory** | Ingredients, movements, stock counts, recipes, suppliers, POs, and transfer lifecycle |
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
| `shift_id`, `cash_movement_id` | Active shift and drawer movement IDs |
| `expense_category_id`, `expense_id` | Expense workflow IDs |
| `printer_id`, `station_id` | Printer and optional kitchen station IDs |
| `branch_id`, `order_id`, `menu_item_id`, … | Resource IDs (auto-set by test scripts) |
| `source_branch_id`, `destination_branch_id` | Branches participating in a stock transfer |
| `stock_transfer_id` | Transfer ID auto-set after creating a request |
| `purchase_order_id`, `purchase_order_item_id` | Purchase order and first line IDs auto-set by the Postman flow |
| `offline_device_id` | Stable UUID identifying the POS device |
| `offline_operation_id`, `offline_client_order_id`, `offline_client_item_id` | Stable idempotency UUIDs; keep them unchanged when retrying |
| `hub_base_url` | LAN hub root (`http://pos-hub.local:7744/api/v1`) |
| `offline_hub_token` | `X-Hub-Token` from `POST /offline/hubs` (shown once) |
| `business_day_id` | Open trading day ID auto-set after `POST /business-days/open` |

## Business day cycle

1. Open the trading day with `POST /business-days/open`. Ingredient stock is snapshotted and daily order numbers start at `1`.
2. Staff clock in; each shift attaches to the open day. Placing an order also opens a day automatically if none exists.
3. Orders on that day receive `business_day_order_number` / `daily_number` (`1, 2, 3…`).
4. Inspect leftover ingredients with `GET /business-days/{id}/remaining-stock`.
5. Record spoilage with `POST /business-days/{id}/waste`.
6. Return leftover stock to the warehouse/HQ branch with `POST /business-days/{id}/return-stock` (`return_remaining: true` sends everything left).
7. Clock out every shift, then close with `POST /business-days/{id}/close`. The next open day starts order numbering at `1` again.
8. `GET /business-days/{id}/report` returns live totals while open and the frozen summary after close (payments by method, sold items, shifts, remaining/waste/returned stock).

Only one business day can be open per branch. Closing is blocked while shifts or unpaid/unfinished orders still exist.

## Offline POS

1. Download `GET /offline/bootstrap?branch_id={id}` while connected and store `cursor`.
2. Persist menu, packages, offers, tables, districts, staff, printers, open orders, shift, and business day locally.
3. Queue operations locally with stable device, operation, order, item, and payment UUIDs.
4. Upload up to 100 operations with `POST /offline/sync`.
5. Remove `applied` and `duplicate` operations from the outbox. Show `rejected` operations for correction.
6. Pull later updates with `GET /offline/changes?branch_id={id}&cursor={cursor}`.

Supported operations: `order.created`, `order.item_added`, `order.item_updated`, `order.item_removed`, `order.status_changed`, `order.item_ready`, and cash `payment.recorded`. Card payments and refunds are not offline. PIN device tokens last 7 days.

Customers are not part of the snapshot. For offline delivery, collect `customer_phone`, `customer_name`, `delivery_address`, and optional `district_id` on `order.created`. Sync finds or creates the customer by phone. Address-only delivery is allowed (no customer record).

## Kitchen / POS on LAN

1. Register the branch hub with `POST /offline/hubs` while online. Store the returned token as `POS_HUB_TOKEN` on the branch mini-PC; it is shown only once.
2. Start the hub with `php artisan pos:hub` (`http://0.0.0.0:7744`). Tablets discover it via `GET /hub/health` and `bootstrap.lan_hub.urls`.
3. POS publishes orders to `POST /hub/events`. Kitchen polls `GET /hub/kitchen/queue` and `GET /hub/events?after={id}`, then marks lines done with `PATCH /hub/kitchen/items/{clientItemId}/done`.
4. Show staff `local_number` / `daily_number`, never client UUIDs.
5. When internet returns, `POST /hub/cloud-sync` or `php artisan pos:hub:sync` uploads the same operations to `POST /offline/sync`.

Hub routes are `/api/v1/hub/*`. They do not use Sanctum or tenant middleware. Send `X-Hub-Token`.

## Delivery dispatch

1. Load pending orders with `GET /deliveries/unassigned`; use `branch_id` when dispatching one branch.
2. Assign selected orders with `POST /deliveries/bulk-assign`, passing one `rider_id` and up to 100 unique `order_ids`.
3. Load `GET /riders/order-summary` to show every active rider, their assigned orders, and outstanding unpaid order count and amount.

Bulk assignment is atomic: the rider and orders must belong to the same branch, and no order is assigned if any selected order is unavailable. In the rider summary, unpaid means the order status is neither `paid` nor `refunded`; cancelled orders are excluded.

## Ingredient transfer cycle (issue voucher)

The warehouse is a branch. The restaurant asks that branch (or another restaurant) for ingredients.

1. Restaurant creates `POST /inventory/transfers` → `pending`. Stock is unchanged.
2. Warehouse prepares with `POST /inventory/transfers/{stockTransfer}/prepare` → `preparing`. Stock is unchanged.
3. Warehouse dispatches with `POST /inventory/transfers/{stockTransfer}/send` → `in_transit`. Warehouse stock is deducted.
4. Restaurant accepts with `POST /inventory/transfers/{stockTransfer}/receive` → `completed`. Restaurant stock is increased.
5. Either side may cancel while `pending` or `preparing`. After dispatch, only the restaurant can reject; sent quantities return to the warehouse.

Send and receive may omit `items` to use the full previous-step quantities, or send lower quantities for partial fulfillment.

## Purchase order cycle

1. Create the needed-items list with `POST /inventory/purchase-orders`; status is `draft`.
2. Print it with `GET /inventory/purchase-orders/{purchaseOrder}/print` when a thermal purchase list is needed.
3. Submit it with `POST /inventory/purchase-orders/{purchaseOrder}/submit`; status becomes `ordered`.
4. Check the delivered goods with `POST /inventory/purchase-orders/{purchaseOrder}/receive`, providing every line's actual quantity and unit cost. Status becomes `received`, but stock is unchanged.
5. Complete it with `POST /inventory/purchase-orders/{purchaseOrder}/complete`; verified quantities are added to stock and status becomes `completed`.

## Item cost and overhead

Recipe cost stays the food cost. Rent, water, electricity, and similar bills are operating expenses, then shared across plates that were actually sold.

1. Create or update an expense category with `allocates_to_item_cost: true` (`PATCH /expense-categories/{id}`).
2. Record and approve the bills as usual. Pending and voided expenses are ignored.
3. `GET /expenses/item-cost?branch_id={id}&start_date=2026-10-01&end_date=2026-10-31` divides that approved total by the quantity of paid items sold in the same period. Each sold unit gets the same share. The default period is the current calendar month.
4. `GET /menu/items/{item}/cost` returns `food_cost` (the recipe), `overhead_cost`, `full_cost`, and `true_margin`. `cost_price` and `total_cost` remain the food cost only.
5. `GET /reports/top-items` adds the same overhead to each row, plus an `item_cost` summary for the report period.

If nothing was sold in the period, `overhead_per_unit` is `0` and the expense pool is still reported so it can be seen as unallocated.

## Regenerate after API changes

```bash
php tools/generate-api-documentation.php
```

The generator reads all `api/v1/*` routes and Form Request validation rules automatically.

## Interactive docs (Scribe)

When the app is running, Scribe HTML docs are also available at `/docs`.
