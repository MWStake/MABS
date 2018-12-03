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
use FauxRequest;
use GitWrapper\GitException;
use HTMLForm;
use MWHttpRequest;
use Mediawiki\MediaWikiServices;
use MediaWiki\Extension\MABS\Config;
use MediaWiki\Extension\MABS\Special\MABS;
use PasswordFactory;
use RequestContext;
use Status;
use User;

class Import extends MABS {
	protected $steps = [
		'verify', 'setuser', 'setremote', 'remote', 'push'
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

	private function getFullURL() {
		return $this->getConfig()->get( "Server" )
			. $this->getConfig()->get( "ScriptPath" ) . "/api.php";
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
		$git = self::getGit();
		$url = $this->getFullUrl();

		$remotes = $git->remote( "-v" );
		$lines = explode( "\n", $remotes );
		if ( is_array( $lines ) && $lines[0] !== "" ) {
			$remote = [];
			array_map( function ( $line ) use ( &$remote ) {
				// We don't differentiate (yet) between push and fetch
				$part = preg_split( '/[\t ]/', $line );
				if ( isset( $part[1] ) ) {
					$remote[$part[0]] = $part[1];
				}
			}, $lines );
		}

		$form = [];
		if ( !(
			isset( $remote['origin'] )
			&& $remote['origin'] === "mediawiki::" . $url
		) ) {
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
		$git = self::getGit();

		$req = MWHttpRequest::factory(
			$form['remote'],
			[ 'method' => 'GET', 'timeout' => 'default', 'connectTimeout' => 'default' ],
			__METHOD__
		);
		$status = $req->execute();
		if ( !$status->isOK() ) {
			$msg = Status::newFatal( "mabs-config-cannot-reach-self", $status->getMessage() );
			return $msg->getErrorsArray();
		}

		$git->remote( "add",  $form['name'], "mediawiki::{$form['remote']}" );
	}

	/**
	 * Test the provided appId and password.
	 *
	 * @param string $user the user to use
	 * @param string $pass the password to use
	 * @return Status
	 */
	protected function loginCheck( $user, $pass ) {
		return BotPassword::login( $user, $pass, new FauxRequest );
	}

	/**
	 * Get user and password that are set on the remote
	 *
	 * @return string[]
	 */
	protected function getUserPass() {
		$url = $this->getFullUrl();
		$git = self::getGit();
		$creds = [];

		try {
			$creds = [ trim( (string)$git->config( "credential." . $url . ".username" ) ),
					   trim( (string)$git->config( "credential." . $url . ".password" ) ) ];
		} catch ( GitException $e ) {
			echo "oops: <pre>{$e->getMessage()}</pre>\n";exit;
		}
		return $creds;
	}

	/**
	 * Set user and password for git
	 *
	 * @param string $user username
	 * @param string $pass password
	 * @return string[]
	 */
	protected function setUserPass( $user, $pass ) {
		$url = $this->getFullUrl();
		$git = self::getGit();
		$git->config( "credential." . $url . ".username", $user );
		$git->config( "credential." . $url . ".password", $pass );
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
		$form = null;
		$callback = [ __CLASS__, 'setUser' ];
		$submit = wfMessage( 'mabs-config-set-user' )->parse();
		$appId = "mabs";

		list( $user, $pass ) = $this->getUserPass();
		$status = $this->loginCheck( $user, $pass );
		if ( $status->isOk() ) {
			$form = null;
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
					'default' => $user
				],
			];
		# Do not try === since these are GitWrapper objects
		} elseif ( $user == "" && $pass == "" ) {
			$form = [
				'user' => [
					'section' => "mabs-config-$step-section",
					'label' => wfMessage( 'mabs-config-setup-user' )->parse(),
					'readonly' => true,
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
	 * @return string
	 */
	private static function getNewPassword( BotPassword $bot ) {
		$conf = RequestContext::getMain()->getConfig();
		$pass = $bot->generatePassword( $conf );
		$passwordFactory = new PasswordFactory();
		$passwordFactory->init( $conf );
		$password = $passwordFactory->newFromPlaintext( $pass );
		return $password;
	}

	private static function getBotPass( User $user, $appId ) {
		$bot = BotPassword::newFromUser( $user, $appId );
		if ( $bot === null ) {
			$bot = BotPassword::newUnsaved(
				[ 'user' => $user, 'appId' => $appId, 'grants' => [ 'mabs' ] ]
			);
		}
		return [ $bot, self::getNewPassword( $bot ) ];
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
		if ( isset( $form['fromScratch'] ) ) {
			list( $bot, $pass ) = self::getBotPass( $user, $form['fromScratch'] );
			return $bot->save( 'insert', $pass )
				?? 'mabs-failure-saving-user';
		} elseif ( isset( $form['takeOverUser'] ) && $form['takeOverUser'] ) {
			list( $bot, $pass ) = self::getBotPass( $user, $form['user'] );
			return $bot->save( 'update', $pass )
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
	public static function startOver( array $form ) {
		$context = RequestContext::getMain();
		$context->getOutput()->redirect( self::getTitleFor( "MABS" )->getFullUrl() );
	}
}
