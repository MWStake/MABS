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

use BotPassword;
use ErrorPageError;
use FauxRequest;
use GitWrapper\GitWrapper;
use GitWrapper\GitException;
use HTMLForm;
use MWHttpRequest;
use MediaWiki\Extension\MABS\Config;
use MediaWiki\Extension\MABS\Special\MABS;
use Mediawiki\MediaWikiServices;
use PasswordFactory;
use RequestContext;
use Status;
use User;

class Import extends MABS {
	static protected $gitDir;
	protected $steps = [
		'verify', 'setuser', 'setremote', 'fetch', 'push'
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
		$form = [];
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( "MABS" );
		self::$gitDir = $config->get( Config::REPO );

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

	private static function getFullURL() {
        $conf = MediaWikiServices::getInstance()->getMainConfig();
		return $conf->get( "Server" ) . $conf->get( "ScriptPath" ) . "/api.php";
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
		$url = "mediawiki::" . self::getFullURL();

		$remotes = $git->remote( "-v" );
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

        if ( substr( $form['remote'], 0, 11 ) !== "mediawiki::" ) {
            throw new MWException( wfMessage( "mabs-not-an-actual-destination" ) );
        }
		$remote = substr( $form['remote'], 11 );
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

        $back = "";
		try {
			$back = $git->remote( "add", $form['name'], $form['remote'] );
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
		$count = explode( " ", $git->run( [ "count-objects" ] ) );
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
	 * Test the provided appId and password.
	 *
	 * @param string $user the user to use
	 * @param string $pass the password to use
	 * @return Status
	 */
	private function loginCheck( $user, $pass ) {
        if ( $user && $pass ) {
            return BotPassword::login( $user, $pass, new FauxRequest );
        }
        return Status::newFatal( "mabs-login-failed", $user );
	}

	/**
	 * Get user and password that are set on the remote
	 *
	 * @return string[]
	 */
	private static function getUserPass() {
		$url = self::getFullUrl();
		$git = self::getGitWrapper();
        try {
            return [ trim( (string)$git->config( "credential." . $url . ".username" ) ),
                     trim( (string)$git->config( "credential." . $url . ".password" ) ) ];
        } catch ( GitException $e ) {
            return [ null, null ];
        }
	}

	/**
	 * Set user and password for git
	 *
	 * @param BotPassword $bot object
	 * @param string $pass password
	 */
	protected static function setUserPass( BotPassword $bot, $pass ) {
		$url = self::getFullUrl();
		$git = self::getGitWrapper();
        $user = User::newFromId( $bot->getUserCentralId() )->getName() . "@" . $bot->getAppID();
        $git->config( "credential." . $url . ".username", $user );
		$git->config( "credential." . $url . ".password", $pass );
		$git->config( "credential." . $url . ".domain", '' ); // FIXME not used yet
	}

	/**
	 * Set up a BotPassword user
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handleSetUser( $step, &$submit, &$callback ) {
		$form = [];
		$callback = [ __CLASS__, 'setUser' ];
		$submit = wfMessage( 'mabs-config-set-user' )->parse();
		$appId = "mabs";

		list( $user, $pass ) = self::getUserPass();
		$status = $this->loginCheck( $user, $pass );
		if ( $status->isOk() ) {
            return $form;
		# Do not try !== since $user is a GitWrapper object
		} elseif ( $user != "" ) {
			$form = [
				"takeOverUser" => [
					'section' => "mabs-config-$step-section",
					'label' => wfMessage( 'mabs-config-reset-password', $user )->parse(),
					'default' => false,
					'type' => 'check',
				],
				'user' => [
					'type' => 'hidden',
					'default' => $appId
				],
			];
		# Do not try === since these are GitWrapper objects
		} elseif ( $user == "" && $pass == "" ) {
			$form = [
				'user' => [
					'section' => "mabs-config-$step-section",
					'default' => wfMessage( 'mabs-config-setup-user' )->parse(),
                    'type' => 'info',
                    'raw' => true
				],
				'fromScratch' => [
					'type' => 'hidden',
					'default' => $appId
				],
			];
		}
		return $form;
	}

	/**
	 * Get a new auto-generated password.
	 *
	 * @param BotPassword $bot object
	 * @return string[]
	 */
	private static function getNewPassword( BotPassword $bot ) {
		$conf = \RequestContext::getMain()->getConfig();
		$pass = $bot->generatePassword( $conf );
		$passwordFactory = new PasswordFactory();
		$passwordFactory->init( $conf );
		$password = $passwordFactory->newFromPlaintext( $pass );
		return [ $pass, $password ];
	}

	/**
	 * Handle setting up the remote and importing
	 *
	 * @param array $form data from the post
	 * @return string|null
	 */
	public static function setUser( array $form ) {
		$context = RequestContext::getMain();
		$user = $context->getUser();
		if ( isset( $form['fromScratch'] ) && $form['fromScratch'] ) {
			$bot = BotPassword::newUnsaved(
				[ 'user' => $user, 'appId' => $form['fromScratch'], 'grants' => [ 'mabs' ] ]
			);
            list( $pass, $crypt ) = self::getNewPassword( $bot );
            self::setUserPass( $bot, $pass );
			return $bot->save( 'insert', $crypt )
				?? 'mabs-failure-saving-user';
		} elseif ( isset( $form['takeOverUser'] ) && $form['takeOverUser'] ) {
			$bot = BotPassword::newFromUser( $user, trim( $form['user'] ) );
            list( $pass, $crypt ) = self::getNewPassword( $bot );
            self::setUserPass( $bot, $pass );
			return $bot->save( 'update', $crypt )
              ?? 'mabs-failure-updating-password';
		} elseif ( isset( $form['takeOverUser'] ) ) {
			return 'mabs-failure-takeover-needed';
		} elseif ( $form['continue'] ) {
			return true;
		}
		return 'mabs-not-an-actual-destination';
	}

	/**
	 * Set up a push
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handlePush( $step, &$submit, &$callback ) {
		var_dump($step);exit;
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
			return $git->fetch();
		} catch ( GitException $e ) {
			return Status::newFatal( "mabs-config-import-fetch-error", $e->getMessage() );
		}
	}
}
