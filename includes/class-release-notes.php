<?php
/**
 * Conversion des notes de release en HTML.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Couvre le Markdown d'une note de release GitHub : titres, listes, gras et liens.
 */
class WS_Form_DB_Release_Notes {

	/**
	 * Convertit des notes en HTML.
	 *
	 * @param string $markdown Notes de la release.
	 *
	 * @return string
	 */
	public static function to_html( $markdown ) {
		$html    = '';
		$in_list = false;

		foreach ( preg_split( '/\R/', (string) $markdown ) as $line ) {
			$line = trim( $line );

			if ( '' === $line ) {
				continue;
			}

			$is_list_item = 1 === preg_match( '/^[*-]\s+(.+)$/', $line, $list_parts );

			if ( $is_list_item !== $in_list ) {
				$html   .= $is_list_item ? '<ul>' : '</ul>';
				$in_list = $is_list_item;
			}

			$html .= $is_list_item ? '<li>' . self::format_inline( $list_parts[1] ) . '</li>' : self::format_block( $line );
		}

		return $in_list ? $html . '</ul>' : $html;
	}

	/**
	 * Convertit une ligne hors liste : un titre ou un paragraphe.
	 *
	 * @param string $line Ligne de texte.
	 *
	 * @return string
	 */
	private static function format_block( $line ) {
		if ( 1 === preg_match( '/^#{1,6}\s+(.+)$/', $line, $heading_parts ) ) {
			return '<h4>' . self::format_inline( $heading_parts[1] ) . '</h4>';
		}

		return '<p>' . self::format_inline( $line ) . '</p>';
	}

	/**
	 * Convertit le gras et les liens d'une ligne.
	 *
	 * L'échappement passe en premier : le texte vient de GitHub et s'affiche dans l'admin.
	 *
	 * @param string $text Texte.
	 *
	 * @return string
	 */
	private static function format_inline( $text ) {
		$bold_text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', esc_html( $text ) );

		// Pas de make_clickable : il couperait les liens de comparaison « v1.0.1...v1.0.2 » aux points.
		return preg_replace( '#https://[^\s<]*[^\s<.,)]#', '<a href="$0">$0</a>', $bold_text );
	}
}
