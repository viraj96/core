<?php
/**
 * @author Thomas Citharel <tcit@tcit.fr>
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
namespace OCA\DAV\Tests\unit\CalDAV;

use OCA\DAV\CalDAV\Calendar;
use OCP\IL10N;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\PublicCalendarRoot;
use OCP\Security\ISecureRandom;
use Test\TestCase;

/**
 * Class PublicCalendarRootTest
 *
 * @group DB
 *
 * @package OCA\DAV\Tests\unit\CalDAV
 */
class PublicCalendarRootTest extends TestCase {

	const UNIT_TEST_USER = 'principals/users/caldav-unit-test';

	/** @var CalDavBackend */
	private $backend;

	/** @var PublicCalendarRoot */
	private $publicCalendarRoot;

	/** @var IL10N */
	private $l10n;

	/** var IConfig */
	protected $config;

	private $principal;

	/** @var ISecureRandom */
	private $random;

	public function setUp() {
		parent::setUp();

		$db = \OC::$server->getDatabaseConnection();
		$this->principal = $this->getMockBuilder('OCA\DAV\Connector\Sabre\Principal')
			->disableOriginalConstructor()
			->getMock();
		$this->config = \OC::$server->getConfig();
		$this->random = \OC::$server->getSecureRandom();

		$this->backend = new CalDavBackend($db, $this->principal, $this->config, $this->random);

		$this->publicCalendarRoot = new PublicCalendarRoot($this->backend);

		$this->l10n = $this->getMockBuilder('\OCP\IL10N')
			->disableOriginalConstructor()->getMock();
	}

	public function tearDown() {
		parent::tearDown();

		if (is_null($this->backend)) {
			return;
		}
		$books = $this->backend->getCalendarsForUser(self::UNIT_TEST_USER);
		foreach ($books as $book) {
			$this->backend->deleteCalendar($book['id']);
		}
	}

	public function testGetName() {
		$name = $this->publicCalendarRoot->getName();
		$this->assertEquals('public-calendars', $name);
	}

	public function testGetChild() {

		$calendar = $this->createPublicCalendar();

		$publicCalendars = $this->backend->getPublicCalendars();
		$this->assertEquals(1, count($publicCalendars));
		$this->assertEquals(true, $publicCalendars[0]['{http://owncloud.org/ns}public']);

		$publicCalendarURI = $publicCalendars[0]['uri'];

		$calendarResult = $this->publicCalendarRoot->getChild($publicCalendarURI);
		$this->assertEquals($calendar, $calendarResult);
	}

	public function testGetChildren() {
		$this->createPublicCalendar();

		$publicCalendars = $this->backend->getPublicCalendars();

		$calendarResults = $this->publicCalendarRoot->getChildren();

		$this->assertEquals(1, count($calendarResults));
		$this->assertEquals(new Calendar($this->backend, $publicCalendars[0], $this->l10n), $calendarResults[0]);
	}

	/**
	 * @return Calendar
	 */
	protected function createPublicCalendar() {
		$this->backend->createCalendar(self::UNIT_TEST_USER, 'Example', []);

		$calendarInfo = $this->backend->getCalendarsForUser(self::UNIT_TEST_USER)[0];
		$calendar = new Calendar($this->backend, $calendarInfo, $this->l10n);
		$publicUri = $calendar->setPublishStatus(true);

		$calendarInfo = $this->backend->getPublicCalendar($publicUri);
		$calendar = new Calendar($this->backend, $calendarInfo, $this->l10n);

		return $calendar;
	}


}
