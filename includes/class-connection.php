<?php
/**
 * Ouverture des connexions vers les bases externes.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ouvre, vérifie et met en cache les connexions externes de la requête courante.
 *
 * Une connexion par jeu de paramètres : deux actions d'un même formulaire
 * peuvent viser deux bases différentes.
 */
class WS_Form_DB_Connection {

	/**
	 * Connexions ouvertes pendant la requête, indexées par empreinte des paramètres.
	 *
	 * @var array<string,WS_Form_DB_External_Wpdb>
	 */
	private static $connections = array();

	/**
	 * Ouvre une connexion vérifiée vers la base externe.
	 *
	 * La protection de la base WordPress est appliquée ici, une fois connecté,
	 * pour comparer les serveurs réels plutôt que des noms d'hôte.
	 *
	 * @param array $params Paramètres validés par WS_Form_DB_Connection_Settings.
	 *
	 * @return WS_Form_DB_External_Wpdb|WP_Error
	 */
	public static function open( $params ) {
		$key = self::build_key( $params );

		if ( isset( self::$connections[ $key ] ) ) {
			return self::$connections[ $key ];
		}

		$host = $params['host'];
		if ( WS_Form_DB_Connection_Settings::DEFAULT_PORT !== (int) $params['port'] ) {
			$host .= ':' . (int) $params['port'];
		}

		$db = new WS_Form_DB_External_Wpdb( $params['user'], $params['password'], $params['database'], $host, $params['timeout'] );

		// ready reste à false si la connexion ou la sélection de la base a échoué.
		if ( empty( $db->dbh ) || ! $db->ready ) {
			$reason = '' !== $db->last_error ? $db->last_error : (string) mysqli_connect_error(); // phpcs:ignore WordPress.DB.RestrictedFunctions.mysql_mysqli_connect_error -- wpdb ne conserve pas l'erreur de connexion.
			$reason = '' !== $reason ? $reason : __( 'Server unreachable or credentials rejected.', 'wsform-to-externaldb' );

			WS_Form_DB_Logger::log_connection( $params['host'], $params['database'], false, $reason );

			return new WP_Error(
				'ws_form_db_connection_failed',
				sprintf(
					/* translators: %s: failure reason */
					__( 'Cannot connect to the external database: %s', 'wsform-to-externaldb' ),
					$reason
				)
			);
		}

		$guard = WS_Form_DB_WordPress_Guard::check_connection( $db );

		if ( is_wp_error( $guard ) ) {
			$db->close();

			return $guard;
		}

		WS_Form_DB_Logger::log_connection( $params['host'], $params['database'], true );

		self::$connections[ $key ] = $db;

		return $db;
	}

	/**
	 * Calcule l'empreinte d'un jeu de paramètres.
	 *
	 * @param array $params Paramètres de connexion.
	 *
	 * @return string
	 */
	private static function build_key( $params ) {
		return md5( implode( "\0", array( $params['host'], $params['port'], $params['user'], $params['password'], $params['database'], $params['timeout'] ) ) );
	}
}
