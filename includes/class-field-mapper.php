<?php
/**
 * Gestion du mappage champs WS Form -> colonnes BDD.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WS_Form_DB_Field_Mapper' ) ) {

	/**
	 * Classe utilitaire pour la récupération et la validation du mappage.
	 */
	class WS_Form_DB_Field_Mapper {

		/**
		 * Récupère les champs disponibles pour un formulaire WS Form.
		 *
		 * @param int $form_id Identifiant du formulaire WS Form.
		 *
		 * @return array|WP_Error
		 */
		public static function get_form_fields( $form_id ) {
			$form_id = absint( $form_id );

			if ( ! $form_id ) {
				return new WP_Error( 'ws_form_db_invalid_form', __( 'Invalid WS Form form.', 'wsform-to-externaldb' ) );
			}

			$fields = array();

			if ( class_exists( 'WS_Form_Common' ) && method_exists( 'WS_Form_Common', 'get_fields_form' ) ) {
				$fields = WS_Form_Common::get_fields_form( $form_id );
			} elseif ( class_exists( 'WS_Form_Common' ) && method_exists( 'WS_Form_Common', 'get_fields' ) ) {
				$common = new WS_Form_Common();
				$fields = $common->get_fields( $form_id );
			} elseif ( function_exists( 'wsf_form_get' ) ) {
				$form = wsf_form_get( $form_id );

				if ( is_array( $form ) && isset( $form['fields'] ) ) {
					$fields = $form['fields'];
				}
			}

			/**
			 * Point d'extension pour forcer la liste des champs si la méthode native varie.
			 */
			$fields = apply_filters( 'ws_form_db_form_fields', $fields, $form_id );

			if ( empty( $fields ) ) {
				return new WP_Error( 'ws_form_db_no_fields', __( 'Could not read the form fields.', 'wsform-to-externaldb' ) );
			}

			return self::normalize_fields( $fields );
		}

		/**
		 * Normalise la structure renvoyée par WS Form.
		 *
		 * @param array $fields Champs bruts.
		 *
		 * @return array
		 */
		private static function normalize_fields( $fields ) {
			$normalized = array();

			foreach ( $fields as $field ) {
				$field_id = 0;

				if ( isset( $field['id'] ) ) {
					$field_id = absint( $field['id'] );
				} elseif ( isset( $field['form_field_id'] ) ) {
					$field_id = absint( $field['form_field_id'] );
				}

				if ( ! $field_id ) {
					continue;
				}

				/* translators: %d: field ID */
				$label = isset( $field['label'] ) ? wp_strip_all_tags( $field['label'] ) : sprintf( __( 'Field #%d', 'wsform-to-externaldb' ), $field_id );
				$type  = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';

				$normalized[] = array(
					'id'    => $field_id,
					'label' => $label,
					'type'  => $type,
					'token' => '#field_' . $field_id,
				);
			}

			return $normalized;
		}
	}
}
