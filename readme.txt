=== WP PureSMTP ===
Contributors: steffenkt
Tags: smtp, email, mail, phpmailer, wp_mail
Requires at least: 5.7
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Replace the WordPress default wp_mail() with a pure SMTP connection. No third-party APIs, no bloat — just SMTP.

== Description ==

WP PureSMTP replaces the WordPress default `wp_mail()` / PHP `mail()` function with a clean, pure SMTP connection using PHPMailer (bundled with WordPress).

Unlike other SMTP plugins, WP PureSMTP offers **only SMTP** as the sending method — no third-party API services, no feature bloat, no upsells.

**Features:**

* Pure SMTP sending via PHPMailer (no extra dependencies)
* Supports None / SSL / TLS encryption with auto-port suggestion
* SMTP authentication with encrypted password storage
* Force From Email / From Name across all plugins
* Email log – every wp_mail() attempt logged with status, error, and SMTP trace
* Mail queue – rate limiting and SMTP-failure retry with WP-Cron
* Kill switch – instantly stop all outgoing email
* Test Email tab – verify your SMTP config in one click
* Debug mode – capture full SMTP conversation
* Log retention – auto-cleanup after N days
* Clean uninstall – optionally removes all data on delete
* Translations-ready (.pot file included)

== Installation ==

1. Upload the `wp-puresmtp` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → WP PureSMTP** and enter your SMTP credentials.
4. Send a test email via the **Test Email** tab to confirm everything works.

== Frequently Asked Questions ==

= Does this plugin support third-party email services like Mailgun or SendGrid? =

No. WP PureSMTP is intentionally SMTP-only. If you need API-based sending, please use a different plugin.

= Where is the SMTP password stored? =

The password is encrypted with AES-256-CBC using a key derived from your WordPress `AUTH_KEY` before being saved to `wp_options`. It is never stored in plain text.

= Can I stop all outgoing email for testing? =

Yes. Enable **Stop sending all emails** in the Queue tab. A prominent admin notice will remind you that mail is blocked.

= Does the retry queue keep emails if my SMTP server is temporarily down? =

Yes. When SMTP sending fails, the email is saved to the queue table and retried automatically via WP-Cron at your configured interval.

= What happens to queued emails if I uninstall the plugin? =

If **Remove data on uninstall** is enabled in the Misc tab, all settings, the email log, and the queue are permanently deleted on uninstall. If disabled (default), your data is preserved.

== Screenshots ==

1. General settings tab – SMTP configuration
2. Email Log tab – all sent/failed emails with status
3. Queue tab – rate limiting and retry queue
4. Test Email tab
5. Misc tab

== Changelog ==

= 1.0.0 =
* Initial release
* SMTP mailer, admin settings (General, Email Log, Queue, Test Email, Misc)
* Email log with detail view and error hints
* Rate limiting + SMTP retry queue with WP-Cron
* Kill switch with admin notice
* Debug mode, log retention, clean uninstall

== Upgrade Notice ==

= 1.0.0 =
Initial release.
