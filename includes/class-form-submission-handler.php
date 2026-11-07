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
	 * Types entiers MySQL.
	 *
	 * @var string[]
	 */
	const INTEGER_TYPES = array( 'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'bit' );

	/**
	 * Types décimaux MySQL.
	 *
	 * @var string[]
	 */
	const DECIMAL_TYPES = array( 'decimal', 'numeric', 'float', 'double', 'real' );

	/**
	 * Types où une chaîne vide est une valeur valide.
	 *
	 * @var string[]
	 */
	const STRING_TYPES = array( 'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'enum', 'set', 'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob' );

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
		$table_name = isset( $action_meta[ WS_Form_DB_Connection_Settings::META_PREFIX . 'table' ] ) ? sanitize_text_field( $action_meta[ WS_Form_DB_Connection_Settings::META_PREFIX . 'table' ] ) : '';

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

		if ( false === $db->insert( $table_name, $row['values'], $row['formats'] ) ) {
			$reason = '' !== $db->last_error ? $db->last_error : __( 'The database rejected the insert.', 'wsform-to-externaldb' );

			WS_Form_DB_Logger::log_insertion( $table_name, count( $row['values'] ), false, null, $reason );

			return new WP_Error( 'ws_form_db_insert_failed', $reason );
		}

		WS_Form_DB_Logger::log_insertion( $table_name, count( $row['values'] ), true, $db->insert_id );

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
		$mapping_raw = isset( $action_meta[ WS_Form_DB_Connection_Settings::META_PREFIX . 'mapping' ] ) ? $action_meta[ WS_Form_DB_Connection_Settings::META_PREFIX . 'mapping' ] : '';

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
	 * @return array{values:array,formats:string[]}|WP_Error
	 */
	protected function build_row( $mapping, $columns, $form, $submit ) {
		$writable = WS_Form_DB_Table_Analyzer::get_writable_columns( $columns );
		$values   = array();
		$formats  = array();

		foreach ( $mapping as $column_name => $template ) {
			// json_decode transforme une clé « 2024 » en entier.
			$column_name = (string) $column_name;

			// Seule garantie pour un identifiant qui ne passe pas par une requête préparée.
			if ( ! isset( $writable[ $column_name ] ) ) {
				return new WP_Error(
					'ws_form_db_unknown_column',
					sprintf(
						/* translators: %s: column name */
						__( 'Column “%s” does not exist or cannot receive a value.', 'wsform-to-externaldb' ),
						$column_name
					)
				);
			}

			$column    = $writable[ $column_name ];
			$base_type = strtolower( (string) strtok( (string) $column['type'], '( ' ) );
			$value     = $this->resolve_value( (string) $template, $form, $submit );

			if ( '' === $value && ! in_array( $base_type, self::STRING_TYPES, true ) ) {
				// Champ facultatif vide : NULL si permis, sinon la valeur par défaut de la base.
				if ( 'YES' === $column['null'] ) {
					$value = null;
				} elseif ( null !== $column['default'] ) {
					continue;
				}
			}

			$value = $this->normalize_number( $value, $base_type, $column_name );

			if ( is_wp_error( $value ) ) {
				return $value;
			}

			$values[ $column_name ] = $value;
			// BIT attend un entier : la chaîne « 1 » vaudrait 0x31.
			$formats[] = 'bit' === $base_type ? '%d' : '%s';
		}

		return array(
			'values'  => $values,
			'formats' => $formats,
		);
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
	 * Valide une valeur destinée à une colonne numérique.
	 *
	 * Un texte est refusé plutôt que converti en 0 par MySQL. La virgule décimale est acceptée.
	 *
	 * @param mixed  $value       Valeur résolue.
	 * @param string $base_type   Type MySQL sans taille.
	 * @param string $column_name Nom de la colonne, pour le message.
	 *
	 * @return mixed|WP_Error
	 */
	protected function normalize_number( $value, $base_type, $column_name ) {
		$is_integer = in_array( $base_type, self::INTEGER_TYPES, true );
		$is_decimal = in_array( $base_type, self::DECIMAL_TYPES, true );

		if ( ( ! $is_integer && ! $is_decimal ) || null === $value || '' === $value ) {
			return $value;
		}

		$value = trim( (string) $value );

		if ( $is_decimal && preg_match( '/^-?\d+,\d+$/', $value ) ) {
			$value = str_replace( ',', '.', $value );
		}

		if ( ! is_numeric( $value ) ) {
			return new WP_Error(
				'ws_form_db_not_numeric',
				sprintf(
					/* translators: %s: column name */
					__( 'Column “%s” expects a number.', 'wsform-to-externaldb' ),
					$column_name
				)
			);
		}

		return $value;
	}
}
