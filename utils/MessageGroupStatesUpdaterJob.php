<?php
/**
 * Logic for handling automatic message group state changes
 *
 * @file
 * @author Niklas Laxström
 * @copyright Copyright © 2012-2013, Niklas Laxström
 * @license GPL-2.0-or-later
 */

use MediaWiki\Extension\Translate\Jobs\GenericTranslateJob;
use MediaWiki\Extension\Translate\SystemUsers\FuzzyBot;
use MediaWiki\MediaWikiServices;

/**
 * Logic for handling automatic message group state changes
 *
 * @ingroup JobQueue
 */
class MessageGroupStatesUpdaterJob extends GenericTranslateJob {
	/**
	 * @param Title $title
	 * @param array $params
	 */
	public function __construct( $title, $params = [] ) {
		parent::__construct( __CLASS__, $title, $params );
		$this->removeDuplicates = true;
	}

	/**
	 * Hook: TranslateEventTranslationReview
	 * and also on translation changes
	 * @param MessageHandle $handle
	 * @return true
	 */
	public static function onChange( MessageHandle $handle ) {
		$job = self::newJob( $handle->getTitle() );
		JobQueueGroup::singleton()->push( $job );

		return true;
	}

	/**
	 * @param Title $title
	 * @return self
	 */
	public static function newJob( $title ) {
		$job = new self( $title );

		return $job;
	}

	public function run() {
		$lb = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		if ( !$lb->waitForReplication() ) {
			$this->logWarning( 'Continuing despite replication lag' );
		}

		$title = $this->title;
		$handle = new MessageHandle( $title );
		$code = $handle->getCode();

		if ( !$code && !$handle->isValid() ) {
			return true;
		}

		$groups = self::getGroupsWithTransitions( $handle );
		foreach ( $groups as $id => $transitions ) {
			$group = MessageGroups::getGroup( $id );
			$stats = MessageGroupStats::forItem( $id, $code );
			$state = self::getNewState( $stats, $transitions );
			if ( $state ) {
				ApiGroupReview::changeState( $group, $code, $state, FuzzyBot::getUser() );
			}
		}

		return true;
	}

	public static function getGroupsWithTransitions( MessageHandle $handle ) {
		$listeners = [];
		foreach ( $handle->getGroupIds() as $id ) {
			$group = MessageGroups::getGroup( $id );

			// No longer exists?
			if ( !$group ) {
				continue;
			}

			$conds = $group->getMessageGroupStates()->getConditions();
			if ( $conds ) {
				$listeners[$id] = $conds;
			}
		}

		return $listeners;
	}

	public static function getStatValue( $stats, $type ) {
		$total = $stats[MessageGroupStats::TOTAL];
		$translated = $stats[MessageGroupStats::TRANSLATED];
		$outdated = $stats[MessageGroupStats::FUZZY];
		$proofread = $stats[MessageGroupStats::PROOFREAD];

		switch ( $type ) {
			case 'UNTRANSLATED':
				return $total - $translated - $outdated;
			case 'OUTDATED':
				return $outdated;
			case 'TRANSLATED':
				return $translated;
			case 'PROOFREAD':
				return $proofread;
			default:
				throw new MWException( "Unknown condition $type" );
		}
	}

	public static function matchCondition( $value, $condition, $max ) {
		switch ( $condition ) {
			case 'ZERO':
				return $value === 0;
			case 'NONZERO':
				return $value > 0;
			case 'MAX':
				return $value === $max;
			default:
				throw new MWException( "Unknown condition value $condition" );
		}
	}

	/**
	 * @param int[] $stats
	 * @param array[] $transitions
	 *
	 * @return string|bool
	 */
	public static function getNewState( $stats, $transitions ) {
		foreach ( $transitions as $transition ) {
			[ $newState, $conds ] = $transition;
			$match = true;

			foreach ( $conds as $type => $cond ) {
				$statValue = self::getStatValue( $stats, $type );
				$max = $stats[MessageGroupStats::TOTAL];
				$match = $match && self::matchCondition( $statValue, $cond, $max );
				// Conditions are AND, so no point trying more if no match
				if ( !$match ) {
					break;
				}
			}

			if ( $match ) {
				return $newState;
			}
		}

		return false;
	}
}
