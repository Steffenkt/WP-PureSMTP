# WP PureSMTP – Development Plan

**Plugin Name:** WP PureSMTP  
**Repository:** https://github.com/Steffenkt/WP-PureSMTP  
**Version:** 1.0.1  
**License:** GPLv2 or later  

---

## 1. Plugin Overview

WP PureSMTP replaces the WordPress default `wp_mail()` / PHP `mail()` function with a clean, pure SMTP connection. Unlike other SMTP plugins, WP PureSMTP offers **only SMTP** as the sending method — no third-party API services, no bloat.

---

## 2. File & Folder Structure

```
wp-puresmtp/
├── wp-puresmtp.php               # Main plugin file (header, bootstrap)
├── readme.txt                    # WordPress.org readme
├── uninstall.php                 # Cleanup on uninstall
├── assets/
│   ├── css/
│   │   └── admin.css             # Admin page styles
│   └── js/
│       └── admin.js              # Admin page scripts (test mail, toggle)
├── includes/
│   ├── class-puresmtp-mailer.php   # Hooks into wp_mail via PHPMailer
│   ├── class-puresmtp-admin.php    # Admin menu, settings page, sanitization
│   ├── class-puresmtp-options.php  # get/set/delete plugin options
│   ├── class-puresmtp-testmail.php # AJAX handler for test mail
│   ├── class-puresmtp-logger.php   # Email log: write, read, delete entries
│   └── class-puresmtp-queue.php    # Mail queue: rate limiting + retry on SMTP failure
└── languages/
    └── wp-puresmtp.pot           # Translation template
```

---

## 3. Admin Settings Page

### 3.1 Menu Position

- Located under: **Settings → WP PureSMTP**
- Menu slug: `wp-puresmtp`

### 3.2 Tab Structure

The settings page is divided into the following tabs (similar to WP Mail SMTP):

| Tab | Description |
|---|---|
| **General** | Sender info + SMTP configuration (main tab) |
| **Email Log** | Log of all sent/failed emails with status & error details |
| **Queue** | Mail queue: rate limiting + SMTP-failure retry queue |
| **Test Email** | Send a test mail to verify config |
| **Misc** | Debug mode, log retention, uninstall option |

---

## 4. Settings Fields (Tab: General)

### 4.1 Sender Information

| Field | Type | Description |
|---|---|---|
| From Email | text input | The email address used as sender |
| Force From Email | toggle (on/off) | Override From address from all plugins |
| From Name | text input | The display name used as sender |
| Force From Name | toggle (on/off) | Override From name from all plugins |
| Return Path | toggle (on/off) | Set return-path to From address (for bounces) |

### 4.2 Mailer Selection

> **WP PureSMTP supports SMTP only.**  
> No provider selection UI is shown. The mailer is always set to `smtp`.  
> This is the core differentiator from other plugins.

### 4.3 SMTP Configuration

| Field | Type | Options / Notes |
|---|---|---|
| SMTP Host | text input | e.g. `smtp.example.com` |
| Encryption | radio buttons | `None` / `SSL` / `TLS` *(default: TLS)* |
| SMTP Port | number input | Auto-suggested: None=25, SSL=465, TLS=587 |
| Auto TLS | toggle (on/off) | Upgrade to TLS automatically if server supports it |
| Authentication | toggle (on/off) | Enable SMTP auth (username + password) |
| SMTP Username | text input | Shown only when auth is enabled |
| SMTP Password | password input | Stored encrypted in DB; button to remove |

**Port auto-fill behavior (JavaScript):**
- User selects `SSL` → port field auto-fills `465`
- User selects `TLS` → port field auto-fills `587`
- User selects `None` → port field auto-fills `25`

---

## 5. Test Email (Tab: Test Email)

| Field | Type | Description |
|---|---|---|
| To | text input | Recipient email address |
| Subject | text input | Email subject |
| Message | textarea | Email body |
| Send Test | button | Triggers AJAX → PHPMailer → returns success/error |

**Result display:** Inline success/error message with SMTP debug output on failure.

---

## 6. Email Log (Tab: Email Log)

### 6.1 Overview

Every outgoing email (triggered by `wp_mail()`) is logged automatically into a custom database table. The log shows whether the email was delivered successfully or failed — including a detailed error message to pinpoint the problem.

