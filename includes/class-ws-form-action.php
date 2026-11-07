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
		public $id = WS_Form_DB_Connection_Settings::ACTION_ID;

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
			add_filter( 'wsf_action_pre_post', array( $this, 'strip_password_from_submit' ), 10, 3 );
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
		 * Déclare les réglages de l'action auprès de WS Form.
		 *
		 * @param array $meta_keys Meta keys existantes.
		 * @param int   $form_id   Identifiant du formulaire.
		 *
		 * @return array
		 */
		public function config_meta_keys( $meta_keys = array(), $form_id = 0 ) {
			return array_merge( $meta_keys, $this->get_meta_key_definitions() );
		}

		/**
		 * Définit les réglages de l'action, dans l'ordre d'affichage.
		 *
		 * @return array<string,array>
		 */
		private function get_meta_key_definitions() {
			$action_id = $this->id;

			return array(

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
		}

		/**
		 * Décrit les réglages de l'action (utilisé dans l'éditeur WS Form).
		 *
		 * @return stdClass
		 */
		public function get_action_settings() {
			$settings = array( 'meta_keys' => array_keys( $this->get_meta_key_definitions() ) );

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
		 * Renvoie toujours true : un échec ne doit ni bloquer le visiteur ni lui montrer de détail technique.
		 *
		 * @param mixed $form   Objet formulaire WS Form.
		 * @param mixed $submit Objet soumission WS Form.
		 * @param array $config Configuration de l'action.
		 * @param int   $count  Rang de l'action parmi celles de ce type (non utilisé : chaque instance insère).
		 *
		 * @return bool
		 */
		public function post( $form, $submit, $config, $count = 1 ) {
			$form_id = ( is_object( $form ) && isset( $form->id ) ) ? absint( $form->id ) : 0;
			$meta    = ( isset( $config['meta'] ) && is_array( $config['meta'] ) ) ? $config['meta'] : array();

			$handler = new WS_Form_DB_Form_Submission_Handler();
			$result  = $handler->handle_submission( $meta, $form, $submit );

			if ( is_wp_error( $result ) ) {
				WS_Form_DB_Failure_Notifier::record( $form_id, $submit, $result );
				return true;
			}

			WS_Form_DB_Logger::log_info( 'Submission inserted', array( 'form_id' => $form_id ) );

			return true;
		}

		/**
		 * Retire le mot de passe de la copie des actions que WS Form enregistre avec chaque soumission.
		 *
		 * WS Form y sérialise la configuration complète des actions avant chacune d'elles.
		 * Notre action reçoit sa configuration séparément : la copie peut être nettoyée.
		 *
		 * @param object $action_object Action sur le point de s'exécuter.
		 * @param object $form          Formulaire.
		 * @param object $submit        Soumission, modifiée par référence d'objet.
		 *
		 * @return object Action inchangée.
		 */
		public function strip_password_from_submit( $action_object, $form, $submit ) {
			if ( ! is_object( $submit ) || ! isset( $submit->actions ) || ! is_string( $submit->actions ) ) {
				return $action_object;
			}

			$password_key = WS_Form_DB_Connection_Settings::META_PREFIX . 'password';

			if ( false === strpos( $submit->actions, $password_key ) ) {
				return $action_object;
			}

			$actions = unserialize( $submit->actions, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Format imposé par WS Form.

			if ( ! is_array( $actions ) ) {
				return $action_object;
			}

			foreach ( $actions as $index => $action ) {
				if ( isset( $action['meta'][ $password_key ] ) ) {
					$actions[ $index ]['meta'][ $password_key ] = '';
				}
			}

			$submit->actions = serialize( $actions ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Format imposé par WS Form.

			return $action_object;
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
				<div class="wsf-db-mapping-container" data-wsf-db-mapping-rows></div>
			</div>
			<?php
			return ob_get_clean();
		}
	}
}
