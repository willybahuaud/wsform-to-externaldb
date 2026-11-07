/**
 * Éditeur des lignes de mapping colonne => modèle de valeur.
 */

import { escapeHtml, i18nHtml } from './helpers';

const $ = window.jQuery;

/**
 * Icône du bouton de variables de WS Form.
 *
 * @type {string}
 */
const VARIABLES_ICON =
	'<svg height="16" width="16" viewBox="0 0 16 16"><path d="m6.6 6.4-.4 3.2h3.2l.3-3.2H6.6zm.1-1.6h3.2l.5-4.8H12l-.5 4.8h3.7L15 6.4h-3.7L11 9.6h3.4l-.2 1.6h-3.4l-.4 4.8H8.8l.5-4.8H6.1L5.6 16H4l.5-4.8H.8L1 9.6h3.7L5 6.4H1.6l.2-1.6h3.4L5.6 0h1.6l-.5 4.8z"></path></svg>';

/**
 * Compteur des lignes, pour des meta keys uniques.
 *
 * @type {number}
 */
let rowCounter = 0;

/**
 * Affiche les lignes de mapping et signale chaque modification.
 */
export default class MappingEditor {
	/**
	 * @param {jQuery}   $container Conteneur des lignes.
	 * @param {Function} onChange   Reçoit le mapping à chaque modification.
	 */
	constructor( $container, onChange ) {
		this.$container = $container;
		this.onChange = onChange;
		this.columns = [];
		this.$rowsList = null;

		this.handleAddClick = this.handleAddClick.bind( this );
		this.handleRowsChange = this.handleRowsChange.bind( this );
		this.handleRemoveClick = this.handleRemoveClick.bind( this );
	}

	/**
	 * Affiche l'éditeur pour ces colonnes et ce mapping.
	 *
	 * @param {Object[]} columns Colonnes de la table.
	 * @param {Object}   mapping Mapping enregistré, colonne => modèle.
	 */
	render( columns, mapping ) {
		this.columns = columns;

		this.$container
			.empty()
			.append(
				'<div class="wsf-db-section-title">' +
					i18nHtml( 'mappingTitle' ) +
					'</div>' +
					'<p class="wsf-db-mapping-intro">' +
					i18nHtml( 'mappingIntro' ) +
					'</p>' +
					'<div class="wsf-db-mapping-repeater">' +
					'<div class="wsf-db-mapping-rows-list"></div>' +
					'<button type="button" class="wsf-button wsf-button-small wsf-db-add-mapping">+ ' +
					i18nHtml( 'addMapping' ) +
					'</button>' +
					'</div>'
			);

		this.$rowsList = this.$container.find( '.wsf-db-mapping-rows-list' );
		this.$container.find( '.wsf-db-add-mapping' ).on( 'click', this.handleAddClick );

		// Délégation : les lignes ajoutées plus tard sont couvertes aussi.
		this.$rowsList.on(
			'input change',
			'.wsf-db-column-select, .wsf-db-value-textarea',
			this.handleRowsChange
		);
		this.$rowsList.on( 'click', '.wsf-db-remove-row', this.handleRemoveClick );

		const columnNames = Object.keys( mapping );

		if ( 0 === columnNames.length ) {
			this.addRow( '', '' );
			return;
		}

		for ( const columnName of columnNames ) {
			this.addRow( columnName, mapping[ columnName ] );
		}
	}

	/**
	 * Ajoute une ligne de mapping.
	 *
	 * @param {string} column Colonne choisie.
	 * @param {string} value  Modèle de valeur.
	 */
	addRow( column, value ) {
		rowCounter++;
		const metaKey = 'wsf_db_value_' + rowCounter;

		this.$rowsList.append(
			'<div class="wsf-db-mapping-row">' +
				'<div class="wsf-db-mapping-row-header">' +
				this.buildColumnSelect( column ) +
				'<button type="button" class="wsf-db-remove-row" title="' +
				i18nHtml( 'removeRow' ) +
				'">×</button>' +
				'</div>' +
				'<div class="wsf-db-mapping-row-value"><div class="wsf-field-wrapper">' +
				'<textarea class="wsf-db-value-textarea wsf-field" rows="2" data-meta-key="' +
				metaKey +
				'" placeholder="' +
				i18nHtml( 'valuePlaceholder' ) +
				'">' +
				escapeHtml( value ) +
				'</textarea>' +
				// Même structure que les champs WS Form, pour réutiliser son sélecteur de variables.
				'<ul class="wsf-field-options"><li><div data-action="wsf-variable-helper" data-option-meta-key="' +
				metaKey +
				'" title="' +
				i18nHtml( 'variables' ) +
				'">' +
				VARIABLES_ICON +
				'</div></li></ul>' +
				'</div></div>' +
				'</div>'
		);
	}