### 6.2 Log Table View

The log is displayed as a sortable table in the admin:

| Column | Description |
|---|---|
| **#** | Log entry ID |
| **Date / Time** | Timestamp of the send attempt |
| **To** | Recipient email address(es) |
| **Subject** | Email subject line |
| **Status** | ✅ Sent / ❌ Failed |
| **Error Message** | Only shown on failure — the exact SMTP/PHPMailer error |
| **Actions** | View details / Delete entry |

### 6.3 Status Indicators

| Status | Color | Meaning |
|---|---|---|
| ✅ **Sent** | Green | Email was accepted by the SMTP server |
| ❌ **Failed** | Red | SMTP server rejected or connection failed |
| ⚠️ **Partial** | Orange | Sent to some recipients, failed for others *(v1.1)* |

### 6.4 Detail View (per log entry)

Clicking "View" on a log entry opens a detail panel showing:

- **Date / Time**
- **From** (address + name)
- **To / CC / BCC**
- **Subject**
- **Status** (Sent / Failed)
- **Error Message** — full PHPMailer error text, e.g.:
  - `SMTP connect() failed` → wrong host or port blocked
  - `535 Authentication failed` → wrong username/password
  - `550 Relay not permitted` → sender address not allowed
  - `Connection timeout` → SMTP host unreachable
- **SMTP Debug Trace** — raw SMTP conversation (only if Debug Mode is ON in Misc)
- **Source Plugin** — which WordPress plugin triggered the email (e.g. WooCommerce, Contact Form 7)

### 6.5 Common Error Messages & Meanings

| Error | Likely Cause | Hint shown in UI |
|---|---|---|
| `SMTP connect() failed` | Wrong host/port, firewall blocks | Check SMTP host and port in General settings |
| `535 Authentication failed` | Wrong username or password | Verify SMTP credentials in General settings |
| `550 No such user here` | Recipient address does not exist | Check the To address |
| `550 Relay access denied` | From address not authorized | Use the exact email address of your SMTP account |
| `Connection timed out` | SMTP host not reachable | Check if your hosting blocks outgoing SMTP ports |
| `STARTTLS failed` | Encryption mismatch | Try switching between SSL/TLS in General settings |

### 6.6 Log Controls

- **Search bar** — filter by To, Subject, or Status
- **Date filter** — show entries from last 7 / 30 / 90 days
- **Bulk delete** — select multiple entries and delete
- **Clear all logs** — single button to wipe the entire log table
- **Auto-cleanup** — configurable retention in Misc tab (e.g. keep logs for 30 days)

### 6.7 `class-puresmtp-logger.php`

**Hooks used:**

```php
// Log every wp_mail() attempt
add_action( 'wp_mail_failed',    [ $this, 'log_failure' ] );
add_filter( 'wp_mail',           [ $this, 'capture_mail_data' ] );  // before send
add_action( 'phpmailer_init',    [ $this, 'capture_debug' ] );       // SMTP trace
```

**Key methods:**

```php
// Called before sending — stores To, Subject, timestamp
public function capture_mail_data( array $args ): array

// Called on wp_mail_failed hook — saves error message + WP_Error details
public function log_failure( WP_Error $error ): void

// Called after successful wp_mail() — marks entry as sent
public function log_success( string $to, string $subject ): void

// Returns log entries with optional filters (status, date range, search)
public function get_entries( array $filters = [] ): array

// Deletes entries older than $days
public function cleanup( int $days ): void
```

**WP_Error data captured on failure:**

```php
$error->get_error_code();     // e.g. 'wp_mail_failed'
$error->get_error_message();  // human-readable reason
$error->get_error_data();     // PHPMailer exception / SMTP response
```

---

## 7. Queue (Tab: Queue)

### 7.1 Overview

The Queue tab controls two independent but related features:

1. **Stop all emails** — immediately pause all outgoing mail
2. **Rate limiting** — throttle how many emails are sent per time interval; excess emails are queued
3. **SMTP retry queue** — if the SMTP connection fails, emails are saved and retried automatically instead of being lost

---

### 7.2 Stop All Emails

