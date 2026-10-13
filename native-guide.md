Implementation Breakdown

UI architecture baseline from NiceAdmin
Reuse NiceAdmin layout primitives: fixed header, sidebar, content area, responsive collapse behavior.
Keep only needed vendor assets (Bootstrap, Bootstrap Icons, Chart.js); avoid extra template-heavy JS features not used by IMS.
Build one shared app shell (topbar + sidebar) and load all screens inside it so every module stays consistent with wireframes.
#2
Backend skeleton exactly as the guide prescribes
Build front-controller PHP app with Router, Request, Response, Database (PDO), Env loader.
Enforce one JSON response envelope across all endpoints.
Add one complete database setup script early so deployment stays straightforward.
#3
Data model and stock invariants first
Implement the complete schema and seed data before business modules.
Lock in the stock rule: quantity changes only via stock movement transactions.
Add service-level guards for negative stock and transfer integrity.
#4
Authentication and authorization foundation
Session auth endpoints and middleware.
Role gates for admin-sensitive modules (Suppliers, Reports, Settings areas requiring privilege).
Frontend auth wrapper with credentials-enabled fetch.
#5
Build modules in guide order, but with strict integration checkpoints
Products/Categories CRUD.
Stock movements engine and UI drawer.
Purchase Orders create/send/receive using stock service (no duplicated stock logic).
Suppliers/Warehouses support module.
Dashboard with KPIs and trend chart.
Sales flow mirroring PO lifecycle.
Reports + settings + final hardening.
#6
Frontend composition strategy
One shared api module, one shared ui-utils module (toasts, modal helpers, validation rendering).
Page-specific modules per screen (products, stock, po, dashboard, reports, settings).
Wireframe parity first, visual polish second.
#7
Validation and QA gates per phase
After each phase: endpoint smoke tests + UI interaction checks against wireframe behavior.
Commit only when phase deliverables are met.
Special QA scenarios: partial PO receipts, negative-stock rejection, transfer dual-entry correctness, CSV exports.
#8
Final production-hardening pass
Global exception handling and safe error responses.
Route protection audit for all mutating endpoints.
Rewrite rules and deployment docs for XAMPP/Nginx.
Short operator README for your team.
#9
What I will add beyond the guide (small but important)

Currency standardization to NGN formatting across all money outputs.
A lightweight migration command script so setup is one command.
A short acceptance checklist per module to prevent hidden regressions.