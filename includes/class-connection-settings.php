<?php
/**
 * Résolution des paramètres de connexion à la base externe.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Construit des paramètres de connexion validés, depuis wp-config.php ou l'action.
 *
 * Une connexion nommée garde le mot de passe hors des formulaires et de leurs exports :
 *
 *     define( 'WS_FORM_DB_CONNECTIONS', array(
 *         'crm' => array(
 *             'host'     => 'db.example.com',
 *             'port'     => 3306,
 *             'user'     => 'forms',
 *             'password' => '...',
 *             'database' => 'crm',
 *             'timeout'  => 5,          // Facultatif, en secondes.
 *             'sites'    => array( 2 ), // Facultatif, multisite : sites autorisés.
 *         ),
 *     ) );
 */
class WS_Form_DB_Connection_Settings {

	/**
	 * Constante qui déclare les connexions nommées.
	 *
	 * @var string
	 */
	const CONNECTIONS_CONSTANT = 'WS_FORM_DB_CONNECTIONS';

	/**
	 * Identifiant de l'action WS Form. Ne pas changer : les formulaires existants en dépendent.
	 *
	 * @var string
	 */
	const ACTION_ID = 'ws_form_db_external';

	/**
	 * Préfixe des meta keys de l'action.
	 *
	 * @var string
	 */
	const META_PREFIX = 'action_' . self::ACTION_ID . '_';

	/**
	 * Port MySQL par défaut.
	 *
	 * @var int
	 */
	const DEFAULT_PORT = 3306;

	/**
	 * Retourne les connexions nommées déclarées dans wp-config.php.
	 *
	 * @return array<string,array> Connexions brutes, indexées par nom.
	 */
	public static function get_named_connections() {
		if ( ! defined( self::CONNECTIONS_CONSTANT ) ) {
			return array();
		}

		$connections = constant( self::CONNECTIONS_CONSTANT );

		if ( ! is_array( $connections ) ) {
			return array();
		}

		$valid = array();

		foreach ( $connections as $name => $connection ) {
			if ( is_string( $name ) && '' !== $name && is_array( $connection ) && self::is_available_on_current_site( $connection ) ) {
				$valid[ $name ] = $connection;
			}
		}

		return $valid;
	}

	/**
	 * Indique si une connexion nommée est réservée à d'autres sites du réseau.
	 *
	 * La clé facultative « sites » liste les ID de sites autorisés. Sans elle, la connexion est partout disponible.
	 *
	 * @param array $connection Connexion brute.
	 *
	 * @return bool
	 */
	private static function is_available_on_current_site( $connection ) {
		if ( ! isset( $connection['sites'] ) || ! is_multisite() ) {
			return true;
		}

		return in_array( get_current_blog_id(), array_map( 'absint', (array) $connection['sites'] ), true );
	}

	/**
	 * Retourne les noms des connexions déclarées, sans aucun identifiant.
	 *
	 * @return string[]
	 */
	public static function get_connection_names() {
		return array_keys( self::get_named_connections() );
	}

	/**
	 * Construit les paramètres depuis la configuration d'une action WS Form.
	 *
	 * @param array $action_meta Meta de l'action (clés préfixées).
	 *
	 * @return array|WP_Error Paramètres validés.
	 */
	public static function from_action_meta( $action_meta ) {
		$named = self::get_meta_value( $action_meta, 'connection' );

		if ( '' !== $named ) {
			return self::from_name( $named );
		}

		return self::normalize(
			array(
				'host'     => self::get_meta_value( $action_meta, 'host' ),
				'port'     => self::get_meta_value( $action_meta, 'port' ),
				'user'     => self::get_meta_value( $action_meta, 'user' ),
				'password' => isset( $action_meta[ self::META_PREFIX . 'password' ] ) ? (string) $action_meta[ self::META_PREFIX . 'password' ] : '',
				'database' => self::get_meta_value( $action_meta, 'name' ),
			)
		);
	}

	/**
	 * Construit les paramètres depuis une requête AJAX de l'éditeur.
	 *
	 * Le nonce et les droits doivent avoir été vérifiés par l'appelant.
	 *
	 * @param array $request Données POST, non échappées.
	 *
	 * @return array|WP_Error Paramètres validés.
	 */
	public static function from_request( $request ) {
		$named = isset( $request['connection'] ) ? sanitize_text_field( wp_unslash( $request['connection'] ) ) : '';

		if ( '' !== $named ) {
			return self::from_name( $named );
		}

		return self::normalize(
			array(
				'host'     => isset( $request['host'] ) ? sanitize_text_field( wp_unslash( $request['host'] ) ) : '',
				'port'     => isset( $request['port'] ) ? sanitize_text_field( wp_unslash( $request['port'] ) ) : '',
				'user'     => isset( $request['user'] ) ? sanitize_text_field( wp_unslash( $request['user'] ) ) : '',
				// Le mot de passe peut contenir n'importe quel caractère : il n'est jamais affiché ni interpolé.
				'password' => isset( $request['password'] ) ? (string) wp_unslash( $request['password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'database' => isset( $request['database'] ) ? sanitize_text_field( wp_unslash( $request['database'] ) ) : '',
			)
		);
	}

	/**
	 * Construit les paramètres d'une connexion nommée.
	 *
	 * @param string $name Nom de la connexion.
	 *
	 * @return array|WP_Error
	 */
	public static function from_name( $name ) {
		$connections = self::get_named_connections();

		if ( ! isset( $connections[ $name ] ) ) {
			return new WP_Error(
				'ws_form_db_unknown_connection',
				sprintf(
					/* translators: 1: connection name, 2: constant name */
					__( 'Connection “%1$s” is not declared in %2$s.', 'wsform-to-externaldb' ),
					$name,
					self::CONNECTIONS_CONSTANT
				)
			);
		}

		$connection = wp_parse_args(
			$connections[ $name ],
			array(
				'host'     => '',
				'port'     => self::DEFAULT_PORT,
				'user'     => '',
				'password' => '',
				'database' => '',
				'timeout'  => WS_Form_DB_External_Wpdb::DEFAULT_CONNECT_TIMEOUT,
			)
		);

		return self::normalize( $connection );
	}

	/**
	 * Nettoie et valide un jeu de paramètres.
	 *
	 * @param array $raw Paramètres bruts (host, port, user, password, database, timeout facultatif).
	 *
	 * @return array|WP_Error
	 */
	private static function normalize( $raw ) {
		$port = absint( $raw['port'] );

		$params = array(
			'host'     => trim( (string) $raw['host'] ),
			'port'     => $port ? $port : self::DEFAULT_PORT,
			'user'     => trim( (string) $raw['user'] ),
			'password' => (string) $raw['password'],
			'database' => trim( (string) $raw['database'] ),
			// Réglable seulement dans wp-config.php : un champ dans l'action partirait
			// avec l'export du formulaire et ferait attendre le visiteur.
			'timeout'  => isset( $raw['timeout'] ) ? max( 1, absint( $raw['timeout'] ) ) : WS_Form_DB_External_Wpdb::DEFAULT_CONNECT_TIMEOUT,
		);

		$validation = WS_Form_DB_Security::validate_connection_params( $params );

		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		return $params;
	}

	/**
	 * Lit une meta simple de l'action.
	 *
	 * @param array  $action_meta Meta de l'action.
	 * @param string $key         Clé sans préfixe.
	 *
	 * @return string
	 */
	private static function get_meta_value( $action_meta, $key ) {
		$full_key = self::META_PREFIX . $key;

		return isset( $action_meta[ $full_key ] ) ? sanitize_text_field( (string) $action_meta[ $full_key ] ) : '';
	}
}