| Field | Type | Description |
|---|---|---|
| Stop sending all emails | toggle (on/off) | When ON, **no** email is sent from this WordPress site. All `wp_mail()` calls are silently intercepted and written to the queue instead. |

> **Use case:** Maintenance mode, testing environments, or preventing a plugin from spamming users.

A prominent **warning banner** is shown at the top of the admin when this is active, so it cannot be forgotten accidentally.

---

### 7.3 Rate Limiting

Limit how many emails WordPress sends within a time window. Emails that exceed the limit are queued and sent automatically as soon as the limit allows.

| Field | Type | Description |
|---|---|---|
| Enable Rate Limiting | toggle (on/off) | Activates the rate limit feature |
| Max emails | number input | How many emails to allow per interval |
| Interval | dropdown | Per: **Minute** / **Hour** / **Day** / **Week** / **Month** |

**Examples:**

| Setting | Meaning |
|---|---|
| 100 / Hour | After 100 emails in one hour, further emails go into queue |
| 500 / Day | Maximum 500 emails per day; rest queued for next day |
| 10 / Minute | Slow drip sending; useful for bulk notifications |

**Counter display:** The tab shows a live counter, e.g. `Sent this hour: 47 / 100`.

---

### 7.4 SMTP Retry Queue (Failure Protection)

When an email cannot be sent because the SMTP server is **unreachable or returns an error**, the email is **not lost** — it is saved to the queue table and retried automatically.

| Field | Type | Description |
|---|---|---|
| Enable Retry Queue | toggle (on/off) | Save failed emails and retry instead of losing them |
| Retry interval | dropdown | Retry every: **5 min** / **15 min** / **30 min** / **1 hour** |
| Max retry attempts | number input | Give up after N failed attempts (default: 5) |
| Notify admin on failure | toggle | Send admin an email if an item exhausts all retries |

**Retry flow:**

```
wp_mail() called
    │
    ▼
SMTP connection attempt
    │
    ├─ SUCCESS → Email sent → logged as "sent"
    │
    └─ FAILURE → Email saved to queue (status: "pending")
                    │
                    ▼
              WP-Cron job fires every [interval]
                    │
                    ├─ Retry succeeds → logged as "sent", removed from queue
                    │
                    └─ Retry fails again → attempt_count + 1
                              │
                              └─ attempt_count >= max → status: "failed"
                                        │
                                        └─ (optional) notify admin
```

---

### 7.5 Queue Admin View

The Queue tab shows a simple table of all pending emails waiting to be sent. There is no filter by reason — the Email Log covers status history. The Queue only shows what still needs to go out.

| Column | Description |
|---|---|
| **#** | Queue entry ID |
| **Date added** | When the email was first queued |
| **Next retry** | Scheduled time of next attempt |
| **Attempts** | How many times sending was tried (e.g. `2 / 5`) |
| **To** | Recipient address |
| **Subject** | Email subject |
| **Actions** | ✏️ Edit / ▶️ Retry now / 🗑️ Delete |

**Bulk actions:** Retry selected / Delete selected / Clear all

---

### 7.6 Manual Edit of Queued Email

Each queue entry can be manually edited before sending. Clicking **Edit** opens an inline edit form directly in the queue table row (or a modal):

| Field | Editable | Notes |
|---|---|---|
| **To** | ✅ Yes | Fix a typo or wrong recipient address |
| **CC** | ✅ Yes | Add or remove CC recipients |
| **BCC** | ✅ Yes | Add or remove BCC recipients |
| **Subject** | ✅ Yes | Correct the subject line |
| **Message** | ✅ Yes | Edit the email body (plain text or HTML) |
| **Headers** | ❌ No | Internal — not shown to user |
| **Attachments** | ❌ No | Cannot be changed after queuing |

**Save & Retry button:** Saves the edited data and immediately attempts to send.  
**Save only button:** Saves changes but leaves the email in the queue for the next scheduled retry.

> After a manual edit, `attempt_count` is **not** reset — the edit is logged in the Email Log entry as `manually edited before retry`.

---

### 7.6 `class-puresmtp-queue.php`

**Key methods:**

