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

use Gitonomy\Git\Admin;
use Gitonomy\Git\Exception\RuntimeException;
use Gitonomy\Git\Repository;
use HTMLForm;
use Mediawiki\MediaWikiServices;
use MediaWiki\Extension\MABS\Config;
use MediaWiki\Extension\MABS\Special\MABS;
use RequestContext;
use Status;
use Wikimedia;

class Setup extends MABS {
	private static $writable;
	protected $steps = [
		'dependency', 'prepare', 'initialize', 'complete'
	];

	/**
	 * Look for any missing software dependencies.  Some duplication here.
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handleDependency( $step, &$submit, &$callback ) {
		$msg = false;
		$form = null;
		$callback = [ __CLASS__, 'trySubmit' ];

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( "MABS" );
		self::$writable = $config->get( Config::REPO );

		if ( !$this->classExists( 'Gitonomy\Git\Repository' ) ) {
			$msg = wfMessage( "mabs-dependency-gitonomy" );
		}

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
		}
		return $form;
	}

	/**
	 * Tell the user to create a writable directory
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handlePrepare( $step, &$submit, &$callback ) {
		$msg = false;
		$form = null;
		$submit = wfMessage( "mabs-config-try-again" )->parse();
		$callback = [ __CLASS__, 'trySubmit' ];

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
		$msg = false;
		$submit = wfMessage( "mabs-config-try-again" )->parse();
		$callback = [ __CLASS__, 'initRepo' ];

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

		$form = [];
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
		try {
			Admin::init( self::$writable );
		} catch ( RuntimeException $e ) {
			return Status::newFatal( "mabs-config-init-repo", $e->getMessage() );
		}
	}

	/**
	 * Last page of the wizard
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handleComplete( $step, &$submit, &$callback ) {
		$submit = wfMessage( "mabs-config-continue" )->parse();
		$callback = [ __CLASS__, 'gotoImport' ];

		$form = [
			'info' => [
				'section' => "mabs-config-$step-section",
				'type' => 'info',
				'default' => wfMessage( "mabs-config-complete" )->parse(),
				'raw' => true,
			]
		];

		return $form;
	}

	/**
	 * Initialization is done, go to the synchronisation bit
	 *
	 * @param array $formData data from submission
	 * @return bool|string
	 */
	public static function gotoImport( array $formData ) {
		$context = RequestContext::getMain();
		$out = $context->getOutput();
		$out->redirect( self::getTitleFor( "MABS", "Import" )->getFullUrl() );
	}
}
