<?php
/**
 * Protection de la base de données WordPress.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refuse la base WordPress, comparée par l'identité du serveur et non par le nom d'hôte
 * (une IP ou un alias contournerait la comparaison).
 *
 * Exceptions dans wp-config.php : WS_FORM_DB_ALLOWED_WP_TABLES (tables autorisées)
 * ou WS_FORM_DB_ALLOW_WORDPRESS_DATABASE (aucune protection).
 */
class WS_Form_DB_WordPress_Guard {

	/**
	 * Constante qui autorise des tables précises de la base WordPress.
	 *
	 * @var string
	 */
	const ALLOWED_TABLES_CONSTANT = 'WS_FORM_DB_ALLOWED_WP_TABLES';

	/**
	 * Constante qui lève toute protection.
	 *
	 * @var string
	 */
	const ALLOW_ALL_CONSTANT = 'WS_FORM_DB_ALLOW_WORDPRESS_DATABASE';

	/**
	 * Refuse la connexion si elle mène à la base WordPress sans table autorisée.
	 *
	 * @param wpdb $external_db Connexion externe ouverte.
	 *
	 * @return true|WP_Error
	 */
	public static function check_connection( $external_db ) {
		if ( self::is_fully_allowed() || ! self::is_wordpress_database( $external_db ) ) {
			return true;
		}

		if ( array() !== self::get_allowed_tables() ) {
			return true;
		}

		WS_Form_DB_Logger::log_warning( 'Connection to the WordPress database blocked' );

		return new WP_Error(
			'ws_form_db_wordpress_database_forbidden',
			sprintf(
				/* translators: %s: constant name */
				__( 'This connection leads to the WordPress database, which is refused by default. To allow specific tables, define %s in wp-config.php.', 'wsform-to-externaldb' ),
				self::ALLOWED_TABLES_CONSTANT
			)
		);
	}

	/**
	 * Refuse une table de la base WordPress qui n'est pas explicitement autorisée.
	 *
	 * @param wpdb   $external_db Connexion externe ouverte.
	 * @param string $table_name  Table visée.
	 *
	 * @return true|WP_Error
	 */
	public static function check_table( $external_db, $table_name ) {
		if ( self::is_fully_allowed() || ! self::is_wordpress_database( $external_db ) ) {
			return true;
		}

		if ( in_array( strtolower( $table_name ), self::get_allowed_tables(), true ) ) {
			return true;
		}

		return new WP_Error(
			'ws_form_db_table_not_allowed',
			sprintf(
				/* translators: 1: table name, 2: constant name */
				__( 'WordPress database table “%1$s” is not allowed. Add it to %2$s in wp-config.php.', 'wsform-to-externaldb' ),
				$table_name,
				self::ALLOWED_TABLES_CONSTANT
			)
		);
	}

	/**
	 * Ne garde que les tables utilisables sur cette connexion.
	 *
	 * @param wpdb     $external_db Connexion externe ouverte.
	 * @param string[] $tables      Tables de la base.
	 *
	 * @return string[]
	 */
	public static function filter_tables( $external_db, $tables ) {
		if ( self::is_fully_allowed() || ! self::is_wordpress_database( $external_db ) ) {
			return $tables;
		}

		$allowed = self::get_allowed_tables();
		$kept    = array();

		foreach ( $tables as $table ) {
			if ( in_array( strtolower( $table ), $allowed, true ) ) {
				$kept[] = $table;
			}
		}

		return $kept;
	}

	/**
	 * Détermine si la connexion externe pointe vers la base WordPress.
	 *
	 * @param WS_Form_DB_External_Wpdb $external_db Connexion externe ouverte.
	 *
	 * @return bool
	 */
	public static function is_wordpress_database( $external_db ) {
		global $wpdb;

		if ( null !== $external_db->is_wordpress_database ) {
			return $external_db->is_wordpress_database;
		}

		$external_identity  = self::get_server_identity( $external_db );
		$wordpress_identity = self::get_server_identity( $wpdb );

		// Si l'identité est illisible, on bloque : mieux vaut un faux positif qu'une écriture dans wp_users.
		$is_wordpress = ( null === $external_identity || $external_identity === $wordpress_identity );

		$external_db->is_wordpress_database = $is_wordpress;

		return $is_wordpress;
	}

	/**
	 * Lit l'identité d'un serveur : nom, port et base courante.
	 *
	 * @param wpdb $db Connexion à interroger.
	 *
	 * @return string|null Identité, ou null si la requête échoue.
	 */
	private static function get_server_identity( $db ) {
		$row = $db->get_row( 'SELECT @@hostname AS server_host, @@port AS server_port, DATABASE() AS current_database', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! is_array( $row ) ) {
			return null;
		}

		return strtolower( implode( '|', array( $row['server_host'], $row['server_port'], $row['current_database'] ) ) );
	}

	/**
	 * Indique si toute protection a été levée dans wp-config.php.
	 *
	 * @return bool
	 */
	private static function is_fully_allowed() {
		return defined( self::ALLOW_ALL_CONSTANT ) && true === constant( self::ALLOW_ALL_CONSTANT );
	}

	/**
	 * Retourne les tables de la base WordPress autorisées, en minuscules.
	 *
	 * @return string[]
	 */
	private static function get_allowed_tables() {
		if ( ! defined( self::ALLOWED_TABLES_CONSTANT ) ) {
			return array();
		}

		$allowed = constant( self::ALLOWED_TABLES_CONSTANT );

		// Une chaîne « table_a, table_b » est aussi acceptée.
		if ( is_string( $allowed ) ) {
			$allowed = explode( ',', $allowed );
		}

		if ( ! is_array( $allowed ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $allowed ) ) ) );
	}
}
