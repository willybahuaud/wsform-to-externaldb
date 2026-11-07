=== External Database for WS Form ===
Contributors: willybahuaud
Tags: ws form, database, mysql, mariadb, form submission
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a WS Form action that inserts each submission into a table of an external MySQL or MariaDB database.

== Description ==

External Database for WS Form adds an "External database" action to WS Form PRO. On submit, it inserts one row into a table of another MySQL or MariaDB database, using a column mapping built from WS Form variables.

Typical use: feed a CRM, an ERP or a business database that lives outside WordPress, without writing a custom action.

= Features =

* Connection declared in wp-config.php, or entered in the action
* Table and column discovery from the editor
* Column mapping with any WS Form variable: `#field(ID)`, `#email`, `#blog_date_custom(Y-m-d)`...
* Extra variables to read public WordPress data from an ID: `#get_post_title(#field(12))`
* Values checked against the real table structure before insert
* Visitors are never blocked by a database failure: the submission stays in WS Form and the administrator is alerted

= Security =

* The WordPress database is refused by default, detected by the server identity, not by the host name.
* All values go through prepared statements. Table and column names must exist in the target table.
* Connection settings never reach the public form.
* Configuration endpoints require the `manage_options` capability and a nonce.

== Installation ==

1. Install and activate WS Form PRO.
2. Upload the `wsform-to-externaldb` folder to `/wp-content/plugins/` and activate it.
3. Declare a connection in wp-config.php (recommended), see the FAQ.
4. In the form editor, add the "External database" action, load the tables, pick one and map its columns.

== Frequently Asked Questions ==

= How do I keep the database password out of the form? =

Declare a named connection in wp-config.php, then pick it in the action:

`define( 'WS_FORM_DB_CONNECTIONS', array( 'crm' => array( 'host' => 'db.example.com', 'port' => 3306, 'user' => 'forms', 'password' => '...', 'database' => 'crm' ) ) );`

A password typed in the action itself is stored in plain text with the form, and included in its exports and copies.

= What happens if the external database is down? =

The visitor sees the normal confirmation and the submission is saved by WS Form. The connection gives up after 5 seconds. Change it with a `timeout` key in the named connection, or with the `ws_form_db_connect_timeout` filter. The failure is logged, added as a note on the submission, and shown as a notice in the admin.

= Can I write into the WordPress database? =

Not by default. To allow specific tables, add to wp-config.php:

`define( 'WS_FORM_DB_ALLOWED_WP_TABLES', array( 'wp_my_leads' ) );`

= What rights should the database user have? =

Only `INSERT` on the target table. MySQL and MariaDB then let it list that table and read its columns, which is all the editor needs.

= How is the plugin updated? =

From the GitHub releases of its repository, through the usual WordPress update screen. Automatic updates can be turned on in the plugin list.

== Changelog ==

= 1.0.1 =
* Editor script rewritten as small modules, no more hard-coded French strings.
* Console traces only when SCRIPT_DEBUG is on.

= 1.0.0 =
* First public version.

== Privacy ==

This plugin sends form submissions to the external database configured by the site administrator. It does not contact any other service.