	/**
	 * Construit la liste des colonnes d'une ligne.
	 *
	 * Une colonne déjà utilisée par une autre ligne est désactivée.
	 *
	 * @param {string} selectedColumn Colonne choisie sur cette ligne.
	 * @return {string} HTML du select.
	 */
	buildColumnSelect( selectedColumn ) {
		const usedColumns = this.getUsedColumns();
		let html =
			'<select class="wsf-db-column-select wsf-field"><option value="">' +
			i18nHtml( 'selectColumn' ) +
			'</option>';

		for ( const column of this.columns ) {
			const isUsed = usedColumns.includes( column.name ) && column.name !== selectedColumn;

			html +=
				'<option value="' +
				escapeHtml( column.name ) +
				'"' +
				( column.name === selectedColumn ? ' selected' : '' ) +
				( isUsed ? ' disabled' : '' ) +
				'>' +
				escapeHtml( column.name ) +
				' (' +
				escapeHtml( column.type ) +
				')' +
				( isUsed ? ' - ' + i18nHtml( 'alreadyUsed' ) : '' ) +
				'</option>';
		}

		return html + '</select>';
	}

	/**
	 * Retourne les colonnes choisies sur l'ensemble des lignes.
	 *
	 * @return {string[]} Noms de colonnes.
	 */
	getUsedColumns() {
		if ( ! this.$rowsList ) {
			return [];
		}

		return this.$rowsList
			.find( '.wsf-db-column-select' )
			.toArray()
			.map( readSelectValue )
			.filter( Boolean );
	}

	/**
	 * Désactive dans chaque ligne les colonnes prises par une autre.
	 */
	refreshColumnSelects() {
		const usedColumns = this.getUsedColumns();

		for ( const select of this.$rowsList.find( '.wsf-db-column-select' ).toArray() ) {
			for ( const option of select.options ) {
				option.disabled =
					'' !== option.value &&
					option.value !== select.value &&
					usedColumns.includes( option.value );
			}
		}
	}

	/**
	 * Lit le mapping affiché. Les lignes incomplètes sont ignorées.
	 *
	 * @return {Object} Mapping colonne => modèle.
	 */
	readMapping() {
		const mapping = {};

		for ( const row of this.$rowsList.find( '.wsf-db-mapping-row' ).toArray() ) {
			const column = $( row ).find( '.wsf-db-column-select' ).val();
			const value = $( row ).find( '.wsf-db-value-textarea' ).val();

			if ( column && value ) {
				mapping[ column ] = value;
			}
		}

		return mapping;
	}

	/**
	 * Ajoute une ligne vide.
	 *
	 * @param {Event} event Clic.
	 */
	handleAddClick( event ) {
		event.preventDefault();
		this.addRow( '', '' );
	}

	/**
	 * Répercute la modification d'une ligne.
	 *
	 * @param {Event} event Saisie ou changement.
	 */
	handleRowsChange( event ) {
		if ( $( event.target ).hasClass( 'wsf-db-column-select' ) ) {
			this.refreshColumnSelects();
		}

		this.onChange( this.readMapping() );
	}

	/**
	 * Supprime une ligne.
	 *
	 * @param {Event} event Clic.
	 */
	handleRemoveClick( event ) {
		event.preventDefault();
		$( event.currentTarget ).closest( '.wsf-db-mapping-row' ).remove();
		this.refreshColumnSelects();
		this.onChange( this.readMapping() );
	}
}

/**
 * Lit la valeur d'un select.
 *
 * @param {HTMLSelectElement} select Select.
 * @return {string} Valeur.
 */
function readSelectValue( select ) {
	return select.value;
}
