<?php
/**
 * Plugin Name: External Database for WS Form
 * Description: Adds a WS Form action that inserts submissions into an external MySQL or MariaDB database.
 * Author: Willy Bahuaud
 * Author URI: https://wabeo.fr
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI: https://github.com/willybahuaud/wsform-to-externaldb
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wsform-to-externaldb
 * Domain Path: /languages
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WS_FORM_DB_VERSION' ) ) {
	define( 'WS_FORM_DB_VERSION', '1.0.1' );
}

if ( ! defined( 'WS_FORM_DB_PLUGIN_FILE' ) ) {
	define( 'WS_FORM_DB_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'WS_FORM_DB_PLUGIN_DIR' ) ) {
	define( 'WS_FORM_DB_PLUGIN_DIR', plugin_dir_path( WS_FORM_DB_PLUGIN_FILE ) );
}

if ( ! defined( 'WS_FORM_DB_PLUGIN_URL' ) ) {
	define( 'WS_FORM_DB_PLUGIN_URL', plugin_dir_url( WS_FORM_DB_PLUGIN_FILE ) );
}

if ( ! defined( 'WS_FORM_DB_PLUGIN_BASENAME' ) ) {
	define( 'WS_FORM_DB_PLUGIN_BASENAME', plugin_basename( WS_FORM_DB_PLUGIN_FILE ) );
}

/**
 * Vérifie si WS Form est actif et disponible.
 *
 * @return bool
 */
function ws_form_db_is_dependency_met() {
	return class_exists( 'WS_Form' );
}

/**
 * Affiche un message d'erreur si WS Form est absent.
 *
 * @return void
 */
function ws_form_db_dependency_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'WS Form must be active to use External Database for WS Form.', 'wsform-to-externaldb' ) . '</p></div>';
}

/**
 * Autoloader pour les classes du plugin.
 *
 * @param string $class_name Nom de la classe à charger.
 *
 * @return void
 */
function ws_form_db_autoloader( $class_name ) {
	if ( 0 !== strpos( $class_name, 'WS_Form_DB_' ) ) {
		return;
	}

	$relative = strtolower( str_replace( 'WS_Form_DB_', '', $class_name ) );
	$relative = str_replace( '_', '-', $relative );
	$path     = WS_FORM_DB_PLUGIN_DIR . 'includes/class-' . $relative . '.php';

	if ( file_exists( $path ) ) {
		require_once $path;
	}
}

/**
 * Enregistre l'autoloader des classes principales.
 *
 * @return void
 */
function ws_form_db_register_autoloader() {
	spl_autoload_register( 'ws_form_db_autoloader' );
}

/**
 * Initialisation des composants communs.
 *
 * @return void
 */
function ws_form_db_init_components() {
	// Le plugin n'est pas hébergé sur WordPress.org : ses traductions sont livrées dans languages/.
	load_plugin_textdomain( 'wsform-to-externaldb', false, dirname( WS_FORM_DB_PLUGIN_BASENAME ) . '/languages' ); // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound

	if ( is_admin() ) {
		WS_Form_DB_Admin_Ajax::register();
		WS_Form_DB_Failure_Notifier::register();
	}
}

/**
 * Bootstrap complet du plugin après chargement des plugins.
 *
 * @return void
 */
function ws_form_db_bootstrap() {
	if ( ! ws_form_db_is_dependency_met() ) {
		add_action( 'admin_notices', 'ws_form_db_dependency_notice' );
		return;
	}

	ws_form_db_register_autoloader();

	if ( class_exists( 'WS_Form_Action' ) && class_exists( 'WS_Form_DB_WS_Form_Action' ) ) {
		WS_Form_DB_WS_Form_Action::get_instance();
	}

	$updater = new WS_Form_DB_Updater(
		array(
			'owner'       => 'willybahuaud',
			'repository'  => 'wsform-to-externaldb',
			'plugin_file' => WS_FORM_DB_PLUGIN_FILE,
			'version'     => WS_FORM_DB_VERSION,
		)
	);
	$updater->register_hooks();

	add_action( 'init', 'ws_form_db_init_components' );
}
add_action( 'plugins_loaded', 'ws_form_db_bootstrap' );

/**
 * Actions à exécuter lors de l'activation du plugin.
 *
 * @return void
 */
function ws_form_db_activate() {
	if ( ! ws_form_db_is_dependency_met() ) {
		deactivate_plugins( WS_FORM_DB_PLUGIN_BASENAME );
		wp_die(
			esc_html__( 'WS Form must be installed and active before activating External Database for WS Form.', 'wsform-to-externaldb' ),
			esc_html__( 'Activation failed', 'wsform-to-externaldb' ),
			array( 'back_link' => true )
		);
	}
}
register_activation_hook( WS_FORM_DB_PLUGIN_FILE, 'ws_form_db_activate' );
