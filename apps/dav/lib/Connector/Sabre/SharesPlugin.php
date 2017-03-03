<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
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
namespace OCA\DAV\Connector\Sabre;

use \Sabre\DAV\PropFind;
use OCP\IUserSession;
use OCP\Share\IShare;

/**
 * Sabre Plugin to provide share-related properties
 */
class SharesPlugin extends \Sabre\DAV\ServerPlugin {

	const NS_OWNCLOUD = 'http://owncloud.org/ns';
	const SHARETYPES_PROPERTYNAME = '{http://owncloud.org/ns}share-types';

	/**
	 * Reference to main server object
	 *
	 * @var \Sabre\DAV\Server
	 */
	private $server;

	/**
	 * @var \OCP\Share\IManager
	 */
	private $shareManager;

	/**
	 * @var \Sabre\DAV\Tree
	 */
	private $tree;

	/**
	 * @var string
	 */
	private $userId;

	/**
	 * @var \OCP\Files\Folder
	 */
	private $userFolder;

	/**
	 * @var IShare[]
	 */
	private $cachedShareTypes;

	/**
	 * @param \Sabre\DAV\Tree $tree tree
	 * @param IUserSession $userSession user session
	 * @param \OCP\Files\Folder $userFolder user home folder
	 * @param \OCP\Share\IManager $shareManager share manager
	 */
	public function __construct(
		\Sabre\DAV\Tree $tree,
		IUserSession $userSession,
		\OCP\Files\Folder $userFolder,
		\OCP\Share\IManager $shareManager
	) {
		$this->tree = $tree;
		$this->shareManager = $shareManager;
		$this->userFolder = $userFolder;
		$this->userId = $userSession->getUser()->getUID();
		$this->cachedShareTypes = [];
	}

	/**
	 * This initializes the plugin.
	 *
	 * This function is called by \Sabre\DAV\Server, after
	 * addPlugin is called.
	 *
	 * This method should set up the required event subscriptions.
	 *
	 * @param \Sabre\DAV\Server $server
	 */
	public function initialize(\Sabre\DAV\Server $server) {
		$server->xml->namespacesMap[self::NS_OWNCLOUD] = 'oc';
		$server->xml->elementMap[self::SHARETYPES_PROPERTYNAME] = 'OCA\\DAV\\Connector\\Sabre\\ShareTypeList';
		$server->protectedProperties[] = self::SHARETYPES_PROPERTYNAME;

		$this->server = $server;
		$this->server->on('propFind', [$this, 'handleGetProperties']);
	}

	/**
	 * Update cachedShareTypes for specific nodeIDs
	 *
	 * @param int[] array of folder/file nodeIDs
	 */
	private function getNodesShareTypes($nodeIDs) {
		$requestedShareTypes = [
			\OCP\Share::SHARE_TYPE_USER,
			\OCP\Share::SHARE_TYPE_GROUP,
			\OCP\Share::SHARE_TYPE_LINK,
			\OCP\Share::SHARE_TYPE_REMOTE
		];

		// Query DB for share types for specified node IDs
		$allShares = $this->shareManager->getAllSharesBy(
			$this->userId,
			$requestedShareTypes,
			$nodeIDs
		);

		// Cache obtained share types
		foreach ($allShares as $share) {
			$currentNodeID = $share->getNodeId();
			$currentShareType = $share->getShareType();
			$this->cachedShareTypes[$currentNodeID][$currentShareType] = true;
		}
	}

	/**
	 * Adds shares to propfind response
	 *
	 * @param PropFind $propFind propfind object
	 * @param \Sabre\DAV\INode $sabreNode sabre node
	 */
	public function handleGetProperties(
		PropFind $propFind,
		\Sabre\DAV\INode $sabreNode
	) {
		if (!($sabreNode instanceof \OCA\DAV\Connector\Sabre\Node)) {
			return;
		}

		// need prefetch ?
		if ($sabreNode instanceof \OCA\DAV\Connector\Sabre\Directory
			&& $propFind->getDepth() !== 0
			&& !is_null($propFind->getStatus(self::SHARETYPES_PROPERTYNAME))
		) {
			$folderNode = $this->userFolder->get($sabreNode->getPath());
			$children = $folderNode->getDirectoryListing();

			// Get ID of parent folder
			$folderNodeID = intval($folderNode->getId());
			$nodeIdsArray = [$folderNodeID];

			// Get IDs for all children of the parent folder
			$this->cachedShareTypes[$folderNodeID] = [];
			foreach ($children as $childNode) {
				// Ensure that they are of File or Folder type
				if (!($childNode instanceof \OCP\Files\File) &&
					!($childNode instanceof \OCP\Files\Folder)) {
					return;
				}

				// Put node ID into an array and initialize cache for it
				$nodeId = intval($childNode->getId());
				array_push($nodeIdsArray, $nodeId);
				$this->cachedShareTypes[$nodeId] = [];
			}

			// Cache share-types obtaining them from DB
			$this->getNodesShareTypes($nodeIdsArray);
		}

		$propFind->handle(self::SHARETYPES_PROPERTYNAME, function() use ($sabreNode) {
			$shareTypesHash = [];
			if (isset($this->cachedShareTypes[$sabreNode->getId()])) {
				// Share types in cache for this node
				$shareTypesHash = $this->cachedShareTypes[$sabreNode->getId()];
			} else {
				// Share types for this node not in cache, obtain if any
				$nodeId = $this->userFolder->get($sabreNode->getPath())->getId();
				$this->cachedShareTypes[$nodeId] = [];
				$this->getNodesShareTypes([$nodeId]);
				$shareTypesHash = $this->cachedShareTypes[$nodeId];
			}
			$shareTypes = array_keys($shareTypesHash);

			return new ShareTypeList($shareTypes);
		});
	}
}
