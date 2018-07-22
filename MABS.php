<?php

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'MABS' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['MABS'] = __DIR__ . '/i18n';
	$wgExtensionMessagesFiles['MABSAlias'] = __DIR__ . '/MABS.i18n.alias.php';
	wfWarn(
		'Deprecated PHP entry point used for MABS extension. Please use wfLoadExtension ' .
		'instead, see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return true;
} else {
	die( 'This version of the MABS extension requires MediaWiki 1.25+' );
}
