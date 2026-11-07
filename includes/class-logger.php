<?php
/**
 * Classe de logging pour WS Form to Database.
 *
 * Fournit un système de logs centralisé pour le débogage et le suivi
 * des opérations du plugin.
 *
 * @package WS_Form_DB
 * @since   1.0.0
 */

// Empêcher l'accès direct.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe WS_Form_DB_Logger.
 *
 * Système de logging avec différents niveaux de log.
 *
 * @since 1.0.0
 */
class WS_Form_DB_Logger {

	/**
	 * Niveau de log DEBUG.
	 *
	 * @var string
	 */
	const DEBUG = 'DEBUG';

	/**
	 * Niveau de log INFO.
	 *
	 * @var string
	 */
	const INFO = 'INFO';

	/**
	 * Niveau de log WARNING.
	 *
	 * @var string
	 */
	const WARNING = 'WARNING';

	/**
	 * Niveau de log ERROR.
	 *
	 * @var string
	 */
	const ERROR = 'ERROR';

	/**
	 * Préfixe pour les messages de log.
	 *
	 * @var string
	 */
	const LOG_PREFIX = '[WS Form DB]';

	/**
	 * Option WordPress pour activer/désactiver les logs.
	 *
	 * @var string
	 */
	const OPTION_ENABLED = 'ws_form_db_logging_enabled';

	/**
	 * Option WordPress pour le niveau de log.
	 *
	 * @var string
	 */
	const OPTION_LEVEL = 'ws_form_db_logging_level';

	/**
	 * Instance singleton.
	 *
	 * @var WS_Form_DB_Logger|null
	 */
	private static $instance = null;

	/**
	 * Indique si le logging est activé.
	 *
	 * @var bool
	 */
	private $enabled;

	/**
	 * Niveau de log minimum.
	 *
	 * @var string
	 */
	private $level;

	/**
	 * Priorité des niveaux de log.
	 *
	 * @var array
	 */
	private $level_priority = array(
		self::DEBUG   => 0,
		self::INFO    => 1,
		self::WARNING => 2,
		self::ERROR   => 3,
	);

	/**
	 * Constructeur privé (singleton).
	 */
	private function __construct() {
		$this->enabled = $this->is_logging_enabled();
		$this->level   = $this->get_logging_level();
	}

	/**
	 * Récupère l'instance singleton.
	 *
	 * @since 1.0.0
	 *
	 * @return WS_Form_DB_Logger
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Vérifie si le logging est activé.
	 *
	 * Par défaut, le logging est activé si WP_DEBUG est true.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_logging_enabled() {
		$option = get_option( self::OPTION_ENABLED, null );

		// Si l'option n'est pas définie, utiliser WP_DEBUG.
		if ( null === $option ) {
			return defined( 'WP_DEBUG' ) && WP_DEBUG;
		}

		return (bool) $option;
	}

	/**
	 * Récupère le niveau de log configuré.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_logging_level() {
		$level = get_option( self::OPTION_LEVEL, self::ERROR );

		if ( ! isset( $this->level_priority[ $level ] ) ) {
			return self::ERROR;
		}

		return $level;
	}

	/**
	 * Vérifie si un niveau de log doit être enregistré.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level Le niveau à vérifier.
	 * @return bool
	 */
	private function should_log( $level ) {
		if ( ! $this->enabled ) {
			return false;
		}

		$current_priority = isset( $this->level_priority[ $level ] ) ? $this->level_priority[ $level ] : 0;
		$min_priority     = isset( $this->level_priority[ $this->level ] ) ? $this->level_priority[ $this->level ] : 0;

		return $current_priority >= $min_priority;
	}

	/**
	 * Formate un message de log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Niveau de log.
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return string
	 */
	private function format_message( $level, $message, $context = array() ) {
		$timestamp = current_time( 'Y-m-d H:i:s' );
		$formatted = sprintf(
			'%s [%s] %s %s',
			$timestamp,
			$level,
			self::LOG_PREFIX,
			$message
		);

		if ( ! empty( $context ) ) {
			// Sanitizer le contexte pour éviter de logger des données sensibles.
			$safe_context = $this->sanitize_context( $context );
			$formatted   .= ' | Context: ' . wp_json_encode( $safe_context, JSON_UNESCAPED_UNICODE );
		}

		return $formatted;
	}

