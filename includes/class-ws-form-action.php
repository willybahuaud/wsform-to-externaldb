<?php
/**
 * Déclaration de l'action personnalisée WS Form.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'WS_Form_Action' ) && ! class_exists( 'WS_Form_DB_WS_Form_Action' ) ) {

	/**
	 * Action WS Form « Insérer dans la base de données ».
	 */
	class WS_Form_DB_WS_Form_Action extends WS_Form_Action {

		/**
		 * Identifiant interne de l'action.
		 *
		 * @var string
		 */
		public $id = 'ws_form_db_external';

		/**
		 * Libellé de l'action.
		 *
		 * @var string
		 */
		public $label = '';

		/**
		 * Libellé du bouton d'ajout de l'action.
		 *
		 * @var string
		 */
		public $label_action = '';

		/**
		 * Indique si plusieurs instances de l'action sont autorisées.
		 *
		 * @var bool
		 */
		public $multiple = true;

		/**
		 * Événements supportés.
		 *
		 * @var array
		 */
		public $events = array( 'submit' );

		/**
		 * Priorité d'exécution.
		 *
		 * @var int
		 */
		public $priority = 100;

		/**
		 * L'action peut être repostée.
		 *
		 * @var bool
		 */
		public $can_repost = false;

		/**
		 * Empêche l'ajout automatique lors de la création d'un formulaire.
		 *
		 * @var bool
		 */
		public $form_add = false;

		/**
		 * Nécessite WS Form Pro.
		 *
		 * @var bool
		 */
		public $pro_required = false;

		/**
		 * Indique si l'action est configurée.
		 *
		 * @var bool
		 */
		public $configured = true;

		/**
		 * Instance unique.
		 *
		 * @var WS_Form_DB_WS_Form_Action|null
		 */
		protected static $instance = null;

		/**
		 * Constructeur privé (singleton).
		 */
		private function __construct() {
			add_action( 'init', array( $this, 'init' ) );
			add_filter( 'wsf_config_meta_keys', array( $this, 'config_meta_keys' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}

		/**
		 * Récupère l'instance unique.
		 *
		 * @return self
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Initialise l'action (labels + enregistrement auprès de WS Form).
		 *
		 * @return void
		 */
		public function init() {
			$this->label        = esc_html__( 'External database', 'wsform-to-externaldb' );
			$this->label_action = esc_html__( 'Insert into database', 'wsform-to-externaldb' );

			parent::register( $this );
		}

		/**
		 * Ajoute les meta keys (champs de configuration) de l'action.
		 *
		 * @param array $meta_keys Meta keys existantes.
		 * @param int   $form_id   Identifiant du formulaire.
		 *
		 * @return array
		 */
		public function config_meta_keys( $meta_keys = array(), $form_id = 0 ) {
			$action_id = $this->id;

			$config_meta_keys = array(

				// Connexion nommée déclarée dans wp-config.php, ou saisie manuelle.
				'action_' . $action_id . '_connection'    => array(
					'label'   => esc_html__( 'Connection', 'wsform-to-externaldb' ),
					'type'    => 'select',
					'options' => $this->get_connection_options(),
					'default' => '',
					'help'    => esc_html__( 'A connection declared in wp-config.php (WS_FORM_DB_CONNECTIONS constant) keeps the password out of the WordPress database and out of form exports.', 'wsform-to-externaldb' ),
				),

				'action_' . $action_id . '_host'          => $this->manual_setting(
					array(
						'label'   => esc_html__( 'Host', 'wsform-to-externaldb' ),
						'type'    => 'text',
						'help'    => esc_html__( 'MySQL or MariaDB server address, without the port.', 'wsform-to-externaldb' ),
						'default' => '',
					)
				),

				'action_' . $action_id . '_user'          => $this->manual_setting(
					array(
						'label'   => esc_html__( 'User', 'wsform-to-externaldb' ),
						'type'    => 'text',
						'help'    => esc_html__( 'Grant it only the INSERT privilege on the target table.', 'wsform-to-externaldb' ),
						'default' => '',
					)
				),

				'action_' . $action_id . '_password'      => $this->manual_setting(
					array(
						'label'   => esc_html__( 'Password', 'wsform-to-externaldb' ),
						'type'    => 'password',
						'help'    => esc_html__( 'Stored in plain text with the form, and included in its exports and copies. Prefer a connection declared in wp-config.php.', 'wsform-to-externaldb' ),
						'default' => '',
					)
				),

				'action_' . $action_id . '_port'          => $this->manual_setting(
					array(
						'label'   => esc_html__( 'Port', 'wsform-to-externaldb' ),
						'type'    => 'number',
						'help'    => esc_html__( '3306 by default.', 'wsform-to-externaldb' ),
						'default' => '3306',
						'min'     => 1,
						'max'     => 65535,
					)
				),

				'action_' . $action_id . '_name'          => $this->manual_setting(
					array(
						'label'   => esc_html__( 'Database', 'wsform-to-externaldb' ),
						'type'    => 'text',
						'default' => '',
					)
				),

				// Boutons d'action (HTML personnalisé).
				'action_' . $action_id . '_buttons'       => array(
					'label' => '',
					'type'  => 'html',
					'html'  => $this->get_buttons_markup(),
				),

				// Table cible (select dynamique).
				'action_' . $action_id . '_table'         => array(
					'label'         => esc_html__( 'Target table', 'wsform-to-externaldb' ),
					'type'          => 'select',
					'options'       => array(
						array(
							'value' => '',
							'text'  => esc_html__( '-- Load tables --', 'wsform-to-externaldb' ),
						),
					),
					'options_blank' => esc_html__( 'Select…', 'wsform-to-externaldb' ),
					'default'       => '',
					'help'          => esc_html__( 'Click “Load tables” to fill this list.', 'wsform-to-externaldb' ),
				),

				// Stockage des tables disponibles (JSON caché).
				'action_' . $action_id . '_tables_cache'  => array(
					'label'   => '',
					'type'    => 'textarea',
					'default' => '',
				),

				// Stockage des colonnes de la table (JSON caché).
				'action_' . $action_id . '_columns_cache' => array(
					'label'   => '',
					'type'    => 'textarea',
					'default' => '',
				),

				// Zone d'affichage des colonnes et mapping (HTML).
				'action_' . $action_id . '_mapping_ui'    => array(
					'label' => '',
					'type'  => 'html',
					'html'  => $this->get_mapping_ui_markup(),
				),

				// Stockage du mapping (JSON, champ caché).
				'action_' . $action_id . '_mapping'       => array(
					'label'   => '',
					'type'    => 'textarea',
					'default' => '',
				),
			);

			return array_merge( $meta_keys, $config_meta_keys );
		}

		/**
		 * Décrit les réglages de l'action (utilisé dans l'éditeur WS Form).
		 *
		 * @return stdClass
		 */
		public function get_action_settings() {
			$action_id = $this->id;

			$settings = array(
				'meta_keys' => array(
					'action_' . $action_id . '_connection',
					'action_' . $action_id . '_host',
					'action_' . $action_id . '_user',
					'action_' . $action_id . '_password',
					'action_' . $action_id . '_port',
					'action_' . $action_id . '_name',
					'action_' . $action_id . '_buttons',
					'action_' . $action_id . '_table',
					'action_' . $action_id . '_tables_cache',
					'action_' . $action_id . '_columns_cache',
					'action_' . $action_id . '_mapping_ui',
					'action_' . $action_id . '_mapping',
				),
			);

			$settings                   = parent::get_settings_wrapper( $settings );
			$settings->label            = $this->label;
			$settings->label_action     = $this->label_action;
			$settings->multiple         = $this->multiple;
			$settings->events           = $this->events;
			$settings->can_repost       = $this->can_repost;
			$settings->configured       = $this->configured;
			$settings->pro_required     = $this->pro_required;
			$settings->form_add         = $this->form_add;
			$settings->priority         = $this->priority;
			$settings->woocommerce_only = false;

			return $settings;
		}

		/**
		 * Insère la soumission dans la base externe.
		 *
		 * En cas d'échec, le visiteur n'est pas bloqué et ne voit aucun détail
		 * technique : la soumission reste dans WS Form et l'administrateur est
		 * prévenu par WS_Form_DB_Failure_Notifier.
		 *
		 * @param mixed $form   Objet formulaire WS Form.
		 * @param mixed $submit Objet soumission WS Form.
		 * @param array $config Configuration de l'action.
		 * @param int   $count  Rang de l'exécution dans la requête (commence à 1).
		 *
		 * @return bool
		 */
		public function post( $form, $submit, $config, $count = 1 ) {
			// WS Form peut rappeler l'action pour la même soumission : une seule insertion.
			if ( $count > 1 ) {
				return true;
			}

			$form_id = ( is_object( $form ) && isset( $form->id ) ) ? absint( $form->id ) : 0;
			$meta    = ( isset( $config['meta'] ) && is_array( $config['meta'] ) ) ? $config['meta'] : array();

			$handler = new WS_Form_DB_Form_Submission_Handler();
			$result  = $handler->handle_submission( $meta, $form, $submit );

			if ( is_wp_error( $result ) ) {
				WS_Form_DB_Failure_Notifier::record( $form_id, $submit, $result );
				return true;
			}

			WS_Form_DB_Logger::log_submission( $form_id, $this->id, true );

			return true;
		}

		/**
		 * Liste les connexions proposées dans l'éditeur.
		 *
		 * Seuls les noms sortent de wp-config.php, jamais les identifiants.
		 *
		 * @return array[]
		 */
		protected function get_connection_options() {
			$options = array(
				array(
					'value' => '',
					'text'  => esc_html__( 'Manual settings', 'wsform-to-externaldb' ),
				),
			);

			foreach ( WS_Form_DB_Connection_Settings::get_connection_names() as $name ) {
				$options[] = array(
					'value' => $name,
					'text'  => $name,
				);
			}

			return $options;
		}

		/**
		 * Affiche un réglage de connexion seulement en saisie manuelle.
		 *
		 * @param array $setting Définition du réglage.
		 *
		 * @return array
		 */
		protected function manual_setting( $setting ) {
			$setting['condition'] = array(
				array(
					'logic'      => '==',
					'meta_key'   => 'action_' . $this->id . '_connection', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => '', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				),
			);

			return $setting;
		}

		/**
		 * Génère le HTML des boutons d'action.
		 *
		 * @return string
		 */
		protected function get_buttons_markup() {
			ob_start();
			?>
			<div class="wsf-db-action-buttons" data-wsf-db-buttons>
				<button type="button" class="wsf-button wsf-button-small" data-wsf-db-test-connection>
					<?php echo esc_html__( 'Test connection', 'wsform-to-externaldb' ); ?>
				</button>
				<button type="button" class="wsf-button wsf-button-small" data-wsf-db-load-tables disabled>
					<?php echo esc_html__( 'Load tables', 'wsform-to-externaldb' ); ?>
				</button>
				<button type="button" class="wsf-button wsf-button-small" data-wsf-db-analyze-table disabled>
					<?php echo esc_html__( 'Analyze table', 'wsform-to-externaldb' ); ?>
					</button>
				<div class="wsf-db-connection-status" data-wsf-db-status></div>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Génère le HTML de l'interface de mapping.
		 *
		 * @return string
		 */
		protected function get_mapping_ui_markup() {
			ob_start();
			?>
		<div class="wsf-db-mapping-ui" data-wsf-db-mapping-ui style="display: none;">
			<div class="wsf-db-columns-list" data-wsf-db-columns></div>
			<div class="wsf-db-mapping-container" data-wsf-db-mapping-rows>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}

		/**
		 * Charge les assets nécessaires dans l'interface WS Form.
		 *
		 * @return void
		 */
		public function enqueue_admin_assets() {
			if ( ! is_admin() ) {
				return;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			if ( ! $screen || false === strpos( $screen->id, 'ws-form' ) ) {
				return;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$form_id = isset( $_GET['form_id'] ) ? absint( $_GET['form_id'] ) : 0;

			$handle = 'ws-form-db-action-config';
			$script = WS_FORM_DB_PLUGIN_URL . 'admin/js/ws-form-action-config.js';
			$style  = WS_FORM_DB_PLUGIN_URL . 'admin/css/ws-form-action-config.css';

			$asset = array(
				'dependencies' => array( 'jquery' ),
				'version'      => WS_FORM_DB_VERSION,
			);

			$asset_file = WS_FORM_DB_PLUGIN_DIR . 'admin/js/ws-form-action-config.asset.php';

			if ( file_exists( $asset_file ) ) {
				$asset_from_file = include $asset_file;
				if ( is_array( $asset_from_file ) ) {
					$asset = wp_parse_args( $asset_from_file, $asset );
				}
			}

			wp_enqueue_script(
				$handle,
				$script,
				$asset['dependencies'],
				$asset['version'],
				true
			);

			wp_localize_script(
				$handle,
				'wsFormDbConfig',
				array(
					'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
					'nonce'    => wp_create_nonce( 'ws_form_db_action_nonce' ),
					'formId'   => $form_id,
					// Traces console seulement quand SCRIPT_DEBUG est actif.
					'debug'    => defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG,
					'actionId' => $this->id,
					'i18n'     => array(
						'testSuccess'       => esc_html__( 'Connection successful!', 'wsform-to-externaldb' ),
						'testError'         => esc_html__( 'Connection failed.', 'wsform-to-externaldb' ),
						'tablesLoaded'      => esc_html__( 'Tables loaded.', 'wsform-to-externaldb' ),
						'tablesError'       => esc_html__( 'Could not load the tables.', 'wsform-to-externaldb' ),
						'analyzeSuccess'    => esc_html__( 'Table analyzed.', 'wsform-to-externaldb' ),
						'analyzeError'      => esc_html__( 'Analysis failed.', 'wsform-to-externaldb' ),
						'noColumns'         => esc_html__( 'No mappable column found.', 'wsform-to-externaldb' ),
						'loading'           => esc_html__( 'Loading…', 'wsform-to-externaldb' ),
						'selectTableFirst'  => esc_html__( 'Please select a table.', 'wsform-to-externaldb' ),
						'connectionMissing' => esc_html__( 'Incomplete connection settings.', 'wsform-to-externaldb' ),
						'selectTable'       => esc_html__( '-- Select a table --', 'wsform-to-externaldb' ),
						'addMapping'        => esc_html__( 'Add mapping', 'wsform-to-externaldb' ),
						'selectColumn'      => esc_html__( '-- Column --', 'wsform-to-externaldb' ),
						'valuePlaceholder'  => esc_html__( '#field(123), #email, text...', 'wsform-to-externaldb' ),
						'alreadyUsed'       => esc_html__( 'already used', 'wsform-to-externaldb' ),
						'mappingIntro'      => esc_html__( 'Use WS Form variables: #field(ID), #email, #blog_time...', 'wsform-to-externaldb' ),
						'mappingTitle'      => esc_html__( 'Column mapping', 'wsform-to-externaldb' ),
						'removeRow'         => esc_html__( 'Remove', 'wsform-to-externaldb' ),
						'variables'         => esc_html__( 'Variables', 'wsform-to-externaldb' ),
						'requestError'      => esc_html__( 'The request failed.', 'wsform-to-externaldb' ),
						'variablesFallback' => __( "WS Form variables: #field(ID), #email, #blog_time, #user_id...\n\nSee the WS Form documentation for the full list.", 'wsform-to-externaldb' ),
					),
				)
			);

			wp_enqueue_style(
				$handle,
				$style,
				array(),
				WS_FORM_DB_VERSION
			);
		}
	}
}
