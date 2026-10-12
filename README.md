# ANTOBELL IMS MVP

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
- Reports with movement CSV export
- Settings for profile, password, and app constants (site name + theme color)
- SMS activity logs with low-stock and customer payment alerts

## Setup

1. Copy `.env.example` to `.env` and update DB credentials.
2. Create database (default: `ims`).
3. For a fresh deployment, select the database and import `database/fresh_install.sql`.
4. Start app from `public/` as document root.

### SMS Notifications

SMS delivery is handled server-side through eBulkSMS. Add these values to `.env` to enable it:

```env
EBULKSMS_API_URL=https://api.ebulksms.com:8080/sendsms
EBULKSMS_USERNAME=your-ebulksms-username
EBULKSMS_API_KEY=your-ebulksms-api-key
EBULKSMS_SENDER_ID=IMS
SMS_LOW_STOCK_RECIPIENTS=2348012345678,2348098765432
SMS_PAYMENT_ALERTS=true
```

The top-bar bell displays recent SMS delivery logs. Provider failures are recorded without failing the stock or payment operation. Category CRUD is available under Settings, and categories referenced by products cannot be deleted.

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
