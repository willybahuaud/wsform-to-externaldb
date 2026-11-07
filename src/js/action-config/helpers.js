/**
 * Utilitaires partagés par l'interface de configuration de l'action.
 */

/**
 * Configuration injectée par wp_localize_script().
 *
 * @type {Object}
 */
export const config = window.wsFormDbConfig || {};

/**
 * Préfixe des meta keys de l'action.
 *
 * @type {string}
 */
export const metaPrefix = 'action_' + ( config.actionId || 'ws_form_db_external' ) + '_';

/**
 * Retourne une chaîne traduite, ou sa clé si elle manque.
 *
 * @param {string} key Clé de la chaîne.
 * @return {string} Chaîne traduite.
 */
export function i18n( key ) {
	return config.i18n && config.i18n[ key ] ? config.i18n[ key ] : key;
}

/**
 * Écrit une trace dans la console, seulement si SCRIPT_DEBUG est actif.
 *
 * @param {...*} values Valeurs à afficher.
 */
export function log( ...values ) {
	if ( config.debug && window.console ) {
		window.console.log( '[WS Form DB]', ...values );
	}
}

/**
 * Échappe un texte pour l'insérer dans du HTML ou un attribut.
 *
 * @param {*} value Valeur à échapper.
 * @return {string} Texte échappé.
 */
export function escapeHtml( value ) {
	if ( null === value || undefined === value ) {
		return '';
	}

	return String( value )
		.replace( /&/g, '&amp;' )
		.replace( /"/g, '&quot;' )
		.replace( /'/g, '&#39;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );
}

/**
 * Décode du JSON sans lever d'exception.
 *
 * @param {string} text Texte JSON.
 * @return {*} Valeur décodée, ou null.
 */
export function parseJson( text ) {
	if ( ! text ) {
		return null;
	}

	try {
		return JSON.parse( text );
	} catch ( error ) {
		return null;
	}
}
