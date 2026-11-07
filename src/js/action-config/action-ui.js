/**
 * Interface de configuration de l'action dans la barre latérale de WS Form.
 */

import { i18n, log, metaPrefix, parseJson, escapeHtml } from './helpers';
import { request } from './api';
import MappingEditor from './mapping';

/**
 * Relie les boutons de l'action aux points AJAX et garde les caches à jour.
 *
 * Les tables, colonnes et le mapping sont stockés dans des champs cachés de
 * l'action : WS Form les enregistre avec le formulaire, et l'interface les
 * retrouve à la réouverture sans rappeler la base.
 */
export default class ActionUI {
	/**
	 * @param {jQuery} $sidebar Barre latérale de l'action.
	 */
	constructor( $sidebar ) {
		this.$sidebar = $sidebar;
		this.tables = [];
		this.columns = [];
		this.mapping = {};

		this.handleTestClick = this.handleTestClick.bind( this );
		this.handleLoadTablesClick = this.handleLoadTablesClick.bind( this );
		this.handleAnalyzeClick = this.handleAnalyzeClick.bind( this );
		this.updateAnalyzeButton = this.updateAnalyzeButton.bind( this );
		this.persistMapping = this.persistMapping.bind( this );

		this.cacheElements();

		if ( ! this.$testButton.length ) {
			log( 'Buttons not found, aborting.' );
			return;
		}

		this.mappingEditor = new MappingEditor(
			this.$mappingUi.find( '[data-wsf-db-mapping-rows]' ),
			this.persistMapping
		);

		this.bindEvents();
		this.restoreState();
		log( 'UI initialized' );
	}

	/**
	 * Repère les éléments de la barre latérale.
	 */
	cacheElements() {
		this.fields = {};

		for ( const key of [
			'connection',
			'host',
			'user',
			'password',
			'port',
			'name',
			'table',
			'tables_cache',
			'columns_cache',
			'mapping',
		] ) {
			this.fields[ key ] = this.$sidebar.find( '[data-meta-key="' + metaPrefix + key + '"]' );
		}

		this.$mappingUi = this.$sidebar.find( '[data-wsf-db-mapping-ui]' );
		this.$status = this.$sidebar.find( '[data-wsf-db-status]' );
		this.$testButton = this.$sidebar.find( '[data-wsf-db-test-connection]' );
		this.$loadTablesButton = this.$sidebar.find( '[data-wsf-db-load-tables]' );
		this.$analyzeButton = this.$sidebar.find( '[data-wsf-db-analyze-table]' );

		// Champs techniques : stockés par WS Form, jamais saisis à la main.
		for ( const key of [ 'tables_cache', 'columns_cache', 'mapping' ] ) {
			this.fields[ key ].closest( '.wsf-field-wrapper' ).hide();
		}
	}

	/**
	 * Branche les boutons.
	 */
	bindEvents() {
		this.$testButton.on( 'click', this.handleTestClick );
		this.$loadTablesButton.on( 'click', this.handleLoadTablesClick );
		this.$analyzeButton.on( 'click', this.handleAnalyzeClick );
		this.fields.table.on( 'change', this.updateAnalyzeButton );
	}

	/**
	 * Restaure tables, colonnes et mapping depuis les champs cachés.
	 */
	restoreState() {
		const tablesCache = parseJson( this.fields.tables_cache.val() );
		const columnsCache = parseJson( this.fields.columns_cache.val() );
		const mappingCache = parseJson( this.fields.mapping.val() );
		const selectedTable =
			( tablesCache && tablesCache.selected ) || this.fields.table.val() || '';

		if ( tablesCache && Array.isArray( tablesCache.tables ) && tablesCache.tables.length ) {
			this.tables = tablesCache.tables;
			this.populateTables( selectedTable );
		}

		if ( Array.isArray( columnsCache ) ) {
			this.columns = columnsCache;
		}

		if ( mappingCache && 'object' === typeof mappingCache ) {
			this.mapping = mappingCache;
		}

		if ( this.columns.length ) {
			this.renderMapping();
		}

		this.updateAnalyzeButton();
		this.setDisabled( this.$loadTablesButton, ! this.getCredentials() && ! this.tables.length );
	}

