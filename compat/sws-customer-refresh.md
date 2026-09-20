# Hourly SWS Pro customer refresh

`stw-sws-customer-refresh.php` is a standalone WordPress must-use plugin for
Stone Tower's main site (blog 1). Install it in `wp-content/mu-plugins/`.

It schedules `stw_sws_customer_refresh_hourly` using WordPress's `hourly`
recurrence, first due one hour after installation. The existing server cron
runner checks due WordPress jobs every minute; a busy runner can delay execution.

Each run calls the same `CustomersController::get_customers()` implementation
used by **SWS Pro → Customers → Refresh**, with `force='true'` and no `full`
parameter. SWS Pro continues to choose incremental versus full fetch using its
existing settings and merchant baseline. The extension does not implement new
webhook, customer, loyalty, or payment synchronization rules.

An existing `queuing` or `processing` status skips the scheduled run, including
statuses older than SWS's normal 30-minute UI guard. This deliberately avoids
restarting a possibly long-running refresh. A stuck native status needs manual
investigation; the scheduler does not clear or cancel it. A short MariaDB named
lock coordinates enqueueing with authenticated manual Refresh requests. It is
released as soon as the native controller queues its background job.

The wrapper records only its latest attempt time, outcome, and HTTP status in
`stw_sws_customer_refresh_last_attempt`. Native progress and completion remain in
`sws_customer_fetch_status`. No customer details are added to these diagnostics.

## Operations

Run WP-CLI as `www-data` with the production path and site URL:

```sh
sudo -n -u www-data wp --path=/var/www/wordpress --url=https://www.stonetowerwinery.com cron event list --hook=stw_sws_customer_refresh_hourly
sudo -n -u www-data wp --path=/var/www/wordpress --url=https://www.stonetowerwinery.com option get sws_customer_fetch_status --format=json
sudo -n -u www-data wp --path=/var/www/wordpress --url=https://www.stonetowerwinery.com option get stw_sws_customer_refresh_last_attempt --format=json
```

The last-attempt option does not exist until the first attempt. To disable:

```sh
sudo -n -u www-data wp --path=/var/www/wordpress --url=https://www.stonetowerwinery.com option update stw_sws_customer_refresh_enabled false --format=json
sudo -n -u www-data wp --path=/var/www/wordpress --url=https://www.stonetowerwinery.com cron event delete stw_sws_customer_refresh_hourly
```

To re-enable, update the option to `true`; the next WordPress load schedules a
fresh hourly event. Disabling does not cancel a job already queued by SWS.
Removing the MU-plugin and deleting this exact cron hook rolls back the addition.

## Validation

Run `php tests/sws-customer-refresh.php` and lint the MU-plugin. Release checks
verify native source checksums, blog ID, MariaDB named locks, the existing
system cron runner, MU-plugin loading, a single hourly event, a future first run,
and normal HTTP availability. Installation does not trigger an extra refresh
immediately after the user's already-completed manual run.
