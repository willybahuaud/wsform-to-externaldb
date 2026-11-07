<?php
/**
 * Instance wpdb dédiée à une base externe.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . WPINC . '/class-wpdb.php';

/**
 * Variante de wpdb qui échoue vite et sans bruit.
 *
 * Le wpdb natif attend jusqu'à default_socket_timeout (60 s par défaut) quand
 * l'hôte ne répond pas, puis appelle bail() : avec WP_DEBUG, la requête du
 * visiteur meurt sur la page « Erreur de connexion à la base de données »,
 * qui affiche l'hôte. Une base externe en panne ne doit jamais faire ça.
 */
class WS_Form_DB_External_Wpdb extends wpdb {

	/**
	 * Délai de connexion par défaut, en secondes.
	 *
	 * @var int
	 */
	const DEFAULT_CONNECT_TIMEOUT = 5;

	/**
	 * Délai de connexion demandé pour cette instance, en secondes.
	 *
	 * @var int
	 */
	private $connect_timeout = self::DEFAULT_CONNECT_TIMEOUT;

	/**
	 * Mémorise le délai puis se connecte.
	 *
	 * Le délai doit être connu avant parent::__construct(), qui se connecte aussitôt.
	 *
	 * @param string $dbuser          Utilisateur.
	 * @param string $dbpassword      Mot de passe.
	 * @param string $dbname          Base.
	 * @param string $dbhost          Hôte, avec le port s'il diffère de 3306.
	 * @param int    $connect_timeout Délai de connexion en secondes.
	 */
	public function __construct( $dbuser, $dbpassword, $dbname, $dbhost, $connect_timeout = self::DEFAULT_CONNECT_TIMEOUT ) {
		$this->connect_timeout = max( 1, absint( $connect_timeout ) );

		parent::__construct( $dbuser, $dbpassword, $dbname, $dbhost );
	}

	/**
	 * Ouvre la connexion avec un délai court et sans jamais interrompre la requête.
	 *
	 * Le paramètre est ignoré : une base externe ne doit jamais faire échouer
	 * la page entière.
	 *
	 * @param bool $allow_bail Ignoré.
	 *
	 * @return bool
	 */
	public function db_connect( $allow_bail = false ) {
		// Le constructeur parent active show_errors() en debug avant la connexion.
		$this->hide_errors();

		/**
		 * Filtre le délai de connexion à la base externe.
		 *
		 * @param int    $timeout Délai en secondes : celui de la connexion nommée, sinon 5.
		 * @param string $host    Hôte visé.
		 */
		$timeout = absint( apply_filters( 'ws_form_db_connect_timeout', $this->connect_timeout, $this->dbhost ) );

		// mysqlnd lit default_socket_timeout au moment de la connexion. wpdb crée
		// son handle lui-même, on ne peut donc pas passer MYSQLI_OPT_CONNECT_TIMEOUT.
		$previous_timeout = ini_get( 'default_socket_timeout' );
		ini_set( 'default_socket_timeout', (string) max( 1, $timeout ) ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Restauré juste après la connexion.

		// En debug, wpdb laisse passer le warning de mysqli : il casserait la
		// réponse JSON de WS Form. L'erreur reste disponible dans last_error.
		$previous_reporting = error_reporting( error_reporting() & ~E_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting, PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall -- Restauré juste après la connexion.

		$connected = parent::db_connect( false );

		error_reporting( $previous_reporting ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting, PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall -- Restauré juste après la connexion.
		ini_set( 'default_socket_timeout', $previous_timeout ); // phpcs:ignore WordPress.PHP.IniSet.Risky, Squiz.PHP.DiscouragedFunctions.Discouraged -- Restauré juste après la connexion.

		return $connected;
	}

	/**
	 * Identifie la base visée, pour les clés de cache.
	 *
	 * @return string
	 */
	public function get_cache_scope() {
		return md5( $this->dbhost . '|' . $this->dbname );
	}

	/**
	 * Neutralise l'arrêt de la requête (base introuvable, etc.).
	 *
	 * @param string $message    Message d'erreur.
	 * @param string $error_code Code d'erreur.
	 *
	 * @return false
	 */
	public function bail( $message, $error_code = '500' ) {
		// Le message natif est une page d'aide complète : seul le titre est utile.
		$lines            = array_filter( array_map( 'trim', explode( "\n", wp_strip_all_tags( $message ) ) ) );
		$this->last_error = (string) reset( $lines );

		return false;
	}
}
