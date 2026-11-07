<?php
/**
 * Appels AJAX de l'éditeur WS Form.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points AJAX utilisés par la configuration de l'action.
 *
 * Tous exigent manage_options et un nonce. Ils ouvrent des connexions vers
 * l'hôte saisi : c'est leur rôle, réservé à un administrateur qui peut déjà
 * installer des extensions. Le délai court de WS_Form_DB_External_Wpdb limite
 * leur intérêt pour sonder le réseau.
 */
class WS_Form_DB_Admin_Ajax {

	/**
	 * Action du nonce partagé avec le script de l'éditeur.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'ws_form_db_action_nonce';

	/**
	 * Enregistre les points AJAX.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'wp_ajax_ws_form_db_test_connection', array( __CLASS__, 'test_connection' ) );
		add_action( 'wp_ajax_ws_form_db_get_tables', array( __CLASS__, 'get_tables' ) );
		add_action( 'wp_ajax_ws_form_db_analyze_table', array( __CLASS__, 'analyze_table' ) );
		add_action( 'wp_ajax_ws_form_db_get_form_fields', array( __CLASS__, 'get_form_fields' ) );
	}

	/**
	 * Teste la connexion.
	 *
	 * @return void
	 */
	public static function test_connection() {
		self::open_connection_or_die();

		wp_send_json_success( array( 'message' => __( 'Connection successful.', 'wsform-to-externaldb' ) ) );
	}

	/**
	 * Retourne la liste des tables utilisables.
	 *
	 * @return void
	 */
	public static function get_tables() {
		$db     = self::open_connection_or_die();
		$tables = WS_Form_DB_Table_Analyzer::get_tables( $db );

		self::die_on_error( $tables );

		wp_send_json_success( array( 'tables' => $tables ) );
	}

	/**
	 * Décrit les colonnes de la table choisie.
	 *
	 * @return void
	 */
	public static function analyze_table() {
		$db = self::open_connection_or_die();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce vérifié dans open_connection_or_die().
		$table_name = isset( $_POST['table'] ) ? sanitize_text_field( wp_unslash( $_POST['table'] ) ) : '';

		// L'éditeur doit toujours voir la structure à jour : pas de cache ici.
		$columns = WS_Form_DB_Table_Analyzer::get_columns( $db, $table_name, false );

		self::die_on_error( $columns );

		$writable = array();
		foreach ( $columns as $column ) {
			if ( empty( $column['auto_increment'] ) ) {
				$writable[] = $column;
			}
		}

		wp_send_json_success( array( 'columns' => $writable ) );
	}

	/**
	 * Retourne les champs du formulaire.
	 *
	 * @return void
	 */
	public static function get_form_fields() {
		self::verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce vérifié dans verify_request().
		$form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

		if ( ! $form_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing form ID.', 'wsform-to-externaldb' ) ) );
		}

		$fields = WS_Form_DB_Field_Mapper::get_form_fields( $form_id );

		self::die_on_error( $fields );

		wp_send_json_success( array( 'fields' => $fields ) );
	}

	/**
	 * Vérifie les droits et le nonce.
	 *
	 * @return void
	 */
	private static function verify_request() {
		WS_Form_DB_Security::require_capability();
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Vérifie la requête puis ouvre la connexion décrite par la requête.
	 *
	 * @return WS_Form_DB_External_Wpdb
	 */
	private static function open_connection_or_die() {
		self::verify_request();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce vérifié dans verify_request().
		$params = WS_Form_DB_Connection_Settings::from_request( $_POST );
		self::die_on_error( $params );

		$db = WS_Form_DB_Connection::open( $params );
		self::die_on_error( $db );

		return $db;
	}

	/**
	 * Répond en erreur et arrête la requête si la valeur est une WP_Error.
	 *
	 * @param mixed $value Valeur à tester.
	 *
	 * @return void
	 */
	private static function die_on_error( $value ) {
		if ( is_wp_error( $value ) ) {
			wp_send_json_error(
				array(
					'message' => $value->get_error_message(),
					'code'    => $value->get_error_code(),
				)
			);
		}
	}
}
