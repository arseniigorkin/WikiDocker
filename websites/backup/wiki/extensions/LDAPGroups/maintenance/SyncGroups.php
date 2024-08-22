<?php

namespace MediaWiki\Extension\LDAPGroups\Maintenance;

use GlobalVarConfig;
use Maintenance;
use MediaWiki\Extension\LDAPGroups\GroupSyncProcess;
use MediaWiki\Extension\LDAPProvider\ClientFactory;
use MediaWiki\Extension\LDAPProvider\DomainConfigFactory;
use MediaWiki\Extension\LDAPProvider\UserDomainStore;
use MediaWiki\MediaWikiServices;

$maintPath = ( getenv( 'MW_INSTALL_PATH' ) !== false
			  ? getenv( 'MW_INSTALL_PATH' )
			  : __DIR__ . '/../../..' ) . '/maintenance/Maintenance.php';
if ( !file_exists( $maintPath ) ) {
	echo "Please set the environment variable MW_INSTALL_PATH "
		. "to your MediaWiki installation.\n";
	exit( 1 );
}
require_once $maintPath;

class SyncGroups extends Maintenance {

	/**
	 *
	 */
	public function __construct() {
		parent::__construct();
		$this->addOption( 'user', 'The local user name', true, true );
		$this->requireExtension( 'LDAPGroups' );
	}

	/**
	 *
	 */
	public function execute() {
		$services = MediaWikiServices::getInstance();
		$username = $this->getOption( 'user' );
		$services = MediaWikiServices::getInstance();
		$user = $services->getUserFactory()->newFromName( $username );
		if ( $user->getId() === 0 ) {
			$this->output( "User '$username' does not exist!\n" );
			return;
		}

		$this->output( "Syncing groups for '{$user->getName()}' (ID:{$user->getId()}) ...\n" );
		$this->output( "\nOld groups:\n" );
		$oldGroupMemberships = $services->getUserGroupManager()->getUserGroupMemberships( $user );
		foreach ( $oldGroupMemberships as $oldGroupMembership ) {
			$this->output( "* {$oldGroupMembership->getGroup()}\n" );
		}

		$loadBalancer = $services->getDBLoadBalancer();
		$domainStore = new UserDomainStore( $loadBalancer );
		$domain = $domainStore->getDomainForUser( $user );
		if ( $domain === null ) {
			$this->error( "ERROR: Could not find domain for {$user->getName()}!\n" );
			return;
		}

		$client = ClientFactory::getInstance()->getForDomain( $domain );
		$domainConfig = DomainConfigFactory::getInstance()->factory( $domain, 'groupsync' );
		$config = new GlobalVarConfig( '' );
		$callbackRegistry = $config->get( 'LDAPGroupsSyncMechanismRegistry' );
		$process = new GroupSyncProcess( $user, $domainConfig, $client, $callbackRegistry );
		$process->run();

		$this->output( "\nNew groups:\n" );
		$newGroupMemberships = $services->getUserGroupManager()->getUserGroupMemberships( $user );
		foreach ( $newGroupMemberships as $newGroupMembership ) {
			$this->output( "* {$newGroupMembership->getGroup()}\n" );
		}
		$this->output( "\n\n" );
	}
}

$maintClass = SyncGroups::class;
require_once RUN_MAINTENANCE_IF_MAIN;
