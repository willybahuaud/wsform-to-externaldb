<?php
/**
 * Journalisation.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Écrit dans le journal PHP via error_log().
 *
 * Actif quand WP_DEBUG l'est, ou forcé par l'option ws_form_db_logging_enabled.
 * Niveau minimal réglable par l'option ws_form_db_logging_level (ERROR par défaut).
 */
class WS_Form_DB_Logger {

	/**
	 * Option qui force l'activation.
	 *
	 * @var string
	 */
	const OPTION_ENABLED = 'ws_form_db_logging_enabled';

	/**
	 * Option du niveau minimal.
	 *
	 * @var string
	 */
	const OPTION_LEVEL = 'ws_form_db_logging_level';

	/**
	 * Niveaux, du plus bavard au plus grave.
	 *
	 * @var array<string,int>
	 */
	const LEVELS = array(
		'DEBUG'   => 0,
		'INFO'    => 1,
		'WARNING' => 2,
		'ERROR'   => 3,
	);

	/**
	 * Fragments de clés dont la valeur est masquée.
	 *
	 * @var string[]
	 */
	const SENSITIVE_KEYS = array( 'password', 'pass', 'pwd', 'secret', 'token', 'api_key' );

	/**
	 * Journalise une erreur.
	 *
	 * @param string $message Message.
	 * @param array  $context Contexte.
	 *
	 * @return void
	 */
	public static function log_error( $message, $context = array() ) {
		self::log( 'ERROR', $message, $context );
	}

	/**
	 * Journalise un avertissement.
	 *
	 * @param string $message Message.
	 * @param array  $context Contexte.
	 *
	 * @return void
	 */
	public static function log_warning( $message, $context = array() ) {
		self::log( 'WARNING', $message, $context );
	}

	/**
	 * Journalise une information.
	 *
	 * @param string $message Message.
	 * @param array  $context Contexte.
	 *
	 * @return void
	 */
	public static function log_info( $message, $context = array() ) {
		self::log( 'INFO', $message, $context );
	}

	/**
	 * Journalise une tentative de connexion.
	 *
	 * @param string $host     Hôte.
	 * @param string $database Base.
	 * @param bool   $success  Réussite.
	 * @param string $error    Raison de l'échec.
	 *
	 * @return void
	 */
	public static function log_connection( $host, $database, $success, $error = '' ) {
		$context = array(
			'host'     => $host,
			'database' => $database,
		);

		if ( $success ) {
			self::log( 'INFO', 'Connection successful', $context );
			return;
		}

		self::log( 'ERROR', 'Connection failed', array_merge( $context, array( 'error' => $error ) ) );
	}

	/**
	 * Journalise une insertion.
	 *
	 * @param string   $table       Table.
	 * @param int      $columns     Nombre de colonnes.
	 * @param bool     $success     Réussite.
	 * @param int|null $inserted_id ID inséré.
	 * @param string   $error       Raison de l'échec.
	 *
	 * @return void
	 */
	public static function log_insertion( $table, $columns, $success, $inserted_id = null, $error = '' ) {
		$context = array(
			'table'   => $table,
			'columns' => $columns,
		);

		if ( $success ) {
			self::log( 'INFO', 'Insert successful', array_merge( $context, array( 'inserted_id' => $inserted_id ) ) );
			return;
		}

		self::log( 'ERROR', 'Insert failed', array_merge( $context, array( 'error' => $error ) ) );
	}

	/**
	 * Écrit une ligne si le niveau est suffisant.
	 *
	 * @param string $level   Niveau (clé de LEVELS).
	 * @param string $message Message.
	 * @param array  $context Contexte.
	 *
	 * @return void
	 */
	private static function log( $level, $message, $context ) {
		if ( ! self::is_enabled() || self::LEVELS[ $level ] < self::LEVELS[ self::get_min_level() ] ) {
			return;
		}

		$line = sprintf( '[WS Form DB] [%s] %s', $level, $message );

		if ( array() !== $context ) {
			$line .= ' | Context: ' . wp_json_encode( self::redact( $context ), JSON_UNESCAPED_UNICODE );
		}

		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

		/**
		 * Après l'écriture d'une ligne de journal.
		 *
		 * @param string $level   Niveau.
		 * @param string $message Message.
		 * @param array  $context Contexte, non masqué.
		 * @param string $line    Ligne écrite.
		 */
		do_action( 'ws_form_db_after_log', $level, $message, $context, $line );
	}

	/**
	 * Indique si la journalisation est active.
	 *
	 * @return bool
	 */
	private static function is_enabled() {
		$forced = get_option( self::OPTION_ENABLED, null );

		return null === $forced ? ( defined( 'WP_DEBUG' ) && WP_DEBUG ) : (bool) $forced;
	}

	/**
	 * Retourne le niveau minimal configuré.
	 *
	 * @return string
	 */
	private static function get_min_level() {
		$level = get_option( self::OPTION_LEVEL, 'ERROR' );

		return isset( self::LEVELS[ $level ] ) ? $level : 'ERROR';
	}

	/**
	 * Masque les valeurs dont la clé ressemble à un secret.
	 *
	 * @param array $context Contexte.
	 *
	 * @return array
	 */
	private static function redact( $context ) {
		foreach ( $context as $key => $value ) {
			$key_lower = strtolower( (string) $key );

			foreach ( self::SENSITIVE_KEYS as $sensitive ) {
				if ( false !== strpos( $key_lower, $sensitive ) ) {
					$context[ $key ] = '***REDACTED***';
					continue 2;
				}
			}

			if ( is_array( $value ) ) {
				$context[ $key ] = self::redact( $value );
			} elseif ( is_object( $value ) ) {
				$context[ $key ] = get_class( $value );
			}
		}

		return $context;
	}
}
