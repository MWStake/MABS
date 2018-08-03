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

use HTMLForm;
use Mediawiki\MediaWikiServices;
use MediaWiki\Extension\MABS\Config;
use MediaWiki\Extension\MABS\Special\MABS;

class Import extends MABS {
	protected $steps = [
		'current', 'remote', 'push'
	];
	protected static $writable;

	/**
	 * Look for any missing software dependencies.  Some duplication with composer here.
	 *
	 * @param string $step that we're on
	 * @param string &$submit button text
	 * @param callable &$callback to handle any form input
	 * @return HTMLForm|null
	 */
	protected function handleCurrent( $step, &$submit, &$callback ) {
		$form = null;
		$callback = [ __CLASS__, 'setRemote' ];
		$submit = wfMessage( 'mabs-config-continue' )->parse();

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( "MABS" );
		self::$writable = $config->get( Config::REPO );

		$form = [
			'remote' => [
				'section' => "mabs-config-$step-section",
				'type' => 'url',
				'label' => wfMessage( 'mabs-config-remote' )->parse(),
				'default' => $this->getConfig()->get( "Server" )
				. $this->getConfig()->get( "ScriptPath" ) . "/api.php",
				'readonly' => true,
				'raw' => true,
			],
			'name' => [
				'section' => "mabs-config-$step-section",
				'type' => 'text',
				'label' => wfMessage( 'mabs-config-origin-branch' )->parse(),
				'default' => 'origin',
				'readonly' => true
			]
		];
		return $form;
	}

	/**
	 * Handle setting up the remote and importing
	 *
	 * @param array $form data from the post
	 * @return string|null
	 */
	public static function setRemote( array $form ) {
		$this->git->addRemote( $form['name'], $form['remote'] );
	}
}
