<?php
/**
 * Insertion d'une soumission WS Form dans la base externe.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Résout le mapping d'une action et insère la ligne correspondante.
 */
class WS_Form_DB_Form_Submission_Handler {

	/**
	 * Préfixe des meta keys de l'action.
	 *
	 * @var string
	 */
	const META_PREFIX = 'action_ws_form_db_external_';

	/**
	 * Types de colonnes numériques, comparés au début du type MySQL.
	 *
	 * @var string[]
	 */
	const NUMERIC_TYPES = array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real', 'bit' );

	/**
	 * Types de colonnes textuelles, où une chaîne vide reste une chaîne vide.
	 *
	 * @var string[]
	 */
	const TEXT_TYPES = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set', 'json' );

	/**
	 * Traite la soumission selon la configuration de l'action.
	 *
	 * @param array $action_meta     Meta de l'action.
	 * @param mixed $form            Objet formulaire WS Form.
	 * @param mixed $submit          Objet soumission WS Form.
	 *
	 * @return array|WP_Error Résultat (inserted_id) ou erreur.
	 */
	public function handle_submission( $action_meta, $form, $submit ) {
		$table_name = isset( $action_meta[ self::META_PREFIX . 'table' ] ) ? sanitize_text_field( $action_meta[ self::META_PREFIX . 'table' ] ) : '';

		if ( '' === $table_name ) {
			return new WP_Error( 'ws_form_db_missing_table', __( 'No destination table is selected.', 'wsform-to-externaldb' ) );
		}

		$mapping = $this->get_mapping( $action_meta );
		if ( is_wp_error( $mapping ) ) {
			return $mapping;
		}

		$params = WS_Form_DB_Connection_Settings::from_action_meta( $action_meta );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$db = WS_Form_DB_Connection::open( $params );
		if ( is_wp_error( $db ) ) {
			return $db;
		}

		$columns = WS_Form_DB_Table_Analyzer::get_columns( $db, $table_name );
		if ( is_wp_error( $columns ) ) {
			return $columns;
		}

		$row = $this->build_row( $mapping, $columns, $form, $submit );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( false === $db->insert( $table_name, $row, array_fill( 0, count( $row ), '%s' ) ) ) {
			$reason = '' !== $db->last_error ? $db->last_error : __( 'The database rejected the insert.', 'wsform-to-externaldb' );

			WS_Form_DB_Logger::log_insertion( $table_name, count( $row ), false, null, $reason );

			return new WP_Error( 'ws_form_db_insert_failed', $reason );
		}

		WS_Form_DB_Logger::log_insertion( $table_name, count( $row ), true, $db->insert_id );

		return array( 'inserted_id' => $db->insert_id );
	}

	/**
	 * Décode et valide le mapping colonne => modèle de valeur.
	 *
	 * @param array $action_meta Meta de l'action.
	 *
	 * @return array|WP_Error
	 */
	protected function get_mapping( $action_meta ) {
		$mapping_raw = isset( $action_meta[ self::META_PREFIX . 'mapping' ] ) ? $action_meta[ self::META_PREFIX . 'mapping' ] : '';

		// Pas de wp_unslash() : il transformerait les \n du JSON en « n ».
		$mapping = is_string( $mapping_raw ) ? json_decode( $mapping_raw, true ) : null;

		if ( ! is_array( $mapping ) || array() === $mapping ) {
			return new WP_Error( 'ws_form_db_missing_mapping', __( 'The column mapping is empty or unreadable.', 'wsform-to-externaldb' ) );
		}

		return $mapping;
	}

