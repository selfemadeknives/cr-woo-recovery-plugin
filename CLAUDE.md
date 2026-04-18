# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A WordPress plugin for a UK custom knife maker. It tracks WooCommerce abandoned carts, captures customer details, and allows manual recovery emails to be sent from the WordPress admin. **Emails are manual-only — there is no automatic sending.**

The plugin folder is `cr-woo-recovery/` and the main entry point is `cart-recovery.php`. The slug was deliberately chosen to avoid colliding with a commercial "Cart Recovery" plugin (slug `cart-recovery`) on wordpress.org.

## Deployment

There is no build step. To deploy:
1. Edit files in `cr-woo-recovery/`
2. Run the zip script (PowerShell, from `cart-recovery-plugin/`):

```powershell
Add-Type -AssemblyName System.IO.Compression.FileSystem
$sourceDir = 'C:/Users/megam/.local/claude/cart-recovery-plugin/cr-woo-recovery'
$zipPath   = 'C:/Users/megam/.local/claude/cart-recovery-plugin/cr-woo-recovery.zip'
Remove-Item $zipPath -ErrorAction SilentlyContinue
$zip = [System.IO.Compression.ZipFile]::Open($zipPath, 'Create')
Get-ChildItem -Path $sourceDir -Recurse -File | ForEach-Object {
    $rel   = $_.FullName.Substring($sourceDir.Length + 1).Replace('\', '/')
    $entry = 'cr-woo-recovery/' + $rel
    [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, $_.FullName, $entry) | Out-Null
}
$zip.Dispose()
```

**Critical:** The zip must use forward slashes (`/`) in entry paths. PowerShell's `Compress-Archive` produces backslashes, which break extraction on Linux servers. Always use the script above, never `Compress-Archive`.

To update on the live site: deactivate → delete folder via hosting File Manager (not WP admin delete, which triggers `uninstall.php` and drops all tables) → upload zip via WP Admin → Plugins → Add New → activate.

## Architecture

### Data flow

```
Customer adds to cart
  → CR_Tracker::schedule_snapshot()       (woocommerce_add_to_cart etc.)
  → CR_Tracker::save_cart_snapshot()      (woocommerce_cart_updated)
  → CR_DB::upsert_cart() + replace_cart_items()

Customer reaches checkout, types email
  → assets/checkout.js blur on #billing_email
  → CR_Tracker::ajax_capture_checkout_email()   (AJAX, fires before form submit)

WP-Cron fires (default every 15 min)
  → CR_Cron::run()
  → marks active → abandoned (after threshold, default 60 min)
  → CR_Cron::sync_woo_orders() imports pending/failed WC orders

Admin sends email manually
  → admin.js → cr_send_email AJAX
  → CR_Admin::ajax_send_email()
  → CR_Emailer::send()
  → wp_mail() + CR_DB::log_email()

Order placed / payment complete
  → CR_Tracker::mark_recovered_from_order()
  → CR_DB::mark_recovered_by_email() + update_cart_status('recovered')
```

### Key classes

- **CR_DB** — all database access. Uses `dbDelta()` for schema (non-destructive). `upsert_cart()` skips recovered/ignored carts. `get_carts()` uses `...$params` spread with `$wpdb->prepare()`. Insights are cached via WP transient (`cr_insights_v1`, 1 hour), busted on any cart mutation via `bust_insights_cache()`.
- **CR_Tracker** — WordPress/WooCommerce hooks for live cart capture. `$snapshot_scheduled` flag prevents duplicate DB writes per request.
- **CR_Cron** — WP-Cron job. Marks abandonment and syncs pending/failed WC orders.
- **CR_Emailer** — Builds and sends recovery emails via `wp_mail()`. Interpolates `{{variables}}` from `ALLOWED_VARS` whitelist only. `sanitize_header_value()` strips `\r\n` to prevent header injection.
- **CR_Admin** — All admin menu pages and AJAX handlers. Templates are passed to JS via `wp_localize_script` as `crTemplates` (never via HTML `data-*` attributes).

### Database tables

All prefixed with `$wpdb->prefix` (typically `wp_`):

- `cr_carts` — one row per session/order. `session_key` is unique: `user_{id}`, `guest_{wc_session_id}`, or `order_{order_id}`.
- `cr_cart_items` — line items per cart.
- `cr_email_log` — every send attempt with status and error.
- `cr_email_templates` — editable HTML templates with `{{variable}}` placeholders.

### Security patterns in use

- All AJAX handlers: `check_ajax_referer()` + `current_user_can('manage_woocommerce')`
- Form handlers: `check_admin_referer()` + `wp_die()` on failure
- Email body: `cr_kses_email()` (custom allowlist permitting inline styles for email clients) — not `wp_kses_post()` which strips them
- `from_email` sanitized with `sanitize_email()`, not `sanitize_text_field()`
- Status values: whitelisted against `CR_DB::ALLOWED_STATUSES` before any DB write
- Rate limiting: 5-minute transient per cart prevents duplicate sends (`cr_email_sent_{cart_id}`)
- JS error messages use `.text()` not `.html()` to prevent reflected XSS

### Settings

Stored in `get_option('cr_settings')`. Keys: `shop_name`, `from_name`, `from_email`, `abandonment_threshold` (minutes), `lookback_days`, `cron_interval`.

### Template variables

`{{customer_name}}`, `{{shop_name}}`, `{{cart_total}}`, `{{cart_items_html}}`, `{{cart_items_list}}`, `{{cart_url}}`, `{{shop_url}}`
