<?php
/**
 * Setup SpecialPage for MABS extension
 *
 * @file
 * @ingroup Extensions
 */
namespace MediaWiki\Extension\MABS\Special;

use Gitonomy\Git\Admin;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Repository;
use HTMLForm;
use Mediawiki\MediaWikiServices;
use MediaWiki\Extension\MABS\Config;
use MWException;
use SpecialPage;
use Status;
use Wikimedia;

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

		$this->writable = $config->get( Config::REPO );

		$steps = [ 'getSoftwareDependencies', 'getNotWritable', 'getInitialForm', 'complete' ];
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
	 * Look for any missing software dependencies.  Some duplication here.
	 *
	 * @return HTMLForm|null
	 */
	private function getSoftwareDependencies() {
		$msg = false;
		$htmlForm = null;
		$submit = wfMessage( "mabs-config-try-again" )->parse();
		$callback = [ __CLASS__, 'trySubmit' ];
		$step = "dependency";

		Wikimedia\suppressWarnings();
		if ( !class_exists( 'Gitonomy\Git\Repository' ) ) {
			$msg = wfMessage( "mabs-dependency-gitonomy" );
		}
		Wikimedia\restoreWarnings();

		if ( !$msg ) {
			Wikimedia\suppressWarnings();
			try {
				$repo = new Repository( "/" );
				if ( !( $repo instanceof Repository ) ) {
					$msg = wfMessage( "mabs-dependency-gitonomy" );
				}
			} catch ( Exception $e ) {
			}
			Wikimedia\restoreWarnings();
		}

		if ( $msg ) {
			$form = [
				'info' => [
					'section' => "mabs-config-$step-section",
					'type' => 'info',
					'default' => $msg->params(
						"[http://gitonomy.com/doc/gitlib/master/ Gitonomy]"
					)->parse(),
					'help' => wfMessage( "mabs-config-fix-problems" )->parse(),
					'raw' => true,
				]
			];
			$htmlForm = HTMLForm::factory( 'ooui', $form, $this->getContext(), 'repoform' );
			$htmlForm->setSubmitText( $submit );
			$htmlForm->setSubmitCallback( $callback );
			$htmlForm->setFormIdentifier( $step );
		}
		return $htmlForm;
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
		$step = "prepare";
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
					'section' => "mabs-config-$step-section",
					'type' => 'info',
					'default' => $msg->params( $this->writable )->parse(),
					'help' => wfMessage( "mabs-config-fix-problems" )->parse(),
					'raw' => true,
				]
			];
			$htmlForm = HTMLForm::factory( 'ooui', $form, $this->getContext(), 'repoform' );
			$htmlForm->setSubmitText( $submit );
			$htmlForm->setSubmitCallback( $callback );
			$htmlForm->setFormIdentifier( $step );
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
		$gitDir = $this->writable;
		$step = "initialize";
		if ( file_exists( $gitDir ) && !is_dir( $gitDir ) ) {
			$msg = wfMessage( "mabs-config-please-fix-directory" );
			$help = wfMessage( "mabs-config-fix-problems" )->parse();
		}

		if ( !$msg && file_exists( $gitDir ) && !is_writable( $gitDir ) ) {
			$msg = wfMessage( "mabs-config-gitdir-not-writable" );
			$help = wfMessage( "mabs-config-fix-problems" )->parse();
		}

		$config = "$gitDir/config";
		if ( !$msg && file_exists( $config ) && !is_writable( $config ) ) {
			$msg = wfMessage( "mabs-config-not-writable" );
			$help = wfMessage( "mabs-config-fix-problems" )->parse();
		}

		if ( !$msg && !file_exists( $config ) ) {
			$msg = wfMessage( "mabs-config-not-exists" );
			$submit = wfMessage( "mabs-config-create" )->parse();
		}

		$htmlForm = null;
		if ( $msg ) {
			$form = [
				'info' => [
					'section' => "mabs-config-$step-section",
					'type' => 'info',
					'default' => $msg->params( $gitDir, $config )->parse(),
					'help' => $help,
					'raw' => true,
				]
			];
			$htmlForm = HTMLForm::factory( 'ooui', $form, $this->getContext(), 'repoform' );
			$htmlForm->setSubmitText( $submit );
			$htmlForm->setSubmitCallback( $callback );
			$htmlForm->setFormIdentifier( $step );
		}
		return $htmlForm;
	}

	/**
	 * Empty (for now, at least) submit handler to go to the next step.
	 *
	 * @param array $formData data from submission
	 * @return bool|string
	 */
	public static function trySubmit( array $formData ) {
	}

	/**
	 * Everything has been checked, do the initialization
	 *
	 * @param array $formData data from submission
	 * @return bool|string
	 */
	public static function initRepo( array $formData ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( "MABS" );

		try {
			Admin::init( $config->get( "repo" ) );
		} catch ( RuntimeException $e ) {
			return Status::newFatal( "mabs-config-init-repo", $e->getMessage() );
		}
	}

	/**
	 * Last page of the wizard
	 *
	 * @return HTMLForm
	 */
	private function complete() {
		$msg = false;
		$htmlForm = null;
		$submit = wfMessage( "mabs-config-continue" )->parse();
		$callback = [ __CLASS__, 'trySubmit' ];
		$step = "complete";

		$form = [
			'info' => [
				'section' => "mabs-config-$step-section",
				'type' => 'info',
				'default' => wfMessage( "mabs-config-complete" )->parse(),
				'raw' => true,
			]
		];
		$htmlForm = HTMLForm::factory( 'ooui', $form, $this->getContext(), 'repoform' );
		$htmlForm->setSubmitText( $submit );
		$htmlForm->setSubmitCallback( $callback );
		$htmlForm->setFormIdentifier( $step );

		return $htmlForm;
	}
}
