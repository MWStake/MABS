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
use HTMLForm;
use MWHttpRequest;
use Mediawiki\MediaWikiServices;
use MediaWiki\Extension\MABS\Config;
use MediaWiki\Extension\MABS\Special\MABS;
use RequestContext;
use Status;

class Import extends MABS {
	static protected $gitDir;
	protected $steps = [
		'verify', 'setremote', 'remote', 'push'
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
		$git = new GitWrapper;
		$url = $this->getConfig()->get( "Server" )
			 . $this->getConfig()->get( "ScriptPath" ) . "/api.php";
		if ( !chdir( self::$gitDir ) ) {
			throw new ErrorPageError( "mabs-system-error", "mabs-no-chdir", self::$gitDir );
		}

		$remotes = $git->git( "remote -v" );
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
		$git = new GitWrapper;

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

		$git->git( "remote add '{$form['name']}' mediawiki::'{$form['remote']}'" );
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