```php
// Intercepts wp_mail() — decides: send now or enqueue
public function maybe_enqueue( array $mail_data ): bool

// Checks rate limit counter for current interval
public function is_rate_limited(): bool

// Saves an email to the queue table
public function enqueue( array $mail_data, string $reason ): int

// WP-Cron callback: processes pending queue items
public function process_queue(): void

// Retries a single queue item — on success removes it, on failure increments count
public function retry( int $queue_id ): bool

// Returns current send count for the active interval
public function get_send_count(): int

// Increments send counter (called after each successful send)
public function increment_send_count(): void
```

**WP-Cron hook registration:**

```php
// Registered on plugin activation
if ( ! wp_next_scheduled( 'puresmtp_process_queue' ) ) {
    wp_schedule_event( time(), 'puresmtp_retry_interval', 'puresmtp_process_queue' );
}
add_action( 'puresmtp_process_queue', [ $queue, 'process_queue' ] );
```

Custom cron interval registered via `cron_schedules` filter to match the user's chosen retry interval (5 min, 15 min, etc.).

---

## 8. Misc Settings (Tab: Misc)

| Field | Type | Description |
|---|---|---|
| Debug Mode | toggle | Captures full SMTP conversation and saves it in the email log |
| Log Retention | dropdown | Auto-delete log entries after: 7 / 14 / 30 / 90 days / Never |
| Uninstall | toggle | If ON, all plugin data + log table is deleted on uninstall |

---

## 9. Core PHP Classes

### 9.1 `class-puresmtp-mailer.php`

**Hook:** `phpmailer_init`

```php
add_action( 'phpmailer_init', [ $this, 'configure_mailer' ] );

public function configure_mailer( PHPMailer $phpmailer ) {
    $phpmailer->isSMTP();
    $phpmailer->Host       = get_option('puresmtp_host');
    $phpmailer->SMTPAuth   = (bool) get_option('puresmtp_auth');
    $phpmailer->Username   = get_option('puresmtp_username');
    $phpmailer->Password   = puresmtp_decrypt( get_option('puresmtp_password') );
    $phpmailer->SMTPSecure = get_option('puresmtp_encryption'); // 'ssl' or 'tls'
    $phpmailer->Port       = (int) get_option('puresmtp_port');
    // From / FromName
    $phpmailer->From       = get_option('puresmtp_from_email');
    $phpmailer->FromName   = get_option('puresmtp_from_name');
}
```

### 9.2 `class-puresmtp-options.php`

- Wrapper for `get_option()` / `update_option()` / `delete_option()`
- Password encryption using `AUTH_KEY` from `wp-config.php`
- All option keys prefixed with `puresmtp_`

### 9.3 `class-puresmtp-admin.php`

- Registers admin menu under Settings
- Renders settings page with tabs
- Handles `$_POST` save with nonce verification + `sanitize_*` functions
- Enqueues `admin.css` and `admin.js` only on plugin page

### 9.4 `class-puresmtp-testmail.php`

- AJAX action: `wp_ajax_puresmtp_test_mail`
- Calls `wp_mail()` with test data
- Returns JSON: `{ success: true/false, message: "..." }`

### 9.5 `class-puresmtp-logger.php`

- See Section 6.7 for full details
- Creates custom DB table `{prefix}_puresmtp_log` on plugin activation
- Table columns: `id`, `date`, `to`, `subject`, `status`, `error_message`, `debug_trace`, `source_plugin`

### 9.6 `class-puresmtp-queue.php`

- See Section 7.6 for full details
- Creates custom DB table `{prefix}_puresmtp_queue` on plugin activation
- Registers WP-Cron event for retry processing
- Handles both rate-limit queuing and SMTP-failure retry queuing

---

## 10. Security Requirements

- All form fields sanitized on save (`sanitize_email`, `sanitize_text_field`, `absint`)
- Nonce verification on every POST (`wp_nonce_field` / `check_admin_referer`)
- SMTP password encrypted before storing in database
- Password field displays `••••••••` and offers "Remove Password" button
- Capability check: only `manage_options` can access settings
- AJAX handler verifies nonce + capability

---

## 11. WordPress.org Compliance

- `readme.txt` with: Description, Installation, FAQ, Changelog, Screenshots
- Tested up to: latest WordPress version
- Minimum WP version: 5.5
- Minimum PHP version: 7.4
- No external API calls on front-end
- Translations-ready with `.pot` file

---

## 12. Development Milestones

