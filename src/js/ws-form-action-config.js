/**
 * Configuration de l'action WS Form DB External.
 *
 * Ce script gère l'interface de configuration de l'action
 * dans le sidebar dynamique de WS Form.
 */

import '../css/ws-form-action-config.css';

( function( $ ) {
	'use strict';

	var config = window.wsFormDbConfig || {};
	var actionId = config.actionId || 'ws_form_db_external';
	var metaPrefix = 'action_' + actionId + '_';

	/**
	 * Logger pour le debug.
	 */
	function log() {
		if ( window.console && window.console.log ) {
			var args = Array.prototype.slice.call( arguments );
			args.unshift( '[WS-Form-DB]' );
			window.console.log.apply( window.console, args );
		}
	}

	/**
	 * Récupère une traduction.
	 */
	function i18n( key ) {
		if ( config.i18n && config.i18n[ key ] ) {
			return config.i18n[ key ];
	}
	return key;
	}

	/**
	 * Classe principale de gestion de l'UI.
	 */
	function WsFormDbActionUI( $sidebar ) {
		this.$sidebar = $sidebar;
		this.$connectionField = null;
		this.$hostField = null;
		this.$userField = null;
		this.$passwordField = null;
		this.$portField = null;
		this.$nameField = null;
		this.$tableField = null;
		this.$tablesCacheField = null;
		this.$columnsCacheField = null;
		this.$mappingField = null;
		this.$mappingUi = null;
		this.$statusEl = null;
		this.$testBtn = null;
		this.$loadTablesBtn = null;
		this.$analyzeBtn = null;

		this.tables = [];
		this.columns = [];
		this.mapping = {};
		this.selectedTable = '';
		this.initialized = false;

		this.init();
	}

	/**
	 * Initialise l'UI.
	 */
	WsFormDbActionUI.prototype.init = function() {
		this.cacheElements();

		if ( ! this.$testBtn || ! this.$testBtn.length ) {
			log( 'Buttons not found, aborting.' );
			return;
		}

		this.bindEvents();
		this.restoreState();
		this.initialized = true;

		log( 'UI initialized' );
	};

	/**
	 * Met en cache les éléments du DOM.
	 */
	WsFormDbActionUI.prototype.cacheElements = function() {
		this.$connectionField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'connection"]' );
		this.$hostField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'host"]' );
		this.$userField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'user"]' );
		this.$passwordField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'password"]' );
		this.$portField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'port"]' );
		this.$nameField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'name"]' );
		this.$tableField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'table"]' );
		this.$tablesCacheField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'tables_cache"]' );
		this.$columnsCacheField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'columns_cache"]' );
		this.$mappingField = this.$sidebar.find( '[data-meta-key="' + metaPrefix + 'mapping"]' );

		this.$mappingUi = this.$sidebar.find( '[data-wsf-db-mapping-ui]' );
		this.$statusEl = this.$sidebar.find( '[data-wsf-db-status]' );
		this.$testBtn = this.$sidebar.find( '[data-wsf-db-test-connection]' );
		this.$loadTablesBtn = this.$sidebar.find( '[data-wsf-db-load-tables]' );
		this.$analyzeBtn = this.$sidebar.find( '[data-wsf-db-analyze-table]' );

		// Masquer les champs de cache.
		this.$tablesCacheField.closest( '.wsf-field-wrapper' ).hide();
		this.$columnsCacheField.closest( '.wsf-field-wrapper' ).hide();
		this.$mappingField.closest( '.wsf-field-wrapper' ).hide();
	};

	/**
	 * Attache les événements.
	 */
	WsFormDbActionUI.prototype.bindEvents = function() {
		var self = this;

		this.$testBtn.on( 'click', function( e ) {
			e.preventDefault();
			self.handleTestConnection();
		} );

		this.$loadTablesBtn.on( 'click', function( e ) {
			e.preventDefault();
			self.handleLoadTables();
		} );

		this.$analyzeBtn.on( 'click', function( e ) {
			e.preventDefault();
			self.handleAnalyzeTable();
		} );

		this.$tableField.on( 'change', function() {
			self.updateAnalyzeButtonState();
		} );
	};

	/**
	 * Restaure l'état depuis les caches.
	 */
	WsFormDbActionUI.prototype.restoreState = function() {
		// Récupérer d'abord la valeur de table sauvegardée par WS Form.
		this.selectedTable = this.$tableField.val() || '';

		// Charger le cache des tables.
		var tablesCache = this.parseJson( this.$tablesCacheField.val() );
		if ( tablesCache && tablesCache.tables && tablesCache.tables.length > 0 ) {
			this.tables = tablesCache.tables;
			this.selectedTable = tablesCache.selected || this.selectedTable;
			this.populateTables( this.tables, this.selectedTable );
			this.setButtonState( this.$loadTablesBtn, false );
			log( 'Tables restored from cache', this.tables.length, 'selected:', this.selectedTable );
		}

		// Charger le cache des colonnes.
		var columnsCache = this.parseJson( this.$columnsCacheField.val() );
		if ( columnsCache && columnsCache.length > 0 ) {
			this.columns = columnsCache;
			log( 'Columns restored from cache', columnsCache.length );
		}

		// Charger le mapping.
		var mappingCache = this.parseJson( this.$mappingField.val() );
		if ( mappingCache && Object.keys( mappingCache ).length > 0 ) {
			this.mapping = mappingCache;
			log( 'Mapping restored', this.mapping );
		}

		// Si on a des colonnes, afficher l'UI de mapping.
		if ( this.columns.length > 0 ) {
			this.renderMappingUI();
		}

		// Mettre à jour l'état des boutons.
		this.updateAnalyzeButtonState();

		// Si on a des credentials, activer le bouton charger.
		if ( this.getCredentials() ) {
			this.setButtonState( this.$loadTablesBtn, false );
		}
	};

	/**
	 * Parse du JSON de façon sécurisée.
	 */
	WsFormDbActionUI.prototype.parseJson = function( str ) {
		if ( ! str ) {
			return null;
		}
		try {
			return JSON.parse( str );
		} catch ( e ) {
			return null;
		}
	};

	/**
	 * Récupère les paramètres de connexion à envoyer au serveur.
	 *
	 * Avec une connexion nommée, seul son nom part : les identifiants restent
	 * dans wp-config.php et le serveur les résout lui-même.
	 *
	 * @return {Object|null} Paramètres, ou null s'ils sont incomplets.
	 */
	WsFormDbActionUI.prototype.getCredentials = function() {
		var connection = this.$connectionField.length && this.$connectionField.val() ? this.$connectionField.val() : '';

		if ( connection ) {
			return { connection: connection };
		}

		var host = this.$hostField.val() ? this.$hostField.val().trim() : '';
		var user = this.$userField.val() ? this.$userField.val().trim() : '';
		var password = this.$passwordField.val() || '';
		var port = this.$portField.val() || '3306';
		var database = this.$nameField.val() ? this.$nameField.val().trim() : '';

		if ( ! host || ! user || ! database ) {
			return null;
		}

		return {
			host: host,
			user: user,
			password: password,
			port: port,
			database: database,
		};
	};

	/**
	 * Effectue une requête AJAX.
	 */
	WsFormDbActionUI.prototype.request = function( action, extra ) {
		var credentials = this.getCredentials();

		if ( ! credentials ) {
			this.updateStatus( i18n( 'connectionMissing' ), 'error' );
			return $.Deferred().reject().promise();
		}

		var payload = $.extend( {}, credentials, extra || {}, {
			action: action,
			nonce: config.nonce
		} );

		return $.ajax( {
			url: config.ajaxUrl,
			method: 'POST',
			data: payload,
			dataType: 'json'
		} ).then(
			function( response ) {
				if ( response.success ) {
					return response.data;
				}
				var message = response.data && response.data.message ? response.data.message : 'Erreur';
				return $.Deferred().reject( new Error( message ) ).promise();
			},
			function( xhr, status, error ) {
				return $.Deferred().reject( new Error( error || status ) ).promise();
			}
		);
	};

	/**
	 * Met à jour le statut affiché.
	 */
	WsFormDbActionUI.prototype.updateStatus = function( message, type ) {
		if ( ! this.$statusEl || ! this.$statusEl.length ) {
			return;
		}

		this.$statusEl
			.text( message )
			.removeClass( 'wsf-db-status-success wsf-db-status-error wsf-db-status-info' )
			.addClass( 'wsf-db-status-' + ( type || 'info' ) );
	};

	/**
	 * Active/désactive un bouton.
	 */
	WsFormDbActionUI.prototype.setButtonState = function( $btn, disabled ) {
		if ( $btn && $btn.length ) {
			$btn.prop( 'disabled', disabled );
		}
	};

	/**
	 * Met à jour l'état du bouton Analyser.
	 */
	WsFormDbActionUI.prototype.updateAnalyzeButtonState = function() {
		var hasTable = this.$tableField.val() && this.$tableField.val() !== '';
		this.setButtonState( this.$analyzeBtn, ! hasTable );
	};

	/**
	 * Gère le test de connexion.
	 */
	WsFormDbActionUI.prototype.handleTestConnection = function() {
		var self = this;

		this.setButtonState( this.$testBtn, true );
		this.updateStatus( i18n( 'loading' ), 'info' );

		this.request( 'ws_form_db_test_connection' )
			.done( function( data ) {
				var msg = data.message || i18n( 'testSuccess' );
				self.updateStatus( msg, 'success' );
				self.setButtonState( self.$loadTablesBtn, false );
				log( 'Connexion OK' );
			} )
			.fail( function( error ) {
				var msg = error && error.message ? error.message : i18n( 'testError' );
				self.updateStatus( msg, 'error' );
				log( 'Connexion KO', error );
			} )
			.always( function() {
				self.setButtonState( self.$testBtn, false );
			} );
	};

	/**
	 * Gère le chargement des tables.
	 */
	WsFormDbActionUI.prototype.handleLoadTables = function() {
		var self = this;

		this.setButtonState( this.$loadTablesBtn, true );
		this.updateStatus( i18n( 'loading' ), 'info' );

		this.request( 'ws_form_db_get_tables' )
			.done( function( data ) {
				var tables = data.tables || [];
				self.tables = tables;
				self.populateTables( tables, '' );
				self.saveTablesToCache( tables, '' );
				self.updateStatus( i18n( 'tablesLoaded' ), 'success' );
				log( 'Tables loaded', tables.length );
			} )
			.fail( function( error ) {
				var msg = error && error.message ? error.message : i18n( 'tablesError' );
				self.updateStatus( msg, 'error' );
				log( 'Erreur tables', error );
			} )
			.always( function() {
				self.setButtonState( self.$loadTablesBtn, false );
			} );
	};

	/**
	 * Remplit le select des tables.
	 */
	WsFormDbActionUI.prototype.populateTables = function( tables, selectedValue ) {
		if ( ! this.$tableField || ! this.$tableField.length ) {
			return;
		}

		var html = '<option value="">' + i18n( 'selectTable' ) + '</option>';
		var i;

		for ( i = 0; i < tables.length; i++ ) {
			var selected = tables[ i ] === selectedValue ? ' selected' : '';
			html += '<option value="' + this.escapeAttr( tables[ i ] ) + '"' + selected + '>';
			html += this.escapeHtml( tables[ i ] );
			html += '</option>';
		}

		this.$tableField.html( html );

		// S'assurer que la valeur est bien définie.
		if ( selectedValue ) {
			this.$tableField.val( selectedValue );
		}

		this.updateAnalyzeButtonState();
	};

	/**
	 * Sauvegarde les tables dans le cache.
	 */
	WsFormDbActionUI.prototype.saveTablesToCache = function( tables, selected ) {
		var data = {
			tables: tables,
			selected: selected
		};
		this.$tablesCacheField.val( JSON.stringify( data ) ).trigger( 'change' );
	};

	/**
	 * Gère l'analyse de la table.
	 */
	WsFormDbActionUI.prototype.handleAnalyzeTable = function() {
		var self = this;
		var table = this.$tableField.val();

		if ( ! table ) {
			this.updateStatus( i18n( 'selectTableFirst' ), 'error' );
			return;
		}

		this.setButtonState( this.$analyzeBtn, true );
		this.updateStatus( i18n( 'loading' ), 'info' );

		// Sauvegarder la table sélectionnée.
		this.saveTablesToCache( this.tables, table );

		this.request( 'ws_form_db_analyze_table', { table: table } )
			.done( function( data ) {
				var columns = data.columns || [];
				self.columns = columns;
				self.saveColumnsToCache( columns );
				self.renderMappingUI();
				self.updateStatus( i18n( 'analyzeSuccess' ), 'success' );
				log( 'Table analyzed', columns.length );
			} )
			.fail( function( error ) {
				var msg = error && error.message ? error.message : i18n( 'analyzeError' );
				self.updateStatus( msg, 'error' );
				log( 'Erreur analyse', error );
			} )
			.always( function() {
				self.setButtonState( self.$analyzeBtn, false );
			} );
	};

	/**
	 * Sauvegarde les colonnes dans le cache.
	 */
	WsFormDbActionUI.prototype.saveColumnsToCache = function( columns ) {
		this.$columnsCacheField.val( JSON.stringify( columns ) ).trigger( 'change' );
	};

	/**
	 * Affiche l'interface de mapping.
	 */
	WsFormDbActionUI.prototype.renderMappingUI = function() {
		if ( ! this.$mappingUi || ! this.$mappingUi.length ) {
			return;
		}

		var $columnsEl = this.$mappingUi.find( '[data-wsf-db-columns]' );
		var $container = this.$mappingUi.find( '[data-wsf-db-mapping-rows]' );

		if ( ! this.columns || this.columns.length === 0 ) {
			$columnsEl.html( '<p>' + i18n( 'noColumns' ) + '</p>' );
			this.$mappingUi.hide();
			return;
		}

		this.$mappingUi.show();

		// Masquer la zone des colonnes (plus nécessaire).
		$columnsEl.empty();

		// Construire l'interface de mapping.
		this.renderMappingRows( $container );
	};

	/**
	 * Affiche les lignes de mapping.
	 */
	WsFormDbActionUI.prototype.renderMappingRows = function( $container ) {
		var self = this;
		var html = '<div class="wsf-db-section-title">Mapping des colonnes</div>';
		html += '<p class="wsf-db-mapping-intro">';
		html += i18n( 'mappingIntro' );
		html += '</p>';
		html += '<div class="wsf-db-mapping-repeater">';
		html += '<div class="wsf-db-mapping-rows-list"></div>';
		html += '<button type="button" class="wsf-button wsf-button-small wsf-db-add-mapping">';
		html += '+ ' + i18n( 'addMapping' );
		html += '</button>';
		html += '</div>';

		$container.find( '.wsf-db-mapping-repeater' ).remove();
		$container.find( '.wsf-db-section-title' ).remove();
		$container.find( '.wsf-db-mapping-intro' ).remove();
		$container.append( html );

		var $rowsList = $container.find( '.wsf-db-mapping-rows-list' );
		var $addBtn = $container.find( '.wsf-db-add-mapping' );

		// Ajouter les lignes existantes du mapping.
		var mappingKeys = Object.keys( this.mapping );
		if ( mappingKeys.length > 0 ) {
			for ( var k = 0; k < mappingKeys.length; k++ ) {
				this.addMappingRow( $rowsList, mappingKeys[ k ], this.mapping[ mappingKeys[ k ] ] );
			}
		} else {
			// Ajouter une ligne vide par défaut.
			this.addMappingRow( $rowsList, '', '' );
		}

		// Événement pour ajouter une ligne.
		$addBtn.on( 'click', function( e ) {
			e.preventDefault();
			self.addMappingRow( $rowsList, '', '' );
		} );
	};

	/**
	 * Ajoute une ligne de mapping.
	 */
	WsFormDbActionUI.prototype.addMappingRow = function( $rowsList, column, value ) {
		var self = this;
		var rowId = 'row-' + Date.now() + '-' + Math.random().toString( 36 ).substr( 2, 5 );
		var metaKey = 'wsf_db_value_' + rowId;

		var html = '<div class="wsf-db-mapping-row" data-row-id="' + rowId + '">';

		// Header avec select et bouton supprimer.
		html += '<div class="wsf-db-mapping-row-header">';

		// Select pour la colonne.
		html += '<select class="wsf-db-column-select wsf-field">';
		html += '<option value="">' + i18n( 'selectColumn' ) + '</option>';

		var usedColumns = this.getUsedColumns();
		var i;
		for ( i = 0; i < this.columns.length; i++ ) {
			var col = this.columns[ i ];
			var isUsed = usedColumns.indexOf( col.name ) !== -1 && col.name !== column;
			var selected = col.name === column ? ' selected' : '';
			var disabled = isUsed ? ' disabled' : '';
			html += '<option value="' + this.escapeAttr( col.name ) + '"' + selected + disabled + '>';
			html += this.escapeHtml( col.name ) + ' (' + this.escapeHtml( col.type ) + ')';
			if ( isUsed ) {
				html += ' - ' + i18n( 'alreadyUsed' );
			}
			html += '</option>';
		}
		html += '</select>';

		// Bouton supprimer.
		html += '<button type="button" class="wsf-db-remove-row" title="Supprimer">×</button>';

		html += '</div>';

		// Textarea avec bouton variable helper (structure WS Form).
		html += '<div class="wsf-db-mapping-row-value">';
		html += '<div class="wsf-field-wrapper">';
		html += '<textarea class="wsf-db-value-textarea wsf-field" ';
		html += 'data-meta-key="' + metaKey + '" ';
		html += 'placeholder="' + i18n( 'valuePlaceholder' ) + '" ';
		html += 'rows="2">' + this.escapeHtml( value ) + '</textarea>';
		
		// Bouton variable helper WS Form natif.
		html += '<ul class="wsf-field-options">';
		html += '<li>';
		html += '<div data-action="wsf-variable-helper" ';
		html += 'data-option-meta-key="' + metaKey + '" ';
		html += 'title="Variables">';
		html += '<svg height="16" width="16" viewBox="0 0 16 16">';
		html += '<path d="m6.6 6.4-.4 3.2h3.2l.3-3.2H6.6zm.1-1.6h3.2l.5-4.8H12l-.5 4.8h3.7L15 6.4h-3.7L11 9.6h3.4l-.2 1.6h-3.4l-.4 4.8H8.8l.5-4.8H6.1L5.6 16H4l.5-4.8H.8L1 9.6h3.7L5 6.4H1.6l.2-1.6h3.4L5.6 0h1.6l-.5 4.8z"></path>';
		html += '</svg>';
		html += '</div>';
		html += '</li>';
		html += '</ul>';
		
		html += '</div>';
		html += '</div>';

		html += '</div>';

		var $row = $( html );
		$rowsList.append( $row );

		// Événements.
		$row.find( '.wsf-db-column-select' ).on( 'change', function() {
			self.onMappingChange();
			self.updateColumnSelects();
		} );

		$row.find( '.wsf-db-value-textarea' ).on( 'input change', function() {
			self.onMappingChange();
		} );

		$row.find( '.wsf-db-remove-row' ).on( 'click', function( e ) {
			e.preventDefault();
			$row.remove();
			self.onMappingChange();
			self.updateColumnSelects();
		} );
	};

	/**
	 * Récupère les colonnes déjà utilisées.
	 */
	WsFormDbActionUI.prototype.getUsedColumns = function() {
		var used = [];
		this.$mappingUi.find( '.wsf-db-column-select' ).each( function() {
			var val = $( this ).val();
			if ( val ) {
				used.push( val );
			}
		} );
		return used;
	};

	/**
	 * Met à jour les selects de colonnes.
	 */
	WsFormDbActionUI.prototype.updateColumnSelects = function() {
		var usedColumns = this.getUsedColumns();

		this.$mappingUi.find( '.wsf-db-column-select' ).each( function() {
			var $select = $( this );
			var currentVal = $select.val();

			$select.find( 'option' ).each( function() {
				var $option = $( this );
				var optionVal = $option.val();

				if ( ! optionVal ) {
					return;
				}

				var isUsed = usedColumns.indexOf( optionVal ) !== -1 && optionVal !== currentVal;
				$option.prop( 'disabled', isUsed );
			} );
		} );
	};

	/**
	 * Gère le changement de mapping.
	 */
	WsFormDbActionUI.prototype.onMappingChange = function() {
		var mapping = {};

		this.$mappingUi.find( '.wsf-db-mapping-row' ).each( function() {
			var $row = $( this );
			var column = $row.find( '.wsf-db-column-select' ).val();
			var value = $row.find( '.wsf-db-value-textarea' ).val();

			if ( column && value ) {
				mapping[ column ] = value;
			}
		} );

		this.mapping = mapping;
		this.persistMapping( mapping );
	};

	/**
	 * Persiste le mapping dans le champ caché.
	 */
	WsFormDbActionUI.prototype.persistMapping = function( mapping ) {
		if ( ! this.$mappingField || ! this.$mappingField.length ) {
			return;
		}

		this.$mappingField.val( JSON.stringify( mapping ) ).trigger( 'change' );
		log( 'Mapping saved', mapping );
	};

	/**
	 * Échappe du HTML.
	 */
	WsFormDbActionUI.prototype.escapeHtml = function( str ) {
		if ( ! str ) {
			return '';
		}
		var div = document.createElement( 'div' );
		div.textContent = str;
		return div.innerHTML;
	};

	/**
	 * Échappe un attribut HTML.
	 */
	WsFormDbActionUI.prototype.escapeAttr = function( str ) {
		if ( ! str ) {
			return '';
			}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /"/g, '&quot;' )
			.replace( /'/g, '&#39;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' );
	};

	// Instance active.
	var currentInstance = null;

	/**
	 * Tente d'initialiser l'UI si le sidebar d'action est ouvert.
	 */
	function tryInitialize() {
		var $sidebar = $( '#wsf-sidebar-action' );

		if ( ! $sidebar.length || ! $sidebar.is( ':visible' ) ) {
			return;
		}

		var $buttons = $sidebar.find( '[data-wsf-db-test-connection]' );

		if ( ! $buttons.length ) {
			return;
		}

		if ( $buttons.data( 'wsf-db-initialized' ) ) {
			return;
		}

		$buttons.data( 'wsf-db-initialized', true );
		currentInstance = new WsFormDbActionUI( $sidebar );
	}

	/**
	 * Observe les mutations du DOM.
	 */
	function observeSidebar() {
		var observer = new MutationObserver( function( mutations ) {
			var shouldTry = false;
			var i, mutation;

			for ( i = 0; i < mutations.length; i++ ) {
				mutation = mutations[ i ];

				if ( mutation.type === 'childList' && mutation.addedNodes.length > 0 ) {
					shouldTry = true;
					break;
		}

				if ( mutation.type === 'attributes' ) {
					shouldTry = true;
					break;
				}
			}

			if ( shouldTry ) {
				tryInitialize();
			}
		} );

		var targetNode = document.getElementById( 'wsf-sidebars' );
		if ( ! targetNode ) {
			targetNode = document.body;
		}

		observer.observe( targetNode, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [ 'class', 'style' ]
		} );

		log( 'Observer started' );
		}

	/**
	 * Point d'entrée.
	 */
	function init() {
		if ( ! config.ajaxUrl || ! config.nonce ) {
			log( 'Configuration manquante', config );
			return;
		}

		tryInitialize();
		observeSidebar();
		initVariableHelper();

		$( document ).on( 'wsf-sidebar-action-open wsf-sidebar-open', function() {
			setTimeout( tryInitialize, 100 );
		} );

		log( 'Script loaded' );
	}

	/**
	 * Initialise le gestionnaire de variable helper.
	 */
	function initVariableHelper() {
		// Écouter les clics sur nos boutons variable helper.
		$( document ).on( 'click', '.wsf-db-mapping-row-value [data-action="wsf-variable-helper"]', function( e ) {
			e.preventDefault();
			e.stopPropagation();

			var $btn = $( this );
			var metaKey = $btn.attr( 'data-option-meta-key' );
			var $textarea = $btn.closest( '.wsf-field-wrapper' ).find( '[data-meta-key="' + metaKey + '"]' );

			log( 'Variable helper clicked, meta key:', metaKey );

			// Vérifier si WS Form est disponible.
			if ( typeof $.WS_Form !== 'undefined' && $.WS_Form.this && typeof $.WS_Form.this.variable_helper_modal_open === 'function' ) {
				log( 'Opening WS Form modal' );
				$.WS_Form.this.variable_helper_modal_open( $textarea, false );
			} else {
				log( 'WS Form variable_helper_modal_open non disponible' );
				// Fallback : afficher un message.
				alert( i18n( 'variablesFallback' ) );
			}
		} );

		log( 'Variable helper initialized' );
	}

	$( document ).ready( init );

} )( jQuery );
