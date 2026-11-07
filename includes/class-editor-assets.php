<?php
/**
 * Script et styles de l'éditeur de formulaire.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Charge l'interface de configuration de l'action sur les écrans WS Form.
 */
class WS_Form_DB_Editor_Assets {

	/**
	 * Handle du script et de la feuille de style.
	 *
	 * @var string
	 */
	const HANDLE = 'ws-form-db-action-config';

	/**
	 * Branche le chargement.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Charge le script et le style sur les écrans WS Form.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( $screen->id, 'ws-form' ) ) {
			return;
		}

		$asset_file = WS_FORM_DB_PLUGIN_DIR . 'admin/js/ws-form-action-config.asset.php';
		$asset      = file_exists( $asset_file ) ? include $asset_file : array();
		$asset      = wp_parse_args(
			is_array( $asset ) ? $asset : array(),
			array(
				'dependencies' => array(),
				'version'      => WS_FORM_DB_VERSION,
			)
		);

		wp_enqueue_script(
			self::HANDLE,
			WS_FORM_DB_PLUGIN_URL . 'admin/js/ws-form-action-config.js',
			array_merge( $asset['dependencies'], array( 'jquery' ) ),
			$asset['version'],
			true
		);

		wp_localize_script( self::HANDLE, 'wsFormDbConfig', self::get_script_config() );

		wp_enqueue_style( self::HANDLE, WS_FORM_DB_PLUGIN_URL . 'admin/css/ws-form-action-config.css', array(), $asset['version'] );
	}

	/**
	 * Configuration passée au script.
	 *
	 * @return array
	 */
	private static function get_script_config() {
		return array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( WS_Form_DB_Admin_Ajax::NONCE_ACTION ),
			'actionId' => WS_Form_DB_Connection_Settings::ACTION_ID,
			// Traces console seulement avec SCRIPT_DEBUG.
			'debug'    => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
			// Textes bruts : le script les échappe lui-même selon qu'il les insère en texte ou en HTML.
			'i18n'     => array(
				'testSuccess'       => __( 'Connection successful!', 'wsform-to-externaldb' ),
				'testError'         => __( 'Connection failed.', 'wsform-to-externaldb' ),
				'tablesLoaded'      => __( 'Tables loaded.', 'wsform-to-externaldb' ),
				'tablesError'       => __( 'Could not load the tables.', 'wsform-to-externaldb' ),
				'analyzeSuccess'    => __( 'Table analyzed.', 'wsform-to-externaldb' ),
				'analyzeError'      => __( 'Analysis failed.', 'wsform-to-externaldb' ),
				'noColumns'         => __( 'No mappable column found.', 'wsform-to-externaldb' ),
				'loading'           => __( 'Loading…', 'wsform-to-externaldb' ),
				'selectTableFirst'  => __( 'Please select a table.', 'wsform-to-externaldb' ),
				'connectionMissing' => __( 'Incomplete connection settings.', 'wsform-to-externaldb' ),
				'selectTable'       => __( '-- Select a table --', 'wsform-to-externaldb' ),
				'addMapping'        => __( 'Add mapping', 'wsform-to-externaldb' ),
				'selectColumn'      => __( '-- Column --', 'wsform-to-externaldb' ),
				'valuePlaceholder'  => __( '#field(123), #email, text...', 'wsform-to-externaldb' ),
				'alreadyUsed'       => __( 'already used', 'wsform-to-externaldb' ),
				'mappingIntro'      => __( 'Use WS Form variables: #field(ID), #email, #blog_time...', 'wsform-to-externaldb' ),
				'mappingTitle'      => __( 'Column mapping', 'wsform-to-externaldb' ),
				'removeRow'         => __( 'Remove', 'wsform-to-externaldb' ),
				'variables'         => __( 'Variables', 'wsform-to-externaldb' ),
				'requestError'      => __( 'The request failed.', 'wsform-to-externaldb' ),
				'variablesFallback' => __( "WS Form variables: #field(ID), #email, #blog_time, #user_id...\n\nSee the WS Form documentation for the full list.", 'wsform-to-externaldb' ),
			),
		);
	}
}
