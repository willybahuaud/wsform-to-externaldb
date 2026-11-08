<?php
/**
 * Mises à jour depuis les releases GitHub.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mises à jour depuis les releases GitHub, via l'en-tête Update URI.
 *
 * Seule une release avec un tag vX.Y.Z et l'archive <slug>-X.Y.Z.zip, servie par
 * github.com, est proposée. Le manifeste <slug>-X.Y.Z.json donne les versions requises.
 * Appels anonymes : le dépôt doit être public.
 */
class WS_Form_DB_Updater {

	/**
	 * Durée du cache d'une réponse valide, en secondes.
	 *
	 * @var int
	 */
	const CACHE_DURATION = 12 * HOUR_IN_SECONDS;

	/**
	 * Durée du cache d'un échec (404, limite atteinte, délai dépassé).
	 *
	 * L'API anonyme est limitée à 60 requêtes par heure et par IP : sur un
	 * hébergement mutualisé, réessayer à chaque chargement l'épuiserait.
	 *
	 * @var int
	 */
	const FAILURE_CACHE_DURATION = HOUR_IN_SECONDS;

	/**
	 * Délai des requêtes vers GitHub, en secondes.
	 *
	 * @var int
	 */
	const REQUEST_TIMEOUT = 5;

	/**
	 * Propriétaire du dépôt GitHub.
	 *
	 * @var string
	 */
	private $owner;

	/**
	 * Nom du dépôt GitHub.
	 *
	 * @var string
	 */
	private $repository;

	/**
	 * Chemin absolu du fichier principal du plugin.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Version installée.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Release déjà lue pendant la requête courante.
	 *
	 * @var array|false|null
	 */
	private $release = null;

	/**
	 * Configure l'updater.
	 *
	 * @param array $settings {
	 *     Réglages.
	 *
	 *     @type string $owner       Propriétaire du dépôt GitHub.
	 *     @type string $repository  Nom du dépôt.
	 *     @type string $plugin_file Chemin absolu du fichier principal.
	 *     @type string $version     Version installée.
	 * }
	 */
	public function __construct( $settings ) {
		$this->owner       = $settings['owner'];
		$this->repository  = $settings['repository'];
		$this->plugin_file = $settings['plugin_file'];
		$this->version     = $settings['version'];
	}

	/**
	 * Branche les filtres WordPress.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'update_plugins_github.com', array( $this, 'filter_update' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'filter_plugin_information' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'filter_source_directory' ), 10, 4 );
	}

	/**
	 * Décrit la dernière release pour le système de mise à jour.
	 *
	 * @param array|false $update      Réponse d'un autre filtre, ou false.
	 * @param array       $plugin_data En-têtes du plugin.
	 * @param string      $plugin_file Plugin concerné (dossier/fichier.php).
	 *
	 * @return array|false
	 */
	public function filter_update( $update, $plugin_data, $plugin_file ) {
		// Le filtre est partagé par tous les plugins hébergés sur github.com.
		if ( $this->get_basename() !== $plugin_file ) {
			return $update;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $update;
		}

		// WordPress compare lui-même les versions et range le résultat en « mise à
		// jour disponible » ou en « à jour ». Dans les deux cas, l'interrupteur des
		// mises à jour automatiques s'affiche.
		return array(
			'slug'         => $this->get_slug(),
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'tested'       => $release['tested'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
		);
	}

	/**
	 * Remplit la fenêtre « Voir les détails ».
	 *
	 * @param false|object|array $result Résultat d'un autre filtre.
	 * @param string             $action Action demandée à l'API.
	 * @param object             $args   Arguments, dont le slug.
	 *
	 * @return false|object|array
	 */
	public function filter_plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $this->get_slug() !== $args->slug ) {
			return $result;
		}

		$release = $this->get_release();

