/**
 * Appels AJAX de l'éditeur.
 */

import { config, i18n } from './helpers';

const $ = window.jQuery;

/**
 * Transforme la réponse de wp_send_json_*() en succès ou en échec.
 *
 * @param {Object} response Réponse JSON.
 * @return {Object|jQuery.Promise} Données, ou promesse rejetée.
 */
function unwrapResponse( response ) {
	if ( response && response.success ) {
		return response.data;
	}

	const message =
		response && response.data && response.data.message
			? response.data.message
			: i18n( 'requestError' );

	return $.Deferred().reject( new Error( message ) ).promise();
}

/**
 * Transforme une erreur réseau en promesse rejetée.
 *
 * @param {Object} xhr    Requête.
 * @param {string} status Statut jQuery.
 * @param {string} error  Message HTTP.
 * @return {jQuery.Promise} Promesse rejetée.
 */
function rejectNetworkError( xhr, status, error ) {
	return $.Deferred()
		.reject( new Error( error || status || i18n( 'requestError' ) ) )
		.promise();
}

/**
 * Appelle un point AJAX du plugin.
 *
 * @param {string} action      Action AJAX.
 * @param {Object} credentials Paramètres de connexion.
 * @param {Object} extra       Données supplémentaires.
 * @return {jQuery.Promise} Données de la réponse.
 */
export function request( action, credentials, extra = {} ) {
	return $.ajax( {
		url: config.ajaxUrl,
		method: 'POST',
		dataType: 'json',
		data: { ...credentials, ...extra, action, nonce: config.nonce },
	} ).then( unwrapResponse, rejectNetworkError );
}
