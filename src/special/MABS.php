<?php
/**
 * SpecialPage for MABS extension
 *
 * Copyright (C) 2018  NicheWork, LLC
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @file
 * @ingroup Extensions
 * @author Mark A. Hershberger <mah@nichework.com>
 */
namespace MediaWiki\Extension\MABS\Special;

use ErrorPageError;
use GitWrapper\GitWrapper;
use HTMLForm;
use MediaWiki\Extension\MABS\Config;
use Mediawiki\MediaWikiServices;
use SpecialPage;
use Wikimedia;

class MABS extends SpecialPage {
	protected $steps;
	protected $page;
	static protected $gitDir;

	/**
	 * @param string|null $page short name for this page class
	 */
	public function __construct( $page = null ) {
		parent::__construct( 'mabs', 'mabs' );
		$this->page = $page;
	}

	/**
	 * Where this should be listed on the special pages
	 * @return string
	 */
	protected function getGroupName() {
		return 'wiki';
	}

	/**
	 * Get an initialized GitWrapper
	 *
	 * @return GitWrapper\GitWorkingCopy
	 */
	protected static function getGit() {
		$git = new GitWrapper;
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( "MABS" );

		$mgrDir = dirname( dirname( __DIR__ ) ) . "lib/mediawiki-git-remote";
		$git->setEnvVar( "PERL5LIB", "$mgrDir/lib:$mgrDir/localcpan" );
		$git->setEnvVar( "GIT_EXEC_PATH", $mgrDir );

		self::$gitDir = $config->get( Config::REPO );
		if ( !chdir( self::$gitDir ) ) {
			throw new ErrorPageError( "mabs-system-error", "mabs-no-chdir", self::$gitDir );
		}
		return $git->workingCopy( self::$gitDir );
	}

	/**
	 * Show the page to the user
	 *
	 * @param string $sub The subpage string argument (if any).
	 */
	public function execute( $sub ) {
		$this->checkPermissions();
		$out = $this->getOutput();
		$out->setPageTitle( $this->msg( 'mabs-setup' ) );
		$out->addWikiMsg( 'mabs-setup-intro' );
		$this->page = "setup";

		if ( $sub ) {
			$this->page = strtolower( $sub );
		}

		$this->pageClass = $this->fetchPageClass( $this->page );
		if ( $this->pageClass ) {
			$this->doWizard();
			return;
		}
		throw new ErrorPageError( "mabs-no-wizard", "mabs-dev-needed", [ $this->page ] );
	}

	/**
	 * Get an object to handle this page
	 *
	 * @param string $page for the object
	 * @return null|object
	 */
	public function fetchPageClass( $page ) {
		$class = __CLASS__ . '\\' . ucFirst( $page );
		if ( $this->classExists( $class ) ) {
			return new $class( $page );
		}
	}

	/**
	 * Quietly determine if a class exists.
	 *
	 * @param string $class to check for
	 * @return bool
	 */
	public function classExists( $class ) {
		Wikimedia\suppressWarnings();
		$exists = class_exists( $class );
		Wikimedia\restoreWarnings();
		return $exists;
	}

	/**
	 * Get the steps for this wizared
	 *
	 * @return string[]
	 */
	protected function getSteps() {
		if ( !$this->steps ) {
			throw new ErrorPageError(
				"mabs-no-wizard-step-list", "mabs-dev-needed-step-list", [ $this->page ]
			);
		}
		return $this->steps;
	}

	/**
	 * Run through the list of pages to get form elements to display
	 */
	public function doWizard() {
		$htmlForm = null;
		foreach ( $this->pageClass->getSteps() as $step ) {
			if ( !isset( $htmlForm ) ) {
				$this->formStep = $this->page . "-$step";
				$htmlForm = $this->doStep( $step );
			}
		}

		if ( $htmlForm === null ) {
			throw new MWException( "You shouldn't get here." );
		}

		$htmlForm->show();
	}

	private function doStep( &$step ) {
		$submit = wfMessage( "mabs-config-try-again" )->parse();

		if ( !method_exists( $this->pageClass, "handle$step" ) ) {
			throw new ErrorPageError(
				"mabs-no-wizard-step", "mabs-dev-needed-step", [ $step, $this->page ]
			);
		}

		$form = $this->pageClass->{"handle$step"}( $this->formStep, $submit, $callback );

		$htmlForm = null;
		if ( $form ) {
			$htmlForm = HTMLForm::factory( 'ooui', $form, $this->getContext(), 'repoform' );
			$htmlForm->setSubmitText( $submit );
			$htmlForm->setSubmitCallback( $callback );
			$htmlForm->setFormIdentifier( $this->formStep );
		}
		return $htmlForm;
	}
}
