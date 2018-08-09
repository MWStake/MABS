<?php
/**
 * Import wizard for MABS extension
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

use ErrorPageError;
use GitWrapper\GitWrapper;
use GitWrapper\GitException;
use HTMLForm;
use MWHttpRequest;
use MediaWiki\Extension\MABS\Config;
use MediaWiki\Extension\MABS\Special\MABS;
use Mediawiki\MediaWikiServices;
use RequestContext;
use Status;

class Import extends MABS {
	static protected $gitDir;
	protected $steps = [
		'verify', 'botpassword', 'setremote', 'fetch', 'push'
	];

	/**
	 * Make sure the previous wizard is done.
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handleVerify( $step, &$submit, &$callback ) {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( "MABS" );
		self::$gitDir = $config->get( Config::REPO );
		$form = [];
		if (
			!( file_exists( self::$gitDir )
			   && is_dir( self::$gitDir )
			   && file_exists( self::$gitDir . "/config" ) )
		) {
			$callback = [ __CLASS__, 'startOver' ];
			$submit = wfMessage( 'mabs-config-try-again' )->parse();
			$form = [
				'returnToTop' => [
					'section' => "mabs-config-$step-section",
					'type' => 'info',
					'default' => wfMessage( "mabs-config-start-over" )->parse()
				] ];
		}
		return $form;
	}

	/**
	 * Handle setting up the remote and importing
	 *
	 * @param array $form data from the post
	 * @return string|null
	 */
	public static function startOver( array $form ) {
		$context = RequestContext::getMain();
		$context->getOutput()->redirect( self::getTitleFor( "MABS" )->getFullUrl() );
	}

	/**
	 * Make sure we have a bot user with the right permissions
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handleBotPassword( $step, &$submit, &$callback ) {
		$form = null;
		$callback = [ __CLASS__, 'setBotPassword' ];
		$submit = wfMessage( 'mabs-config-set-bot-password' );
	}
	/**
	 * Look for any missing software dependencies.  Some duplication with composer here.
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handleSetRemote( $step, &$submit, &$callback ) {
		$form = null;
		$callback = [ __CLASS__, 'setRemote' ];
		$submit = wfMessage( 'mabs-config-set-remote' )->parse();
		$git = self::getGitWrapper();
		$url = "mediawiki::" . $this->getConfig()->get( "Server" )
			 . $this->getConfig()->get( "ScriptPath" ) . "/api.php";

		$remotes = $git->git( "remote -v" );
		$remote = [];
		$lines = explode( "\n", $remotes );
		if ( is_array( $lines ) && $lines[0] !== "" ) {
			array_map( function ( $line ) use ( &$remote ) {
				// We don't differentiate (yet) between push and fetch
				$part = preg_split( '/[\t ]/', $line );
				if ( isset( $part[1] ) ) {
					$remote[$part[0]] = $part[1];
				}
			}, $lines );
		}

		if ( !( isset( $remote['origin'] ) && $remote['origin'] === $url ) ) {
			$form = [
				'remote' => [
					'section' => "mabs-config-$step-section",
					'type' => 'url',
					'label' => wfMessage( 'mabs-config-remote' )->parse(),
					'default' => $url,
					'readonly' => true,
				],
				'name' => [
					'section' => "mabs-config-$step-section",
					'type' => 'text',
					'label' => wfMessage( 'mabs-config-origin-branch' )->parse(),
					'default' => 'origin',
					'readonly' => true,
				]
			];
		}
		return $form;
	}

	/**
	 * Handle setting up the remote and importing
	 *
	 * @param array $form data from the post
	 * @return string|null
	 */
	public static function setRemote( array $form ) {
		$git = self::getGitWrapper();

		$remote = substr( $form['remote'], 0, 11 ) === "mediawiki::"
				? substr( $form['remote'], 11 ) : null;
		$req = MWHttpRequest::factory(
			$remote,
			[ 'method' => 'GET', 'timeout' => 'default', 'connectTimeout' => 'default' ],
			__METHOD__
		);
		$status = $req->execute();
		if ( !$status->isOK() ) {
			$msg = Status::newFatal( "mabs-config-cannot-reach-self", $status->getMessage() );
			return $msg->getErrorsArray();
		}

		$req = MWHttpRequest::factory(
			$remote . '?' . http_build_query( [
				'action' => 'query', 'meta' => 'siteinfo', 'format' => 'json',
			] ),
			[ 'method' => 'GET', 'timeout' => 'default', 'connectTimeout' => 'default' ],
			__METHOD__
		);
		$status = $req->execute();
		if ( !$status->isGood() ) {
			$msg = Status::newFatal( "mabs-config-bad-response-status", $status->getMessage() );
			return $msg->getErrorsArray();
		}

		$resp = json_decode( $req->getContent() );
		if ( $resp === null ) {
			$msg = Status::newFatal( "mabs-config-invalid-json-response", json_last_error_msg() );
			return $msg->getErrorsArray();
		}

		$sitename = MediaWikiServices::getInstance()->getMainConfig()->get( "Sitename" );
		if ( !(
			isset( $resp->query->general->sitename ) && $resp->query->general->sitename == $sitename
		) ) {
			$msg = Status::newFatal(
				"mabs-config-sitename-mismatch", $resp->query->general->sitename, $sitename
			);
			return $msg->getErrorsArray();
		}

		try {
			$back = $git->git( "remote add '{$form['name']}' '{$form['remote']}'" );
			if ( $back === "" ) {
				return true;
			}
		} catch ( GitException $e ) {
			$back = $e->getMessage();
		}
		return Status::newFatal( "mabs-config-add-remote-error", $back );
	}

	/**
	 * Look for any missing software dependencies.  Some duplication with composer here.
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handleFetch( $step, &$submit, &$callback ) {
		$git = self::getGitWrapper();
		$count = explode( " ", $git->git( "count-objects" ) );
		$objects = array_shift( $count );
		$form = [];
		$submit = wfMessage( "mabs-config-import" )->parse();
		$callback = [ __CLASS__, 'doFetch' ];

		if ( $objects === "0" ) {
			$form = [
				'holdOn' => [
					'section' => "mabs-config-$step-section",
					'type' => 'info',
					'default' => wfMessage( "mabs-config-import-ready" )->parse()
				] ];
		}
		return $form;
	}

	/**
	 * Handle setting up the remote and importing
	 *
	 * @param array $form data from the post
	 * @return string|null
	 */
	public static function doFetch( array $form ) {
		$git = self::getGitWrapper();

		try {
			return $git->git( "fetch" );
		} catch ( GitException $e ) {
			return Status::newFatal( "mabs-config-import-fetch-error", $e->getMessage() );
		}
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
		if ( $step === 'push' ) {
			$inSuccess = true;
			$out = RequestContext::getMain()->getOutput();
			$out->redirect( self::getTitleFor( "MABS", "Import" )->getFullUrl() );
		}
		$this->pageClass = $this;
		$this->doWizard();
	}
}
