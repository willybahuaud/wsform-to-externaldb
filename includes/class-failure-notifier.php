<?php
/**
 * Signalement des insertions externes en échec.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signale un échec d'insertion : journal, note sur la soumission, notice admin.
 */
class WS_Form_DB_Failure_Notifier {

	/**
	 * Option qui mémorise les échecs non lus.
	 *
	 * @var string
	 */
	const OPTION = 'ws_form_db_failures';

	/**
	 * Action admin-post qui ferme la notice.
	 *
	 * @var string
	 */
	const DISMISS_ACTION = 'ws_form_db_dismiss_failures';

	/**
	 * Branche la notice et sa fermeture.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_notices', array( __CLASS__, 'display_notice' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'dismiss' ) );
	}

	/**
	 * Enregistre un échec d'insertion.
	 *
	 * @param int      $form_id Identifiant du formulaire.
	 * @param mixed    $submit  Objet soumission WS Form.
	 * @param WP_Error $error   Erreur rencontrée.
	 *
	 * @return void
	 */
	public static function record( $form_id, $submit, $error ) {
		$submit_id = ( is_object( $submit ) && isset( $submit->id ) ) ? absint( $submit->id ) : 0;

		WS_Form_DB_Logger::log_error(
			'External insert failed',
			array(
				'form_id'   => $form_id,
				'submit_id' => $submit_id,
				'code'      => $error->get_error_code(),
				'message'   => $error->get_error_message(),
			)
		);

		self::add_submit_note( $submit_id, $error );

		$failures = get_option( self::OPTION, array() );
		$failures = is_array( $failures ) ? $failures : array();

		update_option(
			self::OPTION,
			array(
				'count'        => ( isset( $failures['count'] ) ? (int) $failures['count'] : 0 ) + 1,
				'last_time'    => time(),
				'last_form_id' => absint( $form_id ),
				'last_message' => $error->get_error_message(),
			),
			false
		);
	}

	/**
	 * Affiche la notice aux administrateurs.
	 *
	 * @return void
	 */
	public static function display_notice() {
		if ( ! WS_Form_DB_Security::current_user_can_manage() ) {
			return;
		}

		$failures = get_option( self::OPTION );

		if ( ! is_array( $failures ) || empty( $failures['count'] ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p><code>%3$s</code></p><p><a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'External database:', 'wsform-to-externaldb' ),
			esc_html(
				sprintf(
					/* translators: 1: number of failures, 2: form ID, 3: date */
					_n(
						'%1$d submission could not be inserted. Last failure on form #%2$d, on %3$s. The data is still in the WS Form submissions.',
						'%1$d submissions could not be inserted. Last failure on form #%2$d, on %3$s. The data is still in the WS Form submissions.',
						(int) $failures['count'],
						'wsform-to-externaldb'
					),
					(int) $failures['count'],
					(int) $failures['last_form_id'],
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $failures['last_time'] )
				)
			),
			esc_html( $failures['last_message'] ),
			esc_url( $dismiss_url ),
			esc_html__( 'Mark as read', 'wsform-to-externaldb' )
		);
	}

	/**
	 * Ferme la notice.
	 *
	 * @return void
	 */
	public static function dismiss() {
		if ( ! WS_Form_DB_Security::current_user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wsform-to-externaldb' ), 403 );
		}

		check_admin_referer( self::DISMISS_ACTION );
		delete_option( self::OPTION );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Ajoute une note sur la soumission WS Form, si la version le permet.
	 *
	 * @param int      $submit_id Identifiant de la soumission.
	 * @param WP_Error $error     Erreur rencontrée.
	 *
	 * @return void
	 */
	private static function add_submit_note( $submit_id, $error ) {
		if ( ! $submit_id || ! class_exists( 'WS_Form_Submit_Note' ) ) {
			return;
		}

		try {
			$note            = new WS_Form_Submit_Note();
			$note->submit_id = $submit_id;
			$note->user_name = __( 'External database', 'wsform-to-externaldb' );
			$note->content   = sprintf(
				/* translators: %s: error message */
				__( 'Insert into the external database failed: %s', 'wsform-to-externaldb' ),
				$error->get_error_message()
			);
			// Le visiteur n'a pas le droit edit_submission : la vérification de droits est contournée volontairement.
			$note->db_create( true );
		} catch ( Exception $exception ) {
			WS_Form_DB_Logger::log_error( 'Could not add submission note', array( 'message' => $exception->getMessage() ) );
		}
	}
}
