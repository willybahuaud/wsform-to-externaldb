<?php
/**
 * Nettoyage à la suppression du plugin.
 *
 * Les réglages des actions vivent dans les formulaires WS Form : ils restent,
 * comme toute autre configuration de formulaire. Le cache de structure des
 * tables est un transient de 5 minutes, il expire seul.
 *
 * @package WS_Form_DB
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ws_form_db_failures' );
delete_option( 'ws_form_db_logging_enabled' );
delete_option( 'ws_form_db_logging_level' );