		if ( ! $release ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Nom traduit lu dans l'en-tête : à ce stade, les traductions sont chargées.
		$plugin_data = get_plugin_data( $this->plugin_file, false, true );

		return (object) array(
			'name'          => $plugin_data['Name'],
			'slug'          => $this->get_slug(),
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . rawurlencode( $this->owner ) . '">' . esc_html( $this->owner ) . '</a>',
			'homepage'      => $this->get_repository_url(),
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'tested'        => $this->get_tested_version( $release['tested'] ),
			'last_updated'  => $release['published_at'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => $this->format_notes( $release['notes'] ),
			),
		);
	}

	/**
	 * Retourne la version testée à présenter à la fenêtre des détails.
	 *
	 * « Tested up to: 7.1 » couvre toute la branche, mais cette fenêtre compare les versions
	 * telles quelles : sans ce relais, elle préviendrait à tort sous WordPress 7.1.4.
	 *
	 * @param string $tested Version déclarée par la release.
	 *
	 * @return string
	 */
	private function get_tested_version( $tested ) {
		$wordpress_version = get_bloginfo( 'version' );

		return 0 === strpos( $wordpress_version . '.', $tested . '.' ) ? $wordpress_version : $tested;
	}

	/**
	 * Donne au dossier extrait le nom du dossier installé.
	 *
	 * L'archive contient déjà un dossier <slug>/, mais un site peut avoir
	 * installé le plugin sous un autre nom de dossier.
	 *
	 * @param string      $source        Dossier extrait.
	 * @param string      $remote_source Dossier de travail de l'extraction.
	 * @param WP_Upgrader $upgrader      Instance de l'upgrader.
	 * @param array       $hook_extra    Contexte, dont le plugin mis à jour.
	 *
	 * @return string|WP_Error
	 */
	public function filter_source_directory( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		if ( ! isset( $hook_extra['plugin'] ) || $this->get_basename() !== $hook_extra['plugin'] ) {
			return $source;
		}

		$expected = trailingslashit( $remote_source ) . dirname( $this->get_basename() );

		if ( untrailingslashit( $source ) === $expected ) {
			return $source;
		}

		if ( $wp_filesystem && $wp_filesystem->move( $source, $expected ) ) {
			return trailingslashit( $expected );
		}

		return new WP_Error( 'ws_form_db_update_rename_failed', __( 'Could not rename the plugin folder during the update.', 'wsform-to-externaldb' ) );
	}

	/**
	 * Lit la dernière release, depuis le cache ou GitHub.
	 *
	 * @return array|false Release normalisée, ou false si indisponible.
	 */
	private function get_release() {
		if ( null !== $this->release ) {
			return $this->release;
		}

		$cache_key = 'ws_form_db_release_' . md5( $this->owner . '/' . $this->repository );
		$cached    = get_site_transient( $cache_key );

		if ( is_array( $cached ) ) {
			$this->release = empty( $cached['failed'] ) ? $cached : false;
			return $this->release;
		}

		$release = $this->fetch_release();

		if ( false === $release ) {
			set_site_transient( $cache_key, array( 'failed' => true ), self::FAILURE_CACHE_DURATION );
		} else {
			set_site_transient( $cache_key, $release, self::CACHE_DURATION );
		}

		$this->release = $release;

		return $release;
	}

