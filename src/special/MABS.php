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
use MediaWiki\MediaWikiServices;
use RequestContext;
use SpecialPage;
use Wikimedia;

class MABS extends SpecialPage {
	protected $steps;
	protected $page;
	protected $pageClass;
	protected $mabsConf;
	protected static $git;
	protected static $gitDir;

	/**
	 * @param string|null $page short name for this page class
	 */
	public function __construct( $page = null ) {
		parent::__construct( 'mabs' );
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
	 * Return the directory for the git repository
	 *
	 * @return string
	 */
	protected static function getGitDir() {
		if ( !self::$gitDir ) {
			$conf = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( "MABS" );
			self::$gitDir = $conf->get( Config::REPO );
		}
		return self::$gitDir;
	}

	/**
	 * Get a pre-initialized git wrapper
	 *
	 * @return GitWrapper\GitWorkingCopy
	 */
	protected static function getGitWrapper() {
		$dir = self::getGitDir();
		if ( !self::$git ) {
			self::$git = new GitWrapper();

			$extDir = MediaWikiServices::getInstance()->getMainConfig()->get( "ExtensionDirectory" );
			self::$git->setEnvVar( "PERL5LIB", "$extDir/MABS/lib/mediawiki-git-remote/lib:"
							 . "$extDir/MABS/lib/mediawiki-git-remote/localcpan" );
			self::$git->setEnvVar( "GIT_EXEC_PATH", "$extDir/MABS/lib/mediawiki-git-remote" );
			self::$git->setEnvVar( "GIT_MW_DEBUG", "1" );
			self::$git->setEnvVar( "GIT_TRACE", "0" );
			if ( !chdir( $dir ) ) {
				throw new ErrorPageError( "mabs-system-error", "mabs-no-chdir", $dir );
			}
		}
		return self::$git->workingCopy( $dir );
	}

	/**
	 * Show the page to the user
	 *
	 * @param string $sub The subpage string argument (if any).
	 */
	public function execute( $sub ) {
		$out = $this->getOutput();
		$this->page = "setup";

		if ( $sub ) {
			$this->page = strtolower( $sub );
		}

		$title = $this->msg( "mabs-{$this->page}-title" );
		if ( !$title->exists() ) {
			$title = $this->msg( "mabs" );
		}
		$out->setPageTitle( $title );

		$intro = $this->msg( "mabs-{$this->page}-intro" );
		if ( !$intro->exists() ) {
			$intro = $this->msg( "mabs-intro" );
		}
		$out->addWikiMsg( $intro );

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
	 *
	 * @param string|null $onStep to start on
	 */
	public function doWizard( $onStep = null ) {
		$htmlForm = null;
		foreach ( $this->pageClass->getSteps() as $step ) {
			if ( $onStep && $step !== $onStep ) {
				continue;
			}
			if ( !isset( $htmlForm ) ) {
				$this->formStep = $this->page . "-$step";
				$htmlForm = $this->doStep( $step );
			}
		}

		if ( $htmlForm === null ) {
			$this->pageClass->doSuccess( "successful" );
		}
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
			if ( !( is_array( $callback ) && is_callable( $callback ) ) ) {
				throw new ErrorPageError(
					"mabs-no-wizard-callback", "mabs-dev-needed-callback", [ $step, $this->page ]
				);
			}

			$htmlForm = HTMLForm::factory( 'ooui', $form, $this->getContext(), 'repoform' );
			$htmlForm->setSubmitText( $submit );
			$htmlForm->setSubmitCallback( $callback );
			$htmlForm->setFormIdentifier( $this->formStep );
			if ( $htmlForm->show() ) {
				$this->pageClass->doSuccess( $step );
			}
		}
		return $htmlForm;
	}

	/**
	 * Get the next page to go to
	 *
	 * @return Title
	 */
	protected function getNextPage() {
		throw new \Exception( "blah!" );
	}

	/**
	 * Handle successful form submission
	 *
	 * @param string $step being handled.
	 */
	protected function doSuccess( $step ) {
		static $inSuccess = false;
		if ( $inSuccess ) {
			throw new ErrorPageError(
				"mabs-wizard-success-loop", "mabs-dev-needed-callback", [ $step, $this->page ]
			);
		}
		$inSuccess = true;

		$allSteps = $this->steps;
		$pos = array_search( $step, $allSteps );
		if ( is_int( $pos ) && $pos + 1 <= count( $allSteps ) - 1 ) {
			$this->pageClass = $this;
			$this->doWizard( $allSteps[ $pos + 1 ] );
			return;
		}
		$out = RequestContext::getMain()->getOutput();
		$out->redirect( $this->getNextPage()->getFullUrl() );
	}
}