	/**
	 * Sanitize le contexte pour éviter de logger des données sensibles.
	 *
	 * @since 1.0.0
	 *
	 * @param array $context Le contexte à sanitizer.
	 * @return array
	 */
	private function sanitize_context( $context ) {
		$sensitive_keys = array( 'password', 'pass', 'pwd', 'secret', 'token', 'key', 'api_key' );
		$result         = array();

		foreach ( $context as $key => $value ) {
			$key_lower = strtolower( $key );

			// Masquer les valeurs sensibles.
			foreach ( $sensitive_keys as $sensitive ) {
				if ( false !== strpos( $key_lower, $sensitive ) ) {
					$result[ $key ] = '***REDACTED***';
					continue 2;
				}
			}

			// Récursivement sanitizer les tableaux.
			if ( is_array( $value ) ) {
				$result[ $key ] = $this->sanitize_context( $value );
			} elseif ( is_object( $value ) ) {
				$result[ $key ] = get_class( $value );
			} else {
				$result[ $key ] = $value;
			}
		}

		return $result;
	}

	/**
	 * Écrit un message dans le log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level   Niveau de log.
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return void
	 */
	private function write_log( $level, $message, $context = array() ) {
		if ( ! $this->should_log( $level ) ) {
			return;
		}

		$formatted = $this->format_message( $level, $message, $context );

		// Utiliser error_log de PHP.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $formatted );

		/**
		 * Action déclenchée après l'écriture d'un log.
		 *
		 * Permet d'ajouter des destinations de log personnalisées.
		 *
		 * @param string $level     Niveau de log.
		 * @param string $message   Message loggé.
		 * @param array  $context   Contexte du log.
		 * @param string $formatted Message formaté.
		 */
		do_action( 'ws_form_db_after_log', $level, $message, $context, $formatted );
	}

	// =========================================================================
	// MÉTHODES PUBLIQUES DE LOGGING
	// =========================================================================

	/**
	 * Log un message de niveau DEBUG.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return void
	 */
	public function debug( $message, $context = array() ) {
		$this->write_log( self::DEBUG, $message, $context );
	}

	/**
	 * Log un message de niveau INFO.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return void
	 */
	public function info( $message, $context = array() ) {
		$this->write_log( self::INFO, $message, $context );
	}

	/**
	 * Log un message de niveau WARNING.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return void
	 */
	public function warning( $message, $context = array() ) {
		$this->write_log( self::WARNING, $message, $context );
	}

	/**
	 * Log un message de niveau ERROR.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return void
	 */
	public function error( $message, $context = array() ) {
		$this->write_log( self::ERROR, $message, $context );
	}

	// =========================================================================
	// MÉTHODES STATIQUES POUR UN ACCÈS RAPIDE
	// =========================================================================

	/**
	 * Log un message de niveau DEBUG (statique).
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return void
	 */
	public static function log_debug( $message, $context = array() ) {
		self::get_instance()->debug( $message, $context );
	}

	/**
	 * Log un message de niveau INFO (statique).
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return void
	 */
	public static function log_info( $message, $context = array() ) {
		self::get_instance()->info( $message, $context );
	}

	/**
	 * Log un message de niveau WARNING (statique).
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return void
	 */
	public static function log_warning( $message, $context = array() ) {
		self::get_instance()->warning( $message, $context );
	}

	/**
	 * Log un message de niveau ERROR (statique).
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message à logger.
	 * @param array  $context Contexte additionnel.
	 * @return void
	 */
	public static function log_error( $message, $context = array() ) {
		self::get_instance()->error( $message, $context );
	}

	// =========================================================================
	// MÉTHODES SPÉCIFIQUES AU PLUGIN
	// =========================================================================