	/**
	 * Retourne les paramètres de connexion à envoyer au serveur.
	 *
	 * Avec une connexion nommée, seul son nom part : les identifiants restent
	 * dans wp-config.php et le serveur les résout lui-même.
	 *
	 * @return {Object|null} Paramètres, ou null s'ils sont incomplets.
	 */
	getCredentials() {
		const connection = this.fields.connection.length ? this.fields.connection.val() : '';

		if ( connection ) {
			return { connection };
		}

		const credentials = {
			host: String( this.fields.host.val() || '' ).trim(),
			user: String( this.fields.user.val() || '' ).trim(),
			password: this.fields.password.val() || '',
			port: this.fields.port.val() || '3306',
			database: String( this.fields.name.val() || '' ).trim(),
		};

		return credentials.host && credentials.user && credentials.database ? credentials : null;
	}

	/**
	 * Appelle un point AJAX en gérant le bouton et le statut.
	 *
	 * @param {jQuery} $button Bouton désactivé pendant l'appel.
	 * @param {string} action  Action AJAX.
	 * @param {Object} extra   Données supplémentaires.
	 * @return {jQuery.Promise|null} Données de la réponse, ou null si la connexion est incomplète.
	 */
	call( $button, action, extra = {} ) {
		const credentials = this.getCredentials();

		if ( ! credentials ) {
			this.setStatus( i18n( 'connectionMissing' ), 'error' );
			return null;
		}

		this.setDisabled( $button, true );
		this.setStatus( i18n( 'loading' ), 'info' );

		return request( action, credentials, extra ).always(
			this.setDisabled.bind( this, $button, false )
		);
	}

	/**
	 * Teste la connexion.
	 *
	 * @param {Event} event Clic.
	 */
	handleTestClick( event ) {
		event.preventDefault();

		const call = this.call( this.$testButton, 'ws_form_db_test_connection' );

		if ( call ) {
			call.done( this.onTestSuccess.bind( this ) ).fail(
				this.onFailure.bind( this, 'testError' )
			);
		}
	}

	/**
	 * Affiche le succès du test et autorise le chargement des tables.
	 *
	 * @param {Object} data Réponse.
	 */
	onTestSuccess( data ) {
		this.setStatus( data.message || i18n( 'testSuccess' ), 'success' );
		this.setDisabled( this.$loadTablesButton, false );
	}

	/**
	 * Charge la liste des tables.
	 *
	 * @param {Event} event Clic.
	 */
	handleLoadTablesClick( event ) {
		event.preventDefault();

		const call = this.call( this.$loadTablesButton, 'ws_form_db_get_tables' );

		if ( call ) {
			call.done( this.onTablesLoaded.bind( this ) ).fail(
				this.onFailure.bind( this, 'tablesError' )
			);
		}
	}

	/**
	 * Remplit et mémorise la liste des tables.
	 *
	 * @param {Object} data Réponse.
	 */
	onTablesLoaded( data ) {
		this.tables = data.tables || [];
		this.populateTables( '' );
		this.saveTablesCache( '' );
		this.setStatus( i18n( 'tablesLoaded' ), 'success' );
		log( 'Tables loaded', this.tables.length );
	}

	/**
	 * Analyse la table choisie.
	 *
	 * @param {Event} event Clic.
	 */
	handleAnalyzeClick( event ) {
		event.preventDefault();

		const table = this.fields.table.val();

		if ( ! table ) {
			this.setStatus( i18n( 'selectTableFirst' ), 'error' );
			return;
		}

		this.saveTablesCache( table );

		const call = this.call( this.$analyzeButton, 'ws_form_db_analyze_table', { table } );

		if ( call ) {
			call.done( this.onTableAnalyzed.bind( this ) ).fail(
				this.onFailure.bind( this, 'analyzeError' )
			);
		}
	}

