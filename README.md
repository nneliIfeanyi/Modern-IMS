# IMS MVP

Plain PHP + MySQL + Bootstrap inventory management system.

## Stack

- PHP 8.2 (no framework)
- MySQL / MariaDB
- Vanilla JS (ES modules)
- Bootstrap 5

## Features

- Authenticated, role-aware inventory workflow
- Product and category management
- Stock movement engine with negative-stock guards
- Purchase order lifecycle (draft -> sent -> partial/full receive)
- Sales order lifecycle (draft -> confirmed -> partial/full fulfill)
- Dashboard KPIs and trend reporting
- Reports for stock valuation, movement history and recaps, near-expiry stock, and low-stock items, with movement CSV export
- Settings for profile, password, and app constants (site name + theme color)
- SMS activity logs with low-stock and customer payment alerts

## Setup

1. Copy `.env.example` to `.env` and update DB credentials.
2. Create database (default: `ims`).
3. For a fresh deployment, select the database and import `database/fresh_install.sql`.
4. Start app from `public/` as document root.

### Progressive Web App

The admin app can be installed from supported browsers using the **Install** button in the login screen or app header. It requires HTTPS in production; `localhost` is supported for local development. The button opens the native install prompt when available and otherwise shows browser-specific install guidance.

The service worker caches the static app shell for repeat visits and offline loading of previously visited pages. API requests and authenticated API responses are never cached.

The current app icon at `public/assets/img/pwa-icon.svg` is a temporary branded placeholder. Replace it with the supplied app icon and update the `icons` entry in `public/manifest.webmanifest` when the final artwork is ready.

### SMS Notifications

SMS delivery is handled server-side through eBulkSMS. Add these values to `.env` to enable it:

```env
EBULKSMS_API_URL=https://api.ebulksms.com:8080/sendsms
EBULKSMS_USERNAME=your-ebulksms-username
EBULKSMS_API_KEY=your-ebulksms-api-key
EBULKSMS_SENDER_ID=IMS
SMS_LOW_STOCK_RECIPIENTS=2348012345678,2348098765432
SMS_EXPIRY_RECIPIENTS=2348012345678,2348098765432
SMS_PAYMENT_ALERTS=true
```

The top-bar bell displays recent SMS delivery logs. Provider failures are recorded without failing the stock or payment operation. Category CRUD is available under Settings, and categories referenced by products cannot be deleted.

### WhatsApp Purchase-Order Notifications

When a draft purchase order is marked as sent, IMS sends a WhatsApp template message to the selected supplier's phone number. Draft creation alone does not send a message. The supplier number must include its international country code (for example, `+234...`).

Configure these values in `.env`:

```env
WHATSAPP_WEBHOOK_VERIFY_TOKEN=your-random-webhook-verification-token
WHATSAPP_APP_SECRET=your-meta-app-secret
WHATSAPP_ACCESS_TOKEN=your-whatsapp-cloud-api-access-token
WHATSAPP_PHONE_NUMBER_ID=your-whatsapp-phone-number-id
WHATSAPP_GRAPH_API_VERSION=your-supported-version
WHATSAPP_PO_TEMPLATE_NAME=your-approved-template-name
WHATSAPP_PO_TEMPLATE_LANGUAGE=en_US
```

Create and get Meta approval for a WhatsApp utility template with five body parameters in this order: supplier name, purchase-order number, a newline-separated list of product names and ordered quantities, total amount, and expected delivery date. Use this body so the template matches the message sent by IMS:

```text
Hello {{1}}, IMS has sent purchase order {{2}}.
Items and quantities:
{{3}}
Order total: {{4}}. Expected delivery: {{5}}. Please confirm receipt.
```

The item-list parameter includes every purchase-order line as `Product name x quantity`; IMS does not truncate the list. If the rendered message exceeds WhatsApp's template body limit, sending fails and the API response reports that failure. The template name and language in `.env` must match the approved Meta template. The WhatsApp access token must have permission to send messages from the configured phone number.

The purchase-order send action marks the order as ordered even if WhatsApp is unavailable; its API response includes `whatsapp_notification.sent` so the caller can distinguish a successful notification from a failure. Failures are recorded in the server log.

### XAMPP (Windows)

- Apache route: `http://localhost/IMS/public/login.html`
- Or PHP built-in server:

```powershell
Set-Location c:\xampp\htdocs\IMS
C:\xampp\php\php.exe -S 127.0.0.1:8107 -t public
```

Then open `http://127.0.0.1:8107/login.html`.

## Documentation Page

- In-app documentation is available at `documentation.html`.
- It is reachable from:
	- Sidebar (`Documentation`)
	- Settings page (`Open Documentation` button)

## Default Login Credentials

After running the seed files, you can sign in with these defaults (if they have not been changed from Settings/Profile updates):

- Admin
	- Email: `admin@ims.local`
	- Password: `admin12345`
- Staff
	- Email: `staff@ims.local`
	- Password: `admin12345`

For non-local environments, change these credentials immediately.

## Settings Customization

Admin/Manager users can update constants from `Settings`:

- Site name (applies to shell logo text)
- Theme color (applies to primary UI accents)

These values are persisted in the `app_settings` table and loaded across the authenticated UI.

## Reports

The Reports page includes daily, week-to-date, and month-to-date stock movement recaps compared with the previous equivalent periods. Transfer quantities count the source leg once, while movement-entry totals include both warehouse ledger entries. The expiring-stock report lists active batches that are expired or due within the selected 30-, 60-, or 90-day window. Expiry reporting requires the inventory-batch schema and expiry tracking enabled with `EXPIRY_TRACKING_ENABLED=true`; if unset, a non-empty `SMS_EXPIRY_RECIPIENTS` enables tracking.

## API Notes

- All JSON endpoints return `{status, data, message}`.
- Auth is session-based; include credentials on fetch calls.
- Mutating endpoints require authenticated session.
- Reports and Settings endpoints require `admin` or `manager` role.

## Key Routes

- Login: `login.html`
- Dashboard: `dashboard.html`
- Products: `products.html`
- Stock Movements: `stock-movements.html`
- Purchase Orders: `purchase-orders.html`
- Sales: `sales.html`
- Suppliers: `suppliers.html`
- Reports: `reports.html`
- Settings: `settings.html`
- Documentation: `documentation.html`

## Troubleshooting

- If login succeeds but you remain on login, ensure you are using the latest `public/login.html` and clear browser cache.
- If API calls fail on localhost subpaths, confirm rewrite is active and `public/` is the document root path.
- Server-side unexpected errors are logged to `storage/logs/app.log`.

## Rewrite Rules

- Apache rewrite file is in `public/.htaccess`.
- Nginx sample is `nginx.conf.example`.
# Modern-IMS
