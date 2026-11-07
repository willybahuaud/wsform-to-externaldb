# External Database for WS Form

A WS Form PRO action that inserts each form submission as a row in a table of another MySQL or MariaDB database.

You get a CRM, an ERP or a business database that lives outside WordPress fed by a WS Form form, without writing a custom action for each project. You pick the table in the form editor, map its columns to WS Form variables, and you are done.

## Requirements

- WordPress 6.0 or later, PHP 7.4 or later
- WS Form PRO, active
- Network access from the web server to the external database
- A database user with `INSERT` on the target table. That is enough: MySQL and MariaDB then let it list the table and read its columns.

## Quick start

1. Install and activate the plugin.
2. Declare the connection in `wp-config.php`:

   ```php
   define( 'WS_FORM_DB_CONNECTIONS', array(
       'crm' => array(
           'host'     => 'db.example.com',
           'port'     => 3306,
           'user'     => 'forms',
           'password' => '...',
           'database' => 'crm',
           'timeout'  => 5,          // Optional, in seconds. 5 by default.
           'sites'    => array( 2 ), // Optional, multisite: sites allowed to use it.
       ),
   ) );
   ```

3. In the form editor, open **Actions** and add **External database**.
4. Pick `crm` in **Connection**, click **Load tables**, choose the table, then **Analyze table**.
5. Map each column to a value, for example `#field(12)`, then save and publish the form.

You can also type the host, user and password directly in the action (**Manual settings**). It works, but read the section on passwords below first.

## What happens on submit

For each submission, the action:

1. resolves the connection, from `wp-config.php` or from the action settings;
2. connects with a short timeout: 5 seconds, or the connection's `timeout` key;
3. checks that the target is not the WordPress database;
4. reads the real table structure, cached for 5 minutes;
5. builds the row: each mapped column must exist and accept a value, and its template is parsed by WS Form;
6. inserts the row with a prepared statement.

A few rules apply when building values:

- Templates are parsed as `text/plain`: line breaks and accented characters arrive as typed.
- An empty value becomes `NULL` in a nullable column that is not a text column. A blank optional field does not end up as `0` or `0000-00-00`.
- An empty value in a `NOT NULL` column that has a default is left out, so the database applies its default.
- Text sent to a numeric column is refused instead of being silently converted to `0`. A decimal comma (`12,5`) is accepted.
- Generated columns and auto-increment columns cannot be mapped.
- A value too long for its column is refused. Nothing is truncated.

### When it fails

The visitor is never blocked. Whether the database is down, the credentials are wrong or a value does not fit, the visitor gets the form's usual confirmation, and the submission is saved by WS Form as usual (keep the **Save to Submissions** action).

The failure is:

- logged, see [Logging](#logging);
- added as a note on the WS Form submission;
- shown to administrators as a notice in the admin, until someone marks it as read.

Nothing is retried automatically. The data is in WS Form submissions, so you can export it and import it on the other side.

## Passwords

A password typed in the action is stored in plain text in the form settings, in the WordPress database. It is also included when the form is exported or duplicated. It is never sent to the public form, though: the JSON that WS Form publishes does not contain actions.

A named connection in `wp-config.php` avoids all of that: the form only stores the connection name, and the editor only sends that name to the server. Use it whenever you can.

## Security model

- **The WordPress database is refused by default.** After connecting, the plugin compares the server identity (`@@hostname`, `@@port` and current database) with WordPress's own. Comparing host names is not enough: an IP address or a DNS alias would get around it. To allow specific tables anyway:

  ```php
  define( 'WS_FORM_DB_ALLOWED_WP_TABLES', array( 'wp_my_leads' ) );
  ```

  `WS_FORM_DB_ALLOW_WORDPRESS_DATABASE` set to `true` lifts the protection entirely. You should not need it.

- **Values** always go through prepared statements. **Table and column names** cannot, so they are limited to `[A-Za-z0-9_$-]` and must exist in the real table.
- **Editor endpoints** (test connection, list tables, describe a table) require `manage_options`, or `manage_network_options` on multisite (filter `ws_form_db_capability`), and a nonce. They connect to whatever host is typed: that is their job.
- **Form editors.** Anyone allowed to edit WS Form forms can configure this action, including picking a named connection. Keep that right for trusted roles. On multisite, restrict a named connection to some sites with its `sites` key.
- **Stored submissions** never keep the action password: it is removed from the copy of the actions that WS Form saves with each submission.
- **Custom variables** (below) are only resolved in the template you write, never in what a visitor types.

## Variables

Any WS Form variable works in a column value: `#field(ID)`, `#blog_date_custom(Y-m-d)`, `#user_email`, `#if(...)`... See the [WS Form variables documentation](https://wsform.com/knowledgebase/variables/).

The plugin adds a few variables to read public WordPress data from an ID, typically one stored in a hidden field. They only work in this action's mapping. See [VARIABLES.md](VARIABLES.md).

## Configuration reference

### Constants (wp-config.php)

| Constant | Purpose |
|----------|---------|
| `WS_FORM_DB_CONNECTIONS` | Named connections, see [Quick start](#quick-start) |
| `WS_FORM_DB_ALLOWED_WP_TABLES` | WordPress database tables that may be written to |
| `WS_FORM_DB_ALLOW_WORDPRESS_DATABASE` | `true` lifts the WordPress database protection |

### Hooks

| Hook | Type | Purpose |
|------|------|---------|
| `ws_form_db_capability` | filter | Capability for the editor endpoints and the failure notice (default `manage_options`, `manage_network_options` on multisite) |
| `ws_form_db_connect_timeout` | filter | Connection timeout in seconds: `( $timeout, $host )`. Starts from the connection's `timeout` key, or `5` |
| `ws_form_db_table_cache_duration` | filter | Table structure cache in seconds (default `300`) |
| `ws_form_db_sanitize_value` | filter | Last chance to change a value before insert: `( $value, $template, $submit )` |
| `ws_form_db_after_log` | action | Runs after each log line: `( $level, $message, $context, $formatted )` |

### Logging

Logs go to the PHP error log through `error_log()`, prefixed with `[WS Form DB]`. They are on when `WP_DEBUG` is on, at `ERROR` level by default. Keys that look like secrets (`password`, `token`...) are redacted. Submitted values are not logged as such, but a database error message can quote one (a duplicate key, for example).

```php
update_option( 'ws_form_db_logging_enabled', true );    // Force logging on, even without WP_DEBUG.
update_option( 'ws_form_db_logging_level', 'DEBUG' );   // DEBUG, INFO, WARNING or ERROR.
```

## Development

```bash
npm install && npm run build     # Editor script and styles, from src/ to admin/
npm run start                    # Watch mode
composer install && composer run lint   # PHPCS, WordPress standards
```

Source strings are in English. The French translation lives in `languages/`. After changing a string:

```bash
wp i18n make-pot . languages/wsform-to-externaldb.pot --exclude=node_modules,vendor,admin/js,src,docs --skip-js
# Update languages/wsform-to-externaldb-fr_FR.po, then:
wp i18n make-mo languages/wsform-to-externaldb-fr_FR.po languages/
wp i18n make-php languages/wsform-to-externaldb-fr_FR.po languages/
```

`.distignore` lists what stays out of the release archive.

## License

GPL-2.0-or-later. By [Willy Bahuaud](https://wabeo.fr).

Bugs and ideas: [GitHub issues](https://github.com/willybahuaud/wsform-to-externaldb/issues).
