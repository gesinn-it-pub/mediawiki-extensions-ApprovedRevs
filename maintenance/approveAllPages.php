<?php

/**
 * This script approves the current revision of all pages that are in an
 * approvable namespace, and do not already have an approved revision.
 *
 * Usage:
 *  no parameters
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @author Jeroen De Dauw
 * @author Yaron Koren
 * @ingroup Maintenance
 */

// Allow people to have different layouts.
if ( !isset( $IP ) ) {
	$IP = __DIR__ . '/../../../';
	if ( getenv( 'MW_INSTALL_PATH' ) ) {
		$IP = getenv( 'MW_INSTALL_PATH' );
	}
}

use MediaWiki\MediaWikiServices;

require_once "$IP/maintenance/Maintenance.php";

class ApproveAllPages extends Maintenance {

	/** @var array|false */
	public $nsFilter = false;

	public function __construct() {
		parent::__construct();

		$this->addDescription( "Approve the current revision of all pages " .
			"that do not yet have an approved revision." );
		$this->addOption(
			"force", "Approve the latest version, even if an earlier "
			. "revision of the page has already been approved."
		);
		$this->addOption(
			"namespaces",
			"Only approve pages from namespaces belonging "
			. "to the list of pipe-separated namespace names or namespace indexes",
			false,
			true
		);

		if ( method_exists( $this, 'requireExtension' ) ) {
			$this->requireExtension( 'Approved Revs' );
		}
	}

	public function execute() {
		global $wgTitle;
		global $wgEnotifWatchlist;

		if ( $this->hasOption( 'namespaces' ) ) {
			$this->setNsfilter( explode( '|', $this->getOption( 'namespaces' ) ) );
		}

		// Don't send out any notifications about people's watch lists.
		$wgEnotifWatchlist = false;

		$dbr = wfGetDB( DB_REPLICA );

		$pages = $dbr->select(
			'page',
			[
				'page_id',
				'page_latest'
			],
			[],
			__METHOD__
		);

		foreach ( $pages as $page ) {
			$title = Title::newFromID( $page->page_id );

			if ( $this->nsFilter ) {
				if ( !in_array($title->getNamespace(), $this->nsFilter )) {
					continue;
				}
			}

			// Some extensions, like Page Forms, need $wgTitle
			// set as well for these checks.
			$wgTitle = $title;

			if ( !ApprovedRevs::pageIsApprovable( $title ) ) {
				continue;
			}
			$approvedRevID = ApprovedRevs::getApprovedRevID( $title );
			$latestRevID = $page->page_latest;
			if ( $this->getOption( "force" ) ) {
				if ( $latestRevID == $approvedRevID ) {
					continue;
				}
			} else {
				if ( $approvedRevID != null ) {
					continue;
				}
			}

			// Let's approve the latest revision...
			// fixme: the user here is empty - use a system user?
			ApprovedRevs::setApprovedRevID(
				$title, $latestRevID, new User(), true
			);

			$this->output( wfTimestamp( TS_DB ) .
				' Approved the last revision of page "' .
				$title->getFullText() . "\".\n" );
		}

		$this->output( "\n Finished setting all current " .
			"revisions to approved. \n" );
	}

	private function setNsfilter( array $namespaces ) {
		if ( count( $namespaces ) == 0 ) {
			$this->nsFilter = false;

			return;
		}
		$this->nsFilter = array_unique( array_map( [ $this, 'getNsIndex' ], $namespaces ) );
	}

	private function getNsIndex( $namespace ) {
		$contLang = MediaWikiServices::getInstance()->getContentLanguage();
		$result = $contLang->getNsIndex( $namespace );
		if ( $result !== false ) {
			return $result;
		}
		$ns = intval( $namespace );
		if ( strval( $ns ) === $namespace && $contLang->getNsText( $ns ) !== false ) {
			return $ns;
		}
		$this->fatalError( "Unknown namespace text / index specified: $namespace" );
	}

}

$maintClass = ApproveAllPages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
