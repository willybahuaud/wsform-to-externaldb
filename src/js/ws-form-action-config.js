/**
 * Point d'entrée de la configuration de l'action dans l'éditeur WS Form.
 *
 * WS Form construit la barre latérale de l'action à la volée : le script
 * surveille le DOM et monte l'interface quand ses boutons apparaissent.
 */

import '../css/ws-form-action-config.css';
import { config, i18n, log } from './action-config/helpers';
import ActionUI from './action-config/action-ui';

const $ = window.jQuery;

/**
 * Délai laissé à WS Form pour remplir la barre latérale après son ouverture.
 *
 * @type {number}
 */
const SIDEBAR_OPEN_DELAY = 100;

/**
 * Monte l'interface si la barre latérale de l'action est ouverte et pas encore prise en charge.
 */
function mountIfReady() {
	const $sidebar = $( '#wsf-sidebar-action' );

	if ( ! $sidebar.length || ! $sidebar.is( ':visible' ) ) {
		return;
	}

	const $buttons = $sidebar.find( '[data-wsf-db-test-connection]' );

	if ( ! $buttons.length || $buttons.data( 'wsf-db-initialized' ) ) {
		return;
	}

	$buttons.data( 'wsf-db-initialized', true );
	new ActionUI( $sidebar ); // eslint-disable-line no-new
}

/**
 * Indique si un lot de mutations peut avoir affiché la barre latérale.
 *
 * @param {MutationRecord[]} mutations Mutations observées.
 * @return {boolean} Vrai s'il faut retenter le montage.
 */
function mutationsMayShowSidebar( mutations ) {
	return mutations.some( isRelevantMutation );
}

/**
 * Indique si une mutation ajoute des nœuds ou change la visibilité.
 *
 * @param {MutationRecord} mutation Mutation.
 * @return {boolean} Vrai si elle est pertinente.
 */
function isRelevantMutation( mutation ) {
	return (
		'attributes' === mutation.type ||
		( 'childList' === mutation.type && mutation.addedNodes.length > 0 )
	);
}

/**
 * Réagit aux mutations du DOM.
 *
 * @param {MutationRecord[]} mutations Mutations observées.
 */
function handleMutations( mutations ) {
	if ( mutationsMayShowSidebar( mutations ) ) {
		mountIfReady();
	}
}

/**
 * Surveille les barres latérales de WS Form.
 */
function observeSidebars() {
	const target = document.getElementById( 'wsf-sidebars' ) || document.body;

	new MutationObserver( handleMutations ).observe( target, {
		childList: true,
		subtree: true,
		attributes: true,
		attributeFilter: [ 'class', 'style' ],
	} );
}

/**
 * Retente le montage peu après l'ouverture d'une barre latérale.
 */
function handleSidebarOpen() {
	window.setTimeout( mountIfReady, SIDEBAR_OPEN_DELAY );
}

/**
 * Ouvre le sélecteur de variables de WS Form pour un champ de mapping.
 *
 * @param {Event} event Clic sur l'icône de variables.
 */
function handleVariableHelperClick( event ) {
	event.preventDefault();
	event.stopPropagation();

	const metaKey = $( event.currentTarget ).attr( 'data-option-meta-key' );
	const $textarea = $( event.currentTarget )
		.closest( '.wsf-field-wrapper' )
		.find( '[data-meta-key="' + metaKey + '"]' );
	const wsForm = $.WS_Form && $.WS_Form.this;

	if ( wsForm && 'function' === typeof wsForm.variable_helper_modal_open ) {
		wsForm.variable_helper_modal_open( $textarea, false );
		return;
	}

	// API interne de WS Form : si elle change, on affiche au moins un mémo.
	log( 'WS Form variable helper not available' );
	window.alert( i18n( 'variablesFallback' ) ); // eslint-disable-line no-alert
}

/**
 * Démarre le script.
 */
function init() {
	if ( ! config.ajaxUrl || ! config.nonce ) {
		log( 'Missing configuration', config );
		return;
	}

	mountIfReady();
	observeSidebars();

	$( document ).on( 'wsf-sidebar-action-open wsf-sidebar-open', handleSidebarOpen );
	$( document ).on(
		'click',
		'.wsf-db-mapping-row-value [data-action="wsf-variable-helper"]',
		handleVariableHelperClick
	);
}

$( init );