	/**
	 * Interroge GitHub et valide la release.
	 *
	 * @return array|false
	 */
	private function fetch_release() {
		$data = $this->request_json( sprintf( 'https://api.github.com/repos/%s/%s/releases/latest', rawurlencode( $this->owner ), rawurlencode( $this->repository ) ) );

		if ( ! is_array( $data ) || empty( $data['tag_name'] ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return false;
		}

		$version = $this->parse_version( $data['tag_name'] );
		$package = $this->find_package_url( $data, $version );

		if ( '' === $version || '' === $package ) {
			return false;
		}

		return array_merge(
			array(
				'version'      => $version,
				'package'      => $package,
				'url'          => isset( $data['html_url'] ) ? esc_url_raw( $data['html_url'] ) : $this->get_repository_url(),
				'notes'        => isset( $data['body'] ) ? (string) $data['body'] : '',
				'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			),
			$this->fetch_requirements( $data, $version )
		);
	}

	/**
	 * Lit les versions requises dans le manifeste <slug>-X.Y.Z.json joint à la release.
	 *
	 * Les en-têtes du plugin installé décrivent l'ancienne version, pas celle qui arrive.
	 * Sans manifeste, la release reste proposée, sans versions requises.
	 *
	 * @param array  $data    Release GitHub.
	 * @param string $version Version annoncée par le tag.
	 *
	 * @return array{requires:string,requires_php:string,tested:string}
	 */
	private function fetch_requirements( $data, $version ) {
		$url          = $this->find_asset_url( $data, $this->get_slug() . '-' . $version . '.json' );
		$manifest     = '' === $url ? null : $this->request_json( $url );
		$requirements = array();

		foreach ( array( 'requires', 'requires_php', 'tested' ) as $key ) {
			$requirements[ $key ] = isset( $manifest[ $key ] ) && is_string( $manifest[ $key ] ) ? $manifest[ $key ] : '';
		}

		return $requirements;
	}

	/**
	 * Cherche l'archive <slug>-X.Y.Z.zip parmi les fichiers de la release.
	 *
	 * @param array  $data    Release GitHub.
	 * @param string $version Version annoncée par le tag.
	 *
	 * @return string URL de téléchargement, ou chaîne vide.
	 */
	private function find_package_url( $data, $version ) {
		return $this->find_asset_url( $data, $this->get_slug() . '-' . $version . '.zip' );
	}

	/**
	 * Cherche un fichier joint à la release, par son nom exact, servi par github.com.
	 *
	 * @param array  $data Release GitHub.
	 * @param string $name Nom du fichier.
	 *
	 * @return string URL de téléchargement, ou chaîne vide.
	 */
	private function find_asset_url( $data, $name ) {
		foreach ( isset( $data['assets'] ) && is_array( $data['assets'] ) ? $data['assets'] : array() as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && $name === $asset['name'] && $this->is_github_url( $asset['browser_download_url'] ) ) {
				return esc_url_raw( $asset['browser_download_url'] );
			}
		}

		return '';
	}

	/**
	 * Vérifie qu'un fichier se télécharge depuis github.com, en HTTPS.
	 *
	 * @param mixed $url URL à vérifier.
	 *
	 * @return bool
	 */
	private function is_github_url( $url ) {
		return is_string( $url ) && 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && 'github.com' === wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Extrait une version X.Y.Z d'un tag vX.Y.Z.
	 *
	 * @param string $tag Tag de la release.
	 *
	 * @return string Version, ou chaîne vide si le tag n'est pas une version.
	 */
	private function parse_version( $tag ) {
		return preg_match( '/^v?(\d+\.\d+\.\d+)$/', (string) $tag, $match ) ? $match[1] : '';
	}

	/**
	 * Lance une requête GET vers GitHub et décode sa réponse JSON.
	 *
	 * @param string $url URL.
	 *
	 * @return array|null Réponse décodée, ou null pour un 404, un 403 de limite ou un délai dépassé.
	 */
	private function request_json( $url ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => $this->repository . '/' . $this->version,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Met en forme les notes de release pour la fenêtre de détails.
	 *
	 * @param string $notes Notes de release, en Markdown.
	 *
	 * @return string HTML.
	 */
	private function format_notes( $notes ) {
		if ( '' === trim( $notes ) ) {
			return '<p>' . esc_html__( 'No release notes.', 'wsform-to-externaldb' ) . '</p>';
		}

		return WS_Form_DB_Release_Notes::to_html( $notes );
	}

	/**
	 * Retourne le plugin sous la forme dossier/fichier.php.
	 *
	 * @return string
	 */
	private function get_basename() {
		return plugin_basename( $this->plugin_file );
	}

	/**
	 * Retourne le slug du plugin, c'est-à-dire le nom du dépôt.
	 *
	 * @return string
	 */
	private function get_slug() {
		return $this->repository;
	}

	/**
	 * Retourne l'URL du dépôt.
	 *
	 * @return string
	 */
	private function get_repository_url() {
		return 'https://github.com/' . rawurlencode( $this->owner ) . '/' . rawurlencode( $this->repository );
	}
}