	/**
	 * Log une tentative de connexion à la base de données.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host     Hôte de la base de données.
	 * @param string $database Nom de la base de données.
	 * @param bool   $success  Succès de la connexion.
	 * @param string $error    Message d'erreur (si échec).
	 * @return void
	 */
	public static function log_connection( $host, $database, $success, $error = '' ) {
		$level   = $success ? self::INFO : self::ERROR;
		$message = $success ? 'Connection successful' : 'Connection failed';

		$context = array(
			'host'     => $host,
			'database' => $database,
			'success'  => $success,
		);

		if ( ! $success && $error ) {
			$context['error'] = $error;
		}

		self::get_instance()->write_log( $level, $message, $context );
	}

	/**
	 * Log une insertion de données.
	 *
	 * @since 1.0.0
	 *
	 * @param string   $table       Nom de la table.
	 * @param int      $columns     Nombre de colonnes insérées.
	 * @param bool     $success     Succès de l'insertion.
	 * @param int|null $inserted_id ID de l'enregistrement inséré.
	 * @param string   $error       Message d'erreur (si échec).
	 * @return void
	 */
	public static function log_insertion( $table, $columns, $success, $inserted_id = null, $error = '' ) {
		$level   = $success ? self::INFO : self::ERROR;
		$message = $success ? 'Insert successful' : 'Insert failed';

		$context = array(
			'table'   => $table,
			'columns' => $columns,
			'success' => $success,
		);

		if ( $success && $inserted_id ) {
			$context['inserted_id'] = $inserted_id;
		}

		if ( ! $success && $error ) {
			$context['error'] = $error;
		}

		self::get_instance()->write_log( $level, $message, $context );
	}

	/**
	 * Log une soumission de formulaire.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $form_id     ID du formulaire WS Form.
	 * @param string $action_name Nom de l'action.
	 * @param bool   $success     Succès du traitement.
	 * @param string $details     Détails supplémentaires.
	 * @return void
	 */
	public static function log_submission( $form_id, $action_name, $success, $details = '' ) {
		$level   = $success ? self::INFO : self::WARNING;
		$message = $success ? 'Submission processed' : 'Submission failed';

		$context = array(
			'form_id'     => $form_id,
			'action_name' => $action_name,
			'success'     => $success,
		);

		if ( $details ) {
			$context['details'] = $details;
		}

		self::get_instance()->write_log( $level, $message, $context );
	}

	/**
	 * Log une erreur de validation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $field   Champ invalide.
	 * @param string $message Message d'erreur.
	 * @param mixed  $value   Valeur qui a échoué la validation (sera sanitizée).
	 * @return void
	 */
	public static function log_validation_error( $field, $message, $value = null ) {
		$context = array(
			'field'   => $field,
			'message' => $message,
		);

		if ( null !== $value ) {
			// Ne pas logger la valeur complète si elle est trop longue.
			if ( is_string( $value ) && strlen( $value ) > 100 ) {
				$context['value'] = substr( $value, 0, 100 ) . '...';
			} else {
				$context['value'] = $value;
			}
		}

		self::get_instance()->warning( 'Erreur de validation', $context );
	}

	// =========================================================================
	// CONFIGURATION
	// =========================================================================

	/**
	 * Active ou désactive le logging.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $enabled True pour activer.
	 * @return void
	 */
	public static function set_enabled( $enabled ) {
		update_option( self::OPTION_ENABLED, (bool) $enabled );

		// Réinitialiser l'instance pour prendre en compte le changement.
		self::$instance = null;
	}

	/**
	 * Définit le niveau de log minimum.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level Niveau (DEBUG, INFO, WARNING, ERROR).
	 * @return void
	 */
	public static function set_level( $level ) {
		$valid_levels = array( self::DEBUG, self::INFO, self::WARNING, self::ERROR );

		if ( in_array( $level, $valid_levels, true ) ) {
			update_option( self::OPTION_LEVEL, $level );

			// Réinitialiser l'instance pour prendre en compte le changement.
			self::$instance = null;
		}
	}

	/**
	 * Récupère la configuration actuelle du logger.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get_config() {
		$instance = self::get_instance();

		return array(
			'enabled' => $instance->enabled,
			'level'   => $instance->level,
		);
	}
}
