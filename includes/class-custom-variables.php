<?php
/**
 * Variables WS Form pour lire des données WordPress à partir d'un ID.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Variables #get_*() du mapping : lisent une donnée WordPress publique à partir d'un ID.
 *
 * Résolues seulement dans le modèle écrit par l'admin, jamais dans une saisie.
 * L'ID pouvant venir du visiteur, seuls les contenus publics et les métas non protégées sont lus.
 */
class WS_Form_DB_Custom_Variables {

	/**
	 * Repère une variable et ses arguments, parenthèses imbriquées comprises.
	 *
	 * @var string
	 */
	const PATTERN = '/#(get_post_title|get_post_field|get_post_meta|get_term_name|get_user_display_name)(\((?:[^()]++|(?2))*\))/';

	/**
	 * Champs de post lisibles par #get_post_field().
	 *
	 * @var string[]
	 */
	const ALLOWED_POST_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_type', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'guid' );

	/**
	 * Remplace chaque variable du modèle par un jeton, avec sa valeur calculée.
	 *
	 * Les jetons ne contiennent pas de # : WS Form les laisse intacts, et une
	 * valeur lue en base (un titre, une méta) n'est jamais réanalysée.
	 *
	 * @param string $template Modèle écrit par l'administrateur.
	 * @param mixed  $form     Objet formulaire WS Form.
	 * @param mixed  $submit   Objet soumission WS Form.
	 *
	 * @return array{0:string,1:array<string,string>} Modèle à jetons, puis valeurs par jeton.
	 */
	public static function extract( $template, $form, $submit ) {
		if ( false === strpos( $template, '#get_' ) || ! preg_match_all( self::PATTERN, $template, $matches, PREG_SET_ORDER ) ) {
			return array( $template, array() );
		}

		$values = array();
		$prefix = 'wsfdb' . wp_generate_password( 12, false );

		foreach ( $matches as $index => $match ) {
			$arguments = array();

			// Découpage avant résolution : une virgule saisie par le visiteur ne doit pas décaler les arguments.
			foreach ( self::split_arguments( substr( $match[2], 1, -1 ) ) as $argument ) {
				$arguments[] = trim( (string) WS_Form_Common::parse_variables_process( $argument, $form, $submit, 'text/plain' ) );
			}

			$token            = $prefix . $index . 'x';
			$values[ $token ] = self::resolve( $match[1], $arguments );
			$template         = self::replace_first( $match[0], $token, $template );
		}

		return array( $template, $values );
	}

	/**
	 * Réinjecte les valeurs à la place des jetons.
	 *
	 * @param string               $value  Valeur analysée par WS Form.
	 * @param array<string,string> $values Valeurs par jeton.
	 *
	 * @return string
	 */
	public static function restore( $value, $values ) {
		return strtr( $value, $values );
	}

	/**
	 * Découpe des arguments sur les virgules de premier niveau.
	 *
	 * Les virgules à l'intérieur de parenthèses, comme dans #field(12, ", "), sont ignorées.
	 *
	 * @param string $arguments Arguments bruts, sans les parenthèses extérieures.
	 *
	 * @return string[]
	 */
	private static function split_arguments( $arguments ) {
		$parts   = array();
		$current = '';
		$depth   = 0;

		foreach ( str_split( $arguments ) as $character ) {
			if ( ',' === $character && 0 === $depth ) {
				$parts[] = $current;
				$current = '';
				continue;
			}

			if ( '(' === $character ) {
				++$depth;
			} elseif ( ')' === $character ) {
				--$depth;
			}

			$current .= $character;
		}

		$parts[] = $current;

		return $parts;
	}

	/**
	 * Remplace la première occurrence d'une chaîne.
	 *
	 * @param string $search  Chaîne cherchée.
	 * @param string $replace Remplacement.
	 * @param string $subject Texte.
	 *
	 * @return string
	 */
	private static function replace_first( $search, $replace, $subject ) {
		$position = strpos( $subject, $search );

		return false === $position ? $subject : substr_replace( $subject, $replace, $position, strlen( $search ) );
	}

	/**
	 * Calcule la valeur d'une variable.
	 *
	 * @param string   $name      Nom de la variable.
	 * @param string[] $arguments Arguments, déjà résolus.
	 *
	 * @return string
	 */
	private static function resolve( $name, $arguments ) {
		$object_id = absint( $arguments[0] );
		$second    = isset( $arguments[1] ) ? trim( $arguments[1], " \t\"'" ) : '';

		if ( ! $object_id ) {
			return '';
		}

		switch ( $name ) {
			case 'get_post_title':
				$post = self::get_public_post( $object_id );
				return $post ? $post->post_title : '';

			case 'get_post_field':
				$post = self::get_public_post( $object_id );
				return ( $post && in_array( $second, self::ALLOWED_POST_FIELDS, true ) ) ? (string) $post->$second : '';

			case 'get_post_meta':
				return self::get_public_post_meta( $object_id, $second );

			case 'get_term_name':
				return self::get_public_term_name( $object_id, sanitize_key( $second ) );

			case 'get_user_display_name':
				return self::get_author_display_name( $object_id );
		}

		return '';
	}

	/**
	 * Retourne un post seulement s'il est publiquement visible.
	 *
	 * @param int $post_id ID du post.
	 *
	 * @return WP_Post|null
	 */
	private static function get_public_post( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! is_post_type_viewable( $post->post_type ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Retourne une méta non protégée d'un post public.
	 *
	 * @param int    $post_id  ID du post.
	 * @param string $meta_key Clé de méta.
	 *
	 * @return string
	 */
	private static function get_public_post_meta( $post_id, $meta_key ) {
		if ( '' === $meta_key || is_protected_meta( $meta_key, 'post' ) || ! self::get_public_post( $post_id ) ) {
			return '';
		}

		$value = get_post_meta( $post_id, $meta_key, true );

		return is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
	}

	/**
	 * Retourne le nom d'un terme d'une taxonomie publique.
	 *
	 * @param int    $term_id  ID du terme.
	 * @param string $taxonomy Taxonomie attendue, facultative.
	 *
	 * @return string
	 */
	private static function get_public_term_name( $term_id, $taxonomy ) {
		$term = get_term( $term_id, $taxonomy );

		if ( ! $term instanceof WP_Term || ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return '';
		}

		return $term->name;
	}

	/**
	 * Retourne le nom affiché d'un utilisateur qui a publié des contenus.
	 *
	 * Le nom d'un auteur est déjà public. Celui d'un simple abonné ou d'un
	 * client ne doit pas l'être.
	 *
	 * @param int $user_id ID de l'utilisateur.
	 *
	 * @return string
	 */
	private static function get_author_display_name( $user_id ) {
		$user = get_userdata( $user_id );

		if ( ! $user || 0 === (int) count_user_posts( $user_id, 'post', true ) ) {
			return '';
		}

		return $user->display_name;
	}
}