	/**
	 * Mémorise les colonnes et affiche le mapping.
	 *
	 * @param {Object} data Réponse.
	 */
	onTableAnalyzed( data ) {
		this.columns = data.columns || [];
		this.fields.columns_cache.val( JSON.stringify( this.columns ) ).trigger( 'change' );
		this.renderMapping();
		this.setStatus( i18n( 'analyzeSuccess' ), 'success' );
		log( 'Table analyzed', this.columns.length );
	}

	/**
	 * Affiche l'erreur d'un appel.
	 *
	 * @param {string} fallbackKey Message par défaut.
	 * @param {Error}  error       Erreur reçue.
	 */
	onFailure( fallbackKey, error ) {
		this.setStatus( error && error.message ? error.message : i18n( fallbackKey ), 'error' );
		log( 'Request failed', error );
	}

	/**
	 * Remplit la liste des tables.
	 *
	 * @param {string} selectedTable Table à présélectionner.
	 */
	populateTables( selectedTable ) {
		let html = '<option value="">' + i18n( 'selectTable' ) + '</option>';

		for ( const table of this.tables ) {
			html +=
				'<option value="' +
				escapeHtml( table ) +
				'"' +
				( table === selectedTable ? ' selected' : '' ) +
				'>' +
				escapeHtml( table ) +
				'</option>';
		}

		this.fields.table.html( html );

		if ( selectedTable ) {
			this.fields.table.val( selectedTable );
		}

		this.updateAnalyzeButton();
	}

	/**
	 * Mémorise les tables et la table choisie dans le champ caché.
	 *
	 * @param {string} selectedTable Table choisie.
	 */
	saveTablesCache( selectedTable ) {
		this.fields.tables_cache
			.val( JSON.stringify( { tables: this.tables, selected: selectedTable } ) )
			.trigger( 'change' );
	}

	/**
	 * Affiche l'éditeur de mapping, ou le message « aucune colonne ».
	 */
	renderMapping() {
		if ( ! this.columns.length ) {
			this.$mappingUi
				.find( '[data-wsf-db-columns]' )
				.html( '<p>' + i18n( 'noColumns' ) + '</p>' );
			this.$mappingUi.hide();
			return;
		}

		this.$mappingUi.find( '[data-wsf-db-columns]' ).empty();
		this.$mappingUi.show();
		this.mappingEditor.render( this.columns, this.mapping );
	}

	/**
	 * Enregistre le mapping dans le champ caché lu par WS Form.
	 *
	 * @param {Object} mapping Mapping colonne => modèle.
	 */
	persistMapping( mapping ) {
		this.mapping = mapping;
		this.fields.mapping.val( JSON.stringify( mapping ) ).trigger( 'change' );
		log( 'Mapping saved', mapping );
	}

	/**
	 * Active le bouton d'analyse quand une table est choisie.
	 */
	updateAnalyzeButton() {
		this.setDisabled( this.$analyzeButton, ! this.fields.table.val() );
	}

	/**
	 * Active ou désactive un bouton.
	 *
	 * @param {jQuery}  $button  Bouton.
	 * @param {boolean} disabled État voulu.
	 */
	setDisabled( $button, disabled ) {
		$button.prop( 'disabled', disabled );
	}

	/**
	 * Affiche un message d'état.
	 *
	 * @param {string} message Message.
	 * @param {string} type    success, error ou info.
	 */
	setStatus( message, type ) {
		this.$status
			.text( message )
			.removeClass( 'wsf-db-status-success wsf-db-status-error wsf-db-status-info' )
			.addClass( 'wsf-db-status-' + type );
	}
}
