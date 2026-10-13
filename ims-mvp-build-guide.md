# IMS MVP — Phased Build Guide

**Stack:** PHP 8.2+ (no framework, PDO + MySQL) · Vanilla JS (ES6, fetch API) · Bootstrap 5
**Goal:** Working MVP matching the dashboard, stock movements, and purchase order wireframes already designed.

Use each phase as a prompt, Do them in order — each phase depends on the last. Test and commit after every phase before moving on.

---

## Phase 0 — Project scaffolding

**Prompt:**
> Set up a plain PHP 8.2 project with no framework. Structure:
> ```
> /public          → index.php (front controller), assets (css/js)
> /src
>   /Core          → Router, Database (PDO singleton), Request, Response, Env loader
>   /Controllers
>   /Models
>   /Middleware    → Auth, Cors
> /config          → config.php (reads .env)
> /database
>   /fresh_install.sql
> .env.example
> composer.json    → PSR-4 autoload only, no packages required yet
> ```
> Add a `.env` loader (no vendor package — parse manually), a PDO connection class using prepared statements only, and a minimal front-controller router that maps `METHOD /api/xxx` to a controller method. Return all API responses as JSON via a `Response` helper (`success()`, `error()`, consistent shape: `{ "status": "ok|error", "data": ..., "message": ... }`).

**Deliverables:** project boots, `/api/ping` returns JSON, DB connects.

---

## Phase 1 — Database schema

**Prompt:**
> Write the complete MySQL setup SQL for this schema: `users`, `roles`, `categories`, `products`, `warehouses`, `stock`, `suppliers`, `purchase_orders`, `purchase_order_items`, `sales_orders`, `sales_order_items`, `stock_movements`, `sms_logs`. Use InnoDB, foreign keys with `ON DELETE RESTRICT` on referenced masters, `unsigned` ints for IDs, `DECIMAL(12,2)` for money, and a composite unique key on `stock (product_id, warehouse_id)`. Include initial data with 2 roles (admin, staff), 1 warehouse, and 5 sample products.

**Deliverables:** the complete database setup runs cleanly against MySQL and loads initial data.

**Key rule to bake in now:** `stock.quantity` is never written directly from application code except inside the `stock_movements` insert transaction — enforce this at the service layer in Phase 5, not the DB.

---

## Phase 2 — Auth & roles

**Prompt:**
> Implement session-based auth (PHP native sessions, not JWT, for MVP simplicity) with `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`. Passwords hashed with `password_hash()`/`password_verify()`. Add an `AuthMiddleware` that checks session on protected routes and a `role:admin` style middleware for role-gated routes (e.g. Suppliers, Reports = admin/manager only). Return 401/403 JSON errors, never redirect.

**Deliverables:** login works, protected route rejects unauthenticated requests, role check rejects wrong role.

**Frontend companion:** simple `login.html` + Bootstrap form, `auth.js` module wrapping `fetch()` with credentials included.

---

## Phase 3 — Frontend shell (matches wireframes)

**Prompt:**
> Build the Bootstrap 5 app shell used by every screen: fixed topbar (logo, search, SMS activity, avatar), left sidebar nav (Dashboard, Products, Stock movements, Purchase orders, Sales, Suppliers, Reports, Settings) with active-state highlighting, and a `<main>` content area. Use CSS Grid/Flex, not a JS framework — plain `layout.html` partial injected via `fetch()` + `innerHTML`, or a lightweight `include.js` that loads `topbar.html`/`sidebar.html` into placeholders on every page. Mobile: sidebar collapses into an offcanvas (Bootstrap `Offcanvas` component) below 768px.

**Deliverables:** shell renders identically to the wireframe structure on desktop and collapses correctly on mobile.

---

## Phase 4 — Products & Categories module

**Prompt:**
> Build REST endpoints: `GET/POST /api/products`, `GET/PUT/DELETE /api/products/{id}`, `GET/POST /api/categories`. Products list supports `?search=&category_id=&page=`. Use PDO prepared statements, validate input server-side (required fields, numeric checks), return 422 with field errors on failure.
> Frontend: `products.html` + `products.js` — Bootstrap table, search box, category filter dropdown, "+ Add product" modal (Bootstrap modal) with a form, client-side validation before submit, toast on save (Bootstrap Toast).

**Deliverables:** full CRUD working end-to-end through the UI.

---

## Phase 5 — Stock & movements (core engine)

**Prompt:**
> Build the stock movement engine — this is the most important service. `POST /api/stock-movements` accepts `{ product_id, warehouse_id, type: in|out|adjustment|transfer, quantity, reference }`. Inside a DB transaction: insert into `stock_movements`, then update (or insert if missing) the matching `stock` row's quantity — increment for `in`, decrement for `out`/transfer-source, insert a second row for transfer-destination. Roll back entirely on any failure. Reject `out`/transfer if it would take quantity below 0 (return 409 with current quantity). `GET /api/stock-movements` supports filters matching the wireframe: type, warehouse, date range, product/SKU search, paginated.
> Frontend: `stock-movements.html` matching the wireframe — filter chips, table, and the "Record movement" side drawer (Bootstrap Offcanvas) with the 4-way type toggle (button group), product typeahead (simple fetch-as-you-type against `/api/products?search=`), warehouse select, quantity input.

