<?php
/**
 * Export wizard for MABS extension
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
use MWException;
use MWHttpRequest;
use MediaWiki\Extension\MABS\Config;
use MediaWiki\Extension\MABS\Special\MABS;
use MediaWiki\MediaWikiServices;
use PasswordFactory;
use RequestContext;
use Status;
use User;

class Export extends MABS {
	protected $steps = [
		'verify', 'push'
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
		$git = self::getGitWrapper();
		$count = explode( " ", $git->run( [ "count-objects" ] ) );
		$objects = array_shift( $count );

		if ( $objects === "0" ) {
			$submit = wfMessage( "mabs-config-try-again" )->parse();
			$callback = [ __CLASS__, 'startOver' ];
			$form = [
				'returnToTop' => [
					'section' => "mabs-config-$step-section",
					'type' => 'info',
					'default' => wfMessage( "mabs-config-import-not-done" )->parse()
				] ];
		}
		return $form;
	}

	/**
	 * Go back to the beginning.
	 *
	 * @return string|null
	 */
	public static function startOver() {
		$context = RequestContext::getMain();
		$context->getOutput()->redirect( self::getTitleFor( "MABS" )->getFullUrl() );
	}

	/**
	 * Push this wiki's git repo to somewhere else
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handlePush( $step, &$submit, &$callback ) {
		$form = [];
		$submit = wfMessage( "mabs-config-export-push" )->parse();
		$callback = [ __CLASS__, 'push' ];

		$form = [
			'remote' => [
				'section' => "mabs-config-$step-section",
				'type' => 'url',
				'label' => wfMessage( "mabs-config-export-remote-url" ),
			],
			'user' => [
				'section' => "mabs-config-$step-section",
				'type' => 'text',
				'label' => wfMessage( 'mabs-config-export-username' ),
			],
			'pass' => [
				'section' => "mabs-config-$step-section",
				'type' => 'password',
				'label' => wfMessage( 'mabs-config-export-password' ),
				'default' => 'other',
			],
			'name' => [
				'section' => "mabs-config-$step-section",
				'type' => 'text',
				'label' => wfMessage( 'mabs-config-export-other-branch' ),
				'default' => 'other',
			]
		];

		return $form;
	}

	/**
	 * Handle setting up the remote and exporting
	 *
	 * @param array $form data from the post
	 * @return string|null
	 */
	public static function push( $form ) {
		if ( !$form['remote'] ) {
			return 'mabs-config-export-remote-needed';
		}

		$git = self::getGitWrapper();

	}
}
