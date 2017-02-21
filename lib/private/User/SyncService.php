<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @copyright Copyright (c) 2017, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */


namespace OC\User;


use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\UserInterface;

class SyncService {

	/** @var UserInterface */
	private $backend;
	/** @var AccountMapper */
	private $mapper;
	/** @var IConfig */
	private $config;
	/** @var string */
	private $backendClass;

	/**
	 * SyncService constructor.
	 *
	 * @param AccountMapper $mapper
	 * @param UserInterface $backend
	 * @param IConfig $config
	 */
	public function __construct(AccountMapper $mapper, UserInterface $backend, IConfig $config) {
		$this->mapper = $mapper;
		$this->backend = $backend;
		$this->backendClass = get_class($backend);
		$this->config = $config;
	}

	/**
	 * @param \Closure $callback is called for every user to allow progress display
	 * @return array
	 */
	public function getNoLongerExistingUsers(\Closure $callback) {
		// detect no longer existing users
		$toBeDeleted = [];
		$this->mapper->callForAllUsers(function (Account $a) use ($toBeDeleted, $callback) {
			if ($a->getBackend() == $this->backendClass) {
				if (!$this->backend->userExists($a->getUserId())) {
					$toBeDeleted[] = $a->getUserId();
				}
			}
			$callback($a);
		}, '', false);

		return $toBeDeleted;
	}

	/**
	 * @param \Closure $callback is called for every user to progress display
	 */
	public function run(\Closure $callback) {
		$users = $this->backend->getUsers();
		// update existing and insert new users
		foreach ($users as $user) {
			try {
				$a = $this->mapper->getByUid($user);
				if ($a->getBackend() !== $this->backendClass) {
					// user already provided by another backend
					continue;
				}
				$a = $this->setupAccount($a, $user);
				$this->mapper->update($a);
			} catch(DoesNotExistException $ex) {
				$a = new Account();
				$a->setUserId($user);
				$a = $this->setupAccount($a, $user);
				$this->mapper->insert($a);
			}
			$callback($user);
		}
	}

	/**
	 * @param Account $a
	 * @param string $uid
	 * @return Account
	 */
	private function setupAccount(Account $a, $uid) {
		$enabled = $this->config->getUserValue($uid, 'core', 'enabled', 'true');
		$lastLogin = $this->config->getUserValue($uid, 'login', 'lastLogin', 0);
		$email = $this->config->getUserValue($uid, 'settings', 'email', null);
		$quota = $this->config->getUserValue($uid, 'files', 'quota', 'default');
		$a->setEmail($email);
		$a->setUserId($uid);
		$a->setBackend(get_class($this->backend));
		$a->setLastLogin($lastLogin);
		$a->setQuota($quota);
		if ($this->backend->implementsActions(\OC_User_Backend::GET_HOME)) {
			$a->setHome($this->backend->getHome($uid));
		}
		if ($this->backend->implementsActions(\OC_User_Backend::GET_DISPLAYNAME)) {
			$a->setDisplayName($this->backend->getDisplayName($uid));
		}
		$a->setState(($enabled === 'true') ? Account::STATE_ENABLED : Account::STATE_DISABLED);
		return $a;
	}

}