**Deliverables:** recording a movement updates stock correctly and shows immediately in the log; negative-stock guard works.

---

## Phase 6 — Purchase Orders module

**Prompt:**
> Build `purchase_orders` + `purchase_order_items` CRUD: `POST /api/purchase-orders` (draft, with line items in one payload), `PUT /api/purchase-orders/{id}` (only while status = draft), `POST /api/purchase-orders/{id}/send` (draft → ordered), `POST /api/purchase-orders/{id}/receive` (accepts partial or full quantities per line item; for each line, creates a `stock_movements` row of type `in` via the Phase 5 service — do not duplicate that logic — then updates `qty_received`; sets PO status to `partially_received` or `received` based on whether all lines are fully received).
> Frontend: `purchase-orders.html` (list, matching wireframe: status filter chips, supplier filter, table) and `purchase-order-detail.html` (order details panel, editable item table while draft, status timeline, "Receive stock" button opening a modal pre-filled with remaining quantities per line).

**Deliverables:** create PO → send → receive (partial then full) → status transitions correctly, stock updates confirmed in Stock movements screen.

---

## Phase 7 — Suppliers & Warehouses (supporting modules)

**Prompt:**
> Build simple CRUD for `suppliers` (name, contact, email, phone, address) and `warehouses` (name, location). These are referenced by Purchase Orders and Stock respectively — reuse the same table/modal/toast pattern from Phase 4 for consistency. No wireframe was drawn for these; keep them minimal Bootstrap tables with an "Add" modal.

**Deliverables:** both modules CRUD correctly and populate the dropdowns used in Phases 5–6.

---

## Phase 8 — Dashboard

**Prompt:**
> Build `GET /api/dashboard/summary` returning: total SKU count, total stock value (Σ quantity × cost_price), low-stock count (quantity ≤ reorder_level), pending order counts (draft/ordered POs + open sales orders). Build `GET /api/dashboard/movements-trend?days=30` returning daily in/out totals for a chart. Build `GET /api/dashboard/low-stock` returning items at/under reorder level.
> Frontend: `dashboard.html` matching the wireframe — 4 KPI cards, a line chart (use Chart.js via CDN, not a framework) for the movement trend, a low-stock alert list, and a recent-movements table (reuse the Phase 5 API with `limit=5`).

**Deliverables:** dashboard loads live data, matches wireframe layout, low-stock items link to the product.

---

## Phase 9 — Sales / stock-out (mirrors PO module)

**Prompt:**
> Build `sales_orders` + `sales_order_items` following the exact same pattern as Phase 6 (draft → confirmed → fulfilled), except fulfilling creates `stock_movements` of type `out` and rejects fulfillment if any line would take stock negative. Frontend mirrors the PO list/detail screens.

**Deliverables:** full sales flow working, stock decrements correctly, negative-stock guard respected.

---

## Phase 10 — Reports & polish

**Prompt:**
> Add `GET /api/reports/stock-valuation`, `GET /api/reports/movement-history` (CSV export via `Content-Type: text/csv` streamed response, no library), and `GET /api/reports/low-stock`. Add global error handling (uncaught exceptions → JSON 500, logged to a file, never expose stack traces in the response). Add request-level input sanitization helper used across all controllers. Add a `Settings` page for basic profile/password change.
> Final pass: confirm every mutating endpoint (`POST`/`PUT`/`DELETE`) requires auth, confirm role checks are enforced on Suppliers/Reports/Settings, add `.htaccess`/`nginx` rewrite rules so `/api/*` routes to `index.php`, and add a basic `README.md` with setup instructions.

**Deliverables:** MVP complete — all wireframe screens live and wired to real data, exportable reports, no unguarded endpoints.

---

## Cross-cutting rules to repeat in every phase prompt

- **PDO only, prepared statements only** — never string-concatenate SQL.
- **No client-side frameworks** — Bootstrap 5 components + vanilla ES6 modules (`type="module"`, `fetch`, no jQuery).
- **stock_movements is the single source of truth** for any quantity change — Products, POs, and Sales Orders all call into it, never touch `stock.quantity` directly.
- **Consistent API envelope** — every endpoint returns `{status, data, message}` so `api.js` (one shared fetch wrapper) can handle all requests/errors identically.
- **Mobile-first on the transactional screens** (stock movement recording, receiving) since staff will likely use these from phones on the floor.

## Suggested order to actually build (condensed)

0 → 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10
