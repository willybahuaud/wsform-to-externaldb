<?php
/**
 * Lecture de la structure des tables externes.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Liste les tables et décrit leurs colonnes, sur une connexion déjà ouverte.
 */
class WS_Form_DB_Table_Analyzer {

	/**
	 * Durée du cache de structure, en secondes.
	 *
	 * @var int
	 */
	const CACHE_DURATION = 300;

	/**
	 * Liste les tables utilisables sur la connexion.
	 *
	 * @param WS_Form_DB_External_Wpdb $db Connexion externe ouverte.
	 *
	 * @return string[]|WP_Error
	 */
	public static function get_tables( $db ) {
		$tables = $db->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( '' !== $db->last_error ) {
			return new WP_Error( 'ws_form_db_tables_failed', $db->last_error );
		}

		return WS_Form_DB_WordPress_Guard::filter_tables( $db, $tables );
	}

	/**
	 * Décrit les colonnes d'une table, après avoir vérifié qu'elle existe.
	 *
	 * @param WS_Form_DB_External_Wpdb $db         Connexion externe ouverte.
	 * @param string                   $table_name Table visée.
	 * @param bool                     $use_cache  Utiliser le cache de structure.
	 *
	 * @return array[]|WP_Error Colonnes : name, type, null, default, extra, auto_increment.
	 */
	public static function get_columns( $db, $table_name, $use_cache = true ) {
		$identifier_check = WS_Form_DB_Security::validate_sql_identifier( $table_name );
		if ( is_wp_error( $identifier_check ) ) {
			return $identifier_check;
		}

		$guard_check = WS_Form_DB_WordPress_Guard::check_table( $db, $table_name );
		if ( is_wp_error( $guard_check ) ) {
			return $guard_check;
		}

		$cache_key = 'ws_form_db_table_' . md5( $db->get_cache_scope() . '|' . $table_name );

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$tables = self::get_tables( $db );
		if ( is_wp_error( $tables ) ) {
			return $tables;
		}

		// Le nom est interpolé dans DESCRIBE : il doit exister tel quel dans la base.
		if ( ! in_array( $table_name, $tables, true ) ) {
			return new WP_Error(
				'ws_form_db_unknown_table',
				sprintf(
					/* translators: %s: table name */
					__( 'Table “%s” does not exist in the external database.', 'wsform-to-externaldb' ),
					$table_name
				)
			);
		}

		$rows = $db->get_results( 'DESCRIBE `' . $table_name . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared

		if ( '' !== $db->last_error || ! is_array( $rows ) ) {
			return new WP_Error( 'ws_form_db_describe_failed', $db->last_error );
		}

		$columns = array();

		foreach ( $rows as $row ) {
			$extra = isset( $row['Extra'] ) ? (string) $row['Extra'] : '';

			$columns[] = array(
				'name'           => $row['Field'],
				'type'           => isset( $row['Type'] ) ? $row['Type'] : '',
				'null'           => isset( $row['Null'] ) ? $row['Null'] : '',
				'default'        => isset( $row['Default'] ) ? $row['Default'] : '',
				'extra'          => $extra,
				'auto_increment' => false !== strpos( $extra, 'auto_increment' ),
			);
		}

		/**
		 * Filtre la durée du cache de structure des tables.
		 *
		 * @param int $duration Durée en secondes.
		 */
		set_transient( $cache_key, $columns, (int) apply_filters( 'ws_form_db_table_cache_duration', self::CACHE_DURATION ) );

		return $columns;
	}

	/**
	 * Retourne les noms des colonnes qui peuvent recevoir une valeur.
	 *
	 * @param array[] $columns Colonnes décrites par get_columns().
	 *
	 * @return string[]
	 */
	public static function get_writable_column_names( $columns ) {
		$names = array();

		foreach ( $columns as $column ) {
			if ( empty( $column['auto_increment'] ) ) {
				$names[] = $column['name'];
			}
		}

		return $names;
	}
}
