<?php
/**
 * Setup wizard for MABS extension
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
namespace MediaWiki\Extension\MABS\Special\MABS;

use GitWrapper\GitWrapper;
use HTMLForm;
use MediaWiki\Extension\MABS\Config;
use MediaWiki\Extension\MABS\Special\MABS;
use Mediawiki\MediaWikiServices;
use Status;
use Wikimedia;

class Setup extends MABS {
	// phpcs:ignore MediaWiki.Commenting.PropertyDocumentation.MissingDocumentationPrivate
	private static $writable;
	protected $steps = [
		'dependency', 'prepare', 'initialize'
	];

	/**
	 * Look for any mfissing software dependencies.  Some duplication here.
	 *
	 * @param string $step that we're on
	 * @return HTMLForm|null
	 */
	protected function handleDependency( $step ) {
		$msg = false;
		$form = null;

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( "MABS" );
		self::$writable = $config->get( Config::REPO );

		if ( !$this->classExists( 'GitWrapper\GitWrapper' ) ) {
			$msg = wfMessage( "mabs-dependency-gitlib" );
		}

		if ( !$msg ) {
			Wikimedia\suppressWarnings();
			try {
				$wrapper = new GitWrapper;
				if ( !( $wrapper instanceof GitWrapper ) ) {
					$msg = wfMessage( "mabs-dependency-gitlib" );
				}
			} catch ( Exception $e ) {
				$msg = wfMessage( "mabs-dependency-gitlib" );
			}
			Wikimedia\restoreWarnings();
		}

		if ( $msg ) {
			$form = [
				'info' => [
					'section' => "mabs-config-$step-section",
					'type' => 'info',
					'default' => $msg->params(
						"[https://packagist.org/packages/cpliakas/git-wrapper GitWrapper]"
					)->parse(),
					'help' => wfMessage( "mabs-config-fix-problems" )->parse(),
					'raw' => true,
				]
			];
		}
		return $form;
	}

	/**
	 * Tell the user to create a writable directory
	 *
	 * @param string $step that we're on
	 * @return HTMLForm|null
	 */
	protected function handlePrepare( $step ) {
		$msg = false;
		$form = null;

		// FIXME: handle $wgEnableAPI and $wgEnableWriteAPI

		if ( !file_exists( self::$writable ) ) {
			$msg = wfMessage( "mabs-config-please-fix-exists" );
		}
		if ( !$msg && !is_dir( self::$writable ) ) {
			$msg = wfMessage( "mabs-config-please-fix-directory" );
		}
		if ( !$msg && !is_writable( self::$writable ) ) {
			$msg = wfMessage( "mabs-config-please-fix-writable" );
		}
		if ( $msg ) {
			$form = [
				'info' => [
					'section' => "mabs-config-$step-section",
					'type' => 'info',
					'default' => $msg->params( self::$writable )->parse(),
					'help' => wfMessage( "mabs-config-fix-problems" )->parse(),
					'raw' => true,
				]
			];
		}
		return $form;
	}

	/**
	 * Get the form for initially creating the repo.
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handleInitialize( $step, &$submit, &$callback ) {
		$submit = wfMessage( "mabs-config-try-again" )->parse();
		$callback = [ __CLASS__, 'initRepo' ];
		$form = [];
		$msg = false;

		$help = null;
		$gitDir = self::$writable;
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
		}
		return $form;
	}

	/**
	 * Everything has been checked, do the initialization
	 *
	 * @return bool|string
	 */
	public static function initRepo() {
		try {
			$wrapper = new GitWrapper;
			$wrapper->init( self::$writable, [ 'bare' => true ] );
		} catch ( RuntimeException $e ) {
			return Status::newFatal( "mabs-config-init-repo", $e->getMessage() );
		}
		return Status::newGood();
	}

	/**
	 * Initialization is done, go to the synchronisation bit
	 * @return Title
	 */
	protected function getNextPage() {
		return self::getTitleFor( "MABS", "Import" );
	}
}
