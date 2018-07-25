<?php
/**
 * Setup SpecialPage for MABS extension
 *
 * @file
 * @ingroup Extensions
 */
namespace MediaWiki\Extension\MABS\Special;

use HTMLForm;
use Mediawiki\MediaWikiServices;
use MWException;
use SpecialPage;

class MABS extends SpecialPage {
	public function __construct() {
		parent::__construct( 'mabs' );
	}

	/**
	 * Where this should be listed on the special pages
	 * @return string
	 */
	protected function getGroupName() {
		return 'wiki';
	}

	/**
	 * Show the page to the user
	 *
	 * @param string $sub The subpage string argument (if any).
	 */
	public function execute( $sub ) {
		$out = $this->getOutput();
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( "MABS" );

		$out->setPageTitle( $this->msg( 'mabs-setup' ) );
		$out->addWikiMsg( 'mabs-setup-intro' );

		$this->writable = $config->get( "repo" );

		$steps = [ 'getNotWritable', 'getInitialForm' ];
		$htmlForm = null;
		foreach ( $steps as $step ) {
			if ( !isset( $htmlForm ) ) {
				$htmlForm = $this->$step();
			}
		}

		if ( $htmlForm === null ) {
			throw new MWException( "wrong!" );
		}
		$htmlForm->show();
	}

	/**
	 * Tell the user to create a writable directory
	 *
	 * @return HTMLForm|null
	 */
	private function getNotWritable() {
		$msg = false;
		$htmlForm = null;
		$submit = wfMessage( "mabs-config-try-again" )->parse();
		$callback = [ __CLASS__, 'trySubmit' ];

		if ( !file_exists( $this->writable ) ) {
			$msg = wfMessage( "mabs-config-please-fix-exists" );
		}
		if ( !$msg && !is_dir( $this->writable ) ) {
			$msg = wfMessage( "mabs-config-please-fix-directory" );
		}
		if ( !$msg && !is_writable( $this->writable ) ) {
			$msg = wfMessage( "mabs-config-please-fix-writable" );
		}
		if ( $msg ) {
			$form = [
				'info' => [
					'section' => 'mabs-config-prepare-section',
					'type' => 'info',
					'default' => $msg->params( $this->writable )->parse(),
					'help' => wfMessage( "mabs-config-fix-problems" )->parse(),
					'raw' => true,
				]
			];
			$htmlForm = HTMLForm::factory( 'ooui', $form, $this->getContext(), 'repoform' );
			$htmlForm->setSubmitText( $submit );
			$htmlForm->setSubmitCallback( $callback );
		}
		return $htmlForm;
	}

	/**
	 * Get the form for initially creating the repo.
	 *
	 * @return HTMLForm|null
	 */
	private function getInitialForm() {
		$msg = false;
		$submit = wfMessage( "mabs-config-try-again" )->parse();
		$callback = [ __CLASS__, 'initRepo' ];
		$help = null;
		$gitDir = $this->writable . "/.git";
		if ( file_exists( $gitDir ) && !is_writable( $gitDir ) ) {
			$msg = wfMessage( "mabs-config-gitdir-not-writable" );
			$help = wfMessage( "mabs-config-fix-problems" )->parse();
		}

		$config = $this->writable . "/.git/config";
		if ( !$msg && !file_exists( $config ) ) {
			$msg = wfMessage( "mabs-config-not-exists" );
			$submit = wfMessage( "mabs-config-create" )->parse();
		}

		if ( !$msg && !is_writable( $config ) ) {
			$msg = wfMessage( "mabs-config-not-writable" );
			$help = wfMessage( "mabs-config-fix-problems" )->parse();
		}

		$htmlForm = null;
		if ( $msg ) {
			$form = [
				'info' => [
					'section' => 'mabs-config-initialize-section',
					'type' => 'info',
					'default' => $msg->params( $gitDir, $config )->parse(),
					'help' => $help,
					'raw' => true,
				]
			];
			$htmlForm = HTMLForm::factory( 'ooui', $form, $this->getContext(), 'repoform' );
			$htmlForm->setSubmitText( $submit );
			$htmlForm->setSubmitCallback( $callback );
		}
		return $htmlForm;
	}

	/**
	 * @param array $formData data from submission
	 * @return bool|string
	 */
	public static function trySubmit( array $formData ) {
	}

	/**
	 * @param array $formData data from submission
	 * @return bool|string
	 */
	public static function initRepo( array $formData ) {
	}
}
