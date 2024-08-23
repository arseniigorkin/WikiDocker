<?php

namespace MediaWiki\Extension\LDAPGroups\SyncMechanism;

use MediaWiki\Extension\LDAPGroups\ISyncMechanism;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserGroupManager;

abstract class Base implements ISyncMechanism {

	/**
	 *
	 * @var \User
	 */
	protected $user = null;

	/**
	 *
	 * @var \MediaWiki\Extension\LDAPProvider\GroupList
	 */
	protected $groupList = null;

	/**
	 *
	 * @var \Config
	 */
	protected $config = null;

	/**
	 *
	 * @var \Status
	 */
	protected $status = null;

	/**
	 *
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger = null;

	/** @var UserGroupManager */
	protected $userGroupManager;

	/**
	 *
	 * @param \Psr\Log\LoggerInterface $logger
	 * @param UserGroupManager $userGroupManager
	 */
	public function __construct( $logger, $userGroupManager ) {
		$this->logger = $logger;
		$this->userGroupManager = $userGroupManager;
	}

	/**
	 *
	 * @param \Config $domainConfig
	 * @param \Psr\Log\LoggerInterface $logger
	 * @return ISyncMechanism
	 */
	public static function factory( $domainConfig, $logger ) {
		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		return new static( $logger, $userGroupManager );
	}

	/**
	 *
	 * @param \User $user
	 * @param \MediaWiki\Extension\LDAPProvider\GroupList $groupList
	 * @param \Config $config
	 * @return \Status
	 */
	public function sync( $user, $groupList, $config ) {
		$this->user = $user;
		$this->groupList = $groupList;
		$this->config = $config;

		$this->status = \Status::newGood();

		$this->doSync();

		return $this->status;
	}

	/**
	 * Do the actual work
	 */
	abstract protected function doSync();

	/**
	 *
	 * @param string $group
	 */
	protected function addGroup( $group ) {
		$success = $this->userGroupManager->addUserToGroup( $this->user, $group );
		$this->logger->info(
			"Adding '$group' to '{$this->user}'."
		);
		if ( !$success ) {
			$this->logger->error(
					"Problem adding user '{$this->user}' to the group '$group'."
			);
		}
	}

	/**
	 *
	 * @param string $group
	 */
	protected function removeGroup( $group ) {
		$success = $this->userGroupManager->removeUserFromGroup( $this->user, $group );
		$this->logger->info(
			"Removing '$group' from '{$this->user}'."
		);
		if ( !$success ) {
			$this->logger->error(
				"Problem removing user '{$this->user}' from the group '$group'."
			);
		}
	}

}
