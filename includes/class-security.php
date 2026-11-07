<?php
/**
 * Validation des entrées et contrôle des droits.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation des paramètres de connexion, des identifiants SQL et des droits.
 */
class WS_Form_DB_Security {

	/**
	 * Capability requise pour configurer une connexion.
	 *
	 * @var string
	 */
	const ADMIN_CAPABILITY = 'manage_options';

	/**
	 * Longueur maximale d'un identifiant MySQL.
	 *
	 * @var int
	 */
	const MAX_SQL_IDENTIFIER_LENGTH = 64;

	/**
	 * Valide un jeu complet de paramètres de connexion.
	 *
	 * @param array $params Paramètres (host, port, user, database).
	 *
	 * @return true|WP_Error
	 */
	public static function validate_connection_params( $params ) {
		foreach ( array( 'host', 'user', 'database' ) as $key ) {
			if ( empty( $params[ $key ] ) ) {
				return new WP_Error(
					'ws_form_db_missing_param',
					sprintf(
						/* translators: %s: setting name */
						__( 'The “%s” setting is required.', 'wsform-to-externaldb' ),
						$key
					)
				);
			}
		}

		$host_check = self::validate_host( $params['host'] );
		if ( is_wp_error( $host_check ) ) {
			return $host_check;
		}

		$port = isset( $params['port'] ) ? absint( $params['port'] ) : 0;
		if ( $port < 1 || $port > 65535 ) {
			return new WP_Error( 'ws_form_db_invalid_port', __( 'The port must be between 1 and 65535.', 'wsform-to-externaldb' ) );
		}

		return self::validate_sql_identifier( $params['database'] );
	}

	/**
	 * Valide un nom d'hôte : localhost, IP ou nom de domaine.
	 *
	 * @param string $host Hôte, sans port.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_host( $host ) {
		$host = (string) $host;

		if ( 'localhost' === strtolower( $host ) || filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		$domain_regex = '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';

		if ( preg_match( $domain_regex, $host ) ) {
			return true;
		}

		return new WP_Error( 'ws_form_db_invalid_host', __( 'The host must be an IP address or a domain name, without a port.', 'wsform-to-externaldb' ) );
	}

	/**
	 * Valide un identifiant SQL (base, table, colonne).
	 *
	 * Les identifiants ne passent pas par les requêtes préparées : ils sont donc
	 * limités aux caractères sans risque, puis comparés à la structure réelle.
	 *
	 * @param string $name Identifiant.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_sql_identifier( $name ) {
		$name = (string) $name;

		if ( '' === $name || strlen( $name ) > self::MAX_SQL_IDENTIFIER_LENGTH || ! preg_match( '/^[A-Za-z0-9_$]+$/', $name ) ) {
			return new WP_Error(
				'ws_form_db_invalid_identifier',
				sprintf(
					/* translators: %s: rejected identifier */
					__( 'SQL identifier rejected: “%s”. Only unaccented letters, digits, _ and $ are allowed.', 'wsform-to-externaldb' ),
					$name
				)
			);
		}

		return true;
	}

	/**
	 * Arrête la requête AJAX si l'utilisateur n'a pas les droits.
	 *
	 * @return void
	 */
	public static function require_capability() {
		if ( ! current_user_can( self::ADMIN_CAPABILITY ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to do this.', 'wsform-to-externaldb' ) ),
				403
			);
		}
	}
}
