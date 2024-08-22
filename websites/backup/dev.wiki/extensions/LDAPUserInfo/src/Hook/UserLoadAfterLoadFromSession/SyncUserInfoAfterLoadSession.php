<?php

namespace MediaWiki\Extension\LDAPUserInfo\Hook\UserLoadAfterLoadFromSession;

use MediaWiki\Extension\LDAPProvider\Hook\UserLoadAfterLoadFromSession;
use MediaWiki\Extension\LDAPUserInfo\Config;
use MediaWiki\Extension\LDAPUserInfo\UserInfoSyncProcess;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\UserOptionsLookup;

class SyncUserInfoAfterLoadSession extends UserLoadAfterLoadFromSession {

	protected $sessionDataKey = 'ldap-user-sync-last';

	/**
	 * @return bool
	 * @throws \ConfigException
	 */
	protected function doSync() {
		$this->user->clearInstanceCache();
		$this->user->loadFromDatabase();
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		$userOptionsLookup->getOptions( $this->user, UserOptionsLookup::EXCLUDE_DEFAULTS );

		$process = new UserInfoSyncProcess(
			$this->user,
			$this->domainConfig,
			$this->ldapClient,
			$this->config->get( 'LDAPUserInfoModifierRegistry' )
		);

		$process->run();

		return true;
	}

	/**
	 *
	 * @return string
	 */
	protected function getDomainConfigSection() {
		return Config::DOMAINCONFIG_SECTION;
	}

}