	/**
	 * Construit la ligne à insérer, colonne par colonne.
	 *
	 * @param array   $mapping Mapping colonne => modèle de valeur.
	 * @param array[] $columns Colonnes réelles de la table.
	 * @param mixed   $form    Objet formulaire WS Form.
	 * @param mixed   $submit  Objet soumission WS Form.
	 *
	 * @return array|WP_Error
	 */
	protected function build_row( $mapping, $columns, $form, $submit ) {
		$columns_by_name = array();
		foreach ( $columns as $column ) {
			$columns_by_name[ $column['name'] ] = $column;
		}

		$writable = WS_Form_DB_Table_Analyzer::get_writable_column_names( $columns );
		$row      = array();

		foreach ( $mapping as $column_name => $template ) {
			// La colonne doit exister et accepter une valeur : c'est la seule garantie
			// pour un identifiant qui ne passe pas par une requête préparée.
			if ( ! in_array( $column_name, $writable, true ) ) {
				return new WP_Error(
					'ws_form_db_unknown_column',
					sprintf(
						/* translators: %s: column name */
						__( 'Column “%s” does not exist or cannot receive a value.', 'wsform-to-externaldb' ),
						$column_name
					)
				);
			}

			$value = $this->resolve_value( (string) $template, $form, $submit );
			$value = $this->adapt_to_column( $value, $columns_by_name[ $column_name ] );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$row[ $column_name ] = $value;
		}

		return $row;
	}

	/**
	 * Remplace les variables WS Form d'un modèle de valeur.
	 *
	 * @param string $template Modèle (texte et variables).
	 * @param mixed  $form     Objet formulaire WS Form.
	 * @param mixed  $submit   Objet soumission WS Form.
	 *
	 * @return string
	 */
	protected function resolve_value( $template, $form, $submit ) {
		// L'éditeur stocke parfois les retours à la ligne en « \n » littéral.
		$template = str_replace( array( '\\r', '\\n', '\\t' ), array( "\r", "\n", "\t" ), $template );

		if ( false === strpos( $template, '#' ) ) {
			return $template;
		}

		list( $template, $custom_values ) = WS_Form_DB_Custom_Variables::extract( $template, $form, $submit );

		// text/plain : pas d'échappement HTML, les retours à la ligne sont conservés.
		$value = WS_Form_Common::parse_variables_process( $template, $form, $submit, 'text/plain' );

		// WS Form neutralise les variables sensibles tapées par le visiteur en
		// remplaçant # par &num;. En base, on veut le texte tel qu'il a été saisi.
		$value = str_replace( '&num;', '#', (string) $value );
		$value = WS_Form_DB_Custom_Variables::restore( $value, $custom_values );

		/**
		 * Filtre une valeur avant insertion.
		 *
		 * @param string $value    Valeur résolue.
		 * @param string $template Modèle d'origine.
		 * @param mixed  $submit   Objet soumission WS Form.
		 */
		return apply_filters( 'ws_form_db_sanitize_value', $value, $template, $submit );
	}

	/**
	 * Adapte une valeur au type de sa colonne.
	 *
	 * Une chaîne vide devient NULL hors colonnes texte : sans ça, un champ
	 * facultatif laissé vide finit en 0 ou en date nulle. Un texte dans une
	 * colonne numérique est refusé plutôt que converti silencieusement en 0.
	 *
	 * @param mixed $value  Valeur résolue.
	 * @param array $column Description de la colonne.
	 *
	 * @return mixed|WP_Error
	 */
	protected function adapt_to_column( $value, $column ) {
		$base_type = strtolower( (string) strtok( (string) $column['type'], '( ' ) );

		if ( '' === $value && ! in_array( $base_type, self::TEXT_TYPES, true ) && 'YES' === $column['null'] ) {
			return null;
		}

		if ( in_array( $base_type, self::NUMERIC_TYPES, true ) && '' !== $value && null !== $value && ! is_numeric( $value ) ) {
			return new WP_Error(
				'ws_form_db_not_numeric',
				sprintf(
					/* translators: %s: column name */
					__( 'Column “%s” expects a number.', 'wsform-to-externaldb' ),
					$column['name']
				)
			);
		}

		return $value;
	}
}