| Milestone | Tasks |
|---|---|
| **v1.0.0 – Core** | Plugin bootstrap, SMTP mailer class, admin settings page (General tab), save/load options, password encryption, basic CSS |
| **v1.0.1 – Email Log** | DB table creation, logger class, log tab UI, status indicators, detail view, error messages with hints |
| **v1.0.2 – Queue** | Queue DB table, rate limiting logic, SMTP retry queue, WP-Cron integration, queue tab UI, kill switch with admin warning banner |
| **v1.0.3 – Test Mail** | Test Email tab, AJAX handler, debug output display |
| **v1.0.4 – Misc & Polish** | Misc tab, log retention/auto-cleanup, debug mode, uninstall cleanup, translations (.pot), readme.txt |

---

## 13. Third-Party Dependencies

| Library | Version | Purpose |
|---|---|---|
| PHPMailer | bundled with WordPress | SMTP sending |
| WordPress Settings API | core | Option saving |
| jQuery | bundled with WordPress | Admin JS (toggle, AJAX test mail) |

> No external Composer packages required for v1.0.

---

## 14. Database

### 14.1 Options (`wp_options` table)

All options stored via `wp_options` table:

| Option Key | Type | Example Value |
|---|---|---|
| `puresmtp_from_email` | string | `hello@example.com` |
| `puresmtp_force_from_email` | bool | `1` |
| `puresmtp_from_name` | string | `My Website` |
| `puresmtp_force_from_name` | bool | `1` |
| `puresmtp_return_path` | bool | `0` |
| `puresmtp_host` | string | `smtp.example.com` |
| `puresmtp_encryption` | string | `tls` |
| `puresmtp_port` | int | `587` |
| `puresmtp_auto_tls` | bool | `1` |
| `puresmtp_auth` | bool | `1` |
| `puresmtp_username` | string | `user@example.com` |
| `puresmtp_password` | string | *(encrypted)* |
| `puresmtp_debug` | bool | `0` |
| `puresmtp_log_retention` | int | `30` *(days)* |
| `puresmtp_uninstall` | bool | `0` |
| `puresmtp_stop_sending` | bool | `0` |
| `puresmtp_rate_limit_enabled` | bool | `0` |
| `puresmtp_rate_limit_count` | int | `100` |
| `puresmtp_rate_limit_interval` | string | `hour` |
| `puresmtp_retry_queue_enabled` | bool | `1` |
| `puresmtp_retry_interval` | string | `15min` |
| `puresmtp_retry_max_attempts` | int | `5` |
| `puresmtp_retry_notify_admin` | bool | `1` |

### 14.3 Queue Table (`{prefix}_puresmtp_queue`)

Created via `dbDelta()` on plugin activation:

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT, AUTO_INCREMENT | Primary key |
| `date_added` | DATETIME | When the email was first queued |
| `next_retry` | DATETIME | Scheduled time of next send attempt |
| `attempt_count` | INT | How many times sending has been tried |
| `max_attempts` | INT | Maximum retries before marking as failed |
| `recipient` | TEXT | To / CC / BCC addresses |
| `subject` | TEXT | Email subject |
| `headers` | TEXT | Serialized email headers |
| `message` | LONGTEXT | Full email body |
| `attachments` | TEXT | Serialized attachment paths |
| `reason` | VARCHAR(50) | Why it was queued: `rate_limit` / `smtp_failure` / `stopped` |
| `status` | VARCHAR(20) | `pending` / `failed` / `sent` |

### 14.2 Email Log Table (`{prefix}_puresmtp_log`)

Created via `dbDelta()` on plugin activation:

| Column | Type | Description |
|---|---|---|
| `id` | BIGINT, AUTO_INCREMENT | Primary key |
| `date` | DATETIME | Timestamp of send attempt |
| `recipient` | TEXT | To / CC / BCC addresses |
| `subject` | TEXT | Email subject |
| `status` | VARCHAR(10) | `sent` or `failed` |
| `error_code` | VARCHAR(100) | PHPMailer/SMTP error code |
| `error_message` | TEXT | Full human-readable error text |
| `debug_trace` | LONGTEXT | Raw SMTP conversation (if debug ON) |
| `source_plugin` | VARCHAR(200) | Plugin that triggered wp_mail() |
