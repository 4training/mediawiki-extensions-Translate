<?php
/**
 * Contains class with job for deleting translatable and translation pages.
 *
 * @file
 * @author Niklas Laxström
 * @copyright Copyright © 2008-2013, Niklas Laxström
 * @license GPL-2.0-or-later
 */

use MediaWiki\Extension\Translate\SystemUsers\FuzzyBot;

/**
 * Contains class with job for deleting translatable and translation pages.
 *
 * @ingroup PageTranslation JobQueue
 */
class TranslateDeleteJob extends Job {
	/**
	 * @param Title $target
	 * @param string $base
	 * @param string $full
	 * @param User $performer
	 * @param string $reason
	 * @return self
	 */
	public static function newJob( Title $target, $base, $full, /*User*/ $performer, $reason ) {
		$job = new self( $target );
		$job->setUser( FuzzyBot::getUser() );
		$job->setFull( $full );
		$job->setBase( $base );
		$msg = $job->getFull() ? 'pt-deletepage-full-logreason' : 'pt-deletepage-lang-logreason';
		$job->setSummary( wfMessage( $msg, $base )->inContentLanguage()->text() );
		$job->setPerformer( $performer );
		$job->setReason( $reason );

		return $job;
	}

	/**
	 * @param Title $title
	 * @param array $params
	 */
	public function __construct( $title, $params = [] ) {
		parent::__construct( __CLASS__, $title, $params );
	}

	public function run() {
		// Initialization
		$title = $this->title;
		// Other stuff
		$user = $this->getUser();
		$summary = $this->getSummary();
		$base = $this->getBase();
		$doer = User::newFromName( $this->getPerformer() );
		$reason = $this->getReason();

		PageTranslationHooks::$allowTargetEdit = true;
		PageTranslationHooks::$jobQueueRunning = true;

		$error = '';
		$wikipage = new WikiPage( $title );
		if ( version_compare( TranslateUtils::getMWVersion(), '1.35', '<' ) ) {
			$status = $wikipage->doDeleteArticleReal(
				"{$summary}: $reason",
				// https://phabricator.wikimedia.org/T262800
				// @phan-suppress-next-line PhanTypeMismatchArgumentReal
				false,
				0,
				true,
				$error,
				$user,
				[],
				'delete',
				true
			);
		} else {
			$status = $wikipage->doDeleteArticleReal(
				"{$summary}: $reason",
				$user,
				false,
				null,
				$error,
				null,
				[],
				'delete',
				true
			);
		}

		if ( !$status->isGood() ) {
			$params = [
				'target' => $base,
				'errors' => $status->getErrorsArray(),
			];

			$type = $this->getFull() ? 'deletefnok' : 'deletelnok';
			$entry = new ManualLogEntry( 'pagetranslation', $type );
			$entry->setPerformer( $doer );
			$entry->setComment( $reason );
			$entry->setTarget( $title );
			$entry->setParameters( $params );
			$logid = $entry->insert();
			$entry->publish( $logid );
		}

		PageTranslationHooks::$allowTargetEdit = false;

		$cache = ObjectCache::getInstance( CACHE_DB );
		$pageKey = $cache->makeKey( 'pt-base', $base );
		$pages = (array)$cache->get( $pageKey );
		$lastitem = array_pop( $pages );
		if ( $title->getPrefixedText() === $lastitem ) {
			$cache->delete( $pageKey );

			$type = $this->getFull() ? 'deletefok' : 'deletelok';
			$entry = new ManualLogEntry( 'pagetranslation', $type );
			$entry->setPerformer( $doer );
			$entry->setComment( $reason );
			$entry->setTarget( Title::newFromText( $base ) );
			$logid = $entry->insert();
			$entry->publish( $logid );

			$tpage = TranslatablePage::newFromTitle( $title );
			$tpage->getTranslationPercentages();
			foreach ( $tpage->getTranslationPages() as $page ) {
				$page->invalidateCache();
			}
			$title->invalidateCache();
			PageTranslationHooks::$jobQueueRunning = false;
		}

		return true;
	}

	public function setSummary( $summary ) {
		$this->params['summary'] = $summary;
	}

	public function getSummary() {
		return $this->params['summary'];
	}

	public function setReason( $reason ) {
		$this->params['reason'] = $reason;
	}

	public function getReason() {
		return $this->params['reason'];
	}

	public function setFull( $full ) {
		$this->params['full'] = $full;
	}

	public function getFull() {
		return $this->params['full'];
	}

	/** @param User|string $performer */
	public function setPerformer( $performer ) {
		if ( is_object( $performer ) ) {
			$this->params['performer'] = $performer->getName();
		} else {
			$this->params['performer'] = $performer;
		}
	}

	public function getPerformer() {
		return $this->params['performer'];
	}

	/** @param User|string $user */
	public function setUser( $user ) {
		if ( is_object( $user ) ) {
			$this->params['user'] = $user->getName();
		} else {
			$this->params['user'] = $user;
		}
	}

	public function setBase( $base ) {
		$this->params['base'] = $base;
	}

	public function getBase() {
		return $this->params['base'];
	}

	/**
	 * Get a user object for doing edits.
	 * @return User
	 */
	public function getUser() {
		return User::newFromName( $this->params['user'], false );
	}
}
