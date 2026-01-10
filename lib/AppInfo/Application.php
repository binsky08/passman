<?php
/**
 * Nextcloud - passman
 *
 * @copyright Copyright (c) 2016, Sander Brand (brantje@gmail.com)
 * @copyright Copyright (c) 2016, Marcos Zuriaga Miguel (wolfi@wolfi.es)
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\PassmanNext\AppInfo;

use OC\Files\View;
use OC\ServerContainer;
use OCA\PassmanNext\Controller\ShareController;
use OCA\PassmanNext\Middleware\APIMiddleware;
use OCA\PassmanNext\Middleware\ShareMiddleware;
use OCA\PassmanNext\Notifier;
use OCA\PassmanNext\Search\Provider;
use OCA\PassmanNext\Service\ActivityService;
use OCA\PassmanNext\Service\CredentialService;
use OCA\PassmanNext\Service\CronService;
use OCA\PassmanNext\Service\FileService;
use OCA\PassmanNext\Service\NotificationService;
use OCA\PassmanNext\Service\ShareService;
use OCA\PassmanNext\Service\VaultService;
use OCA\PassmanNext\Utility\Utils;
use OCA\UserStatus\Listener\UserDeletedListener;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager;
use OCP\User\Events\BeforeUserDeletedEvent;
use OCP\Util;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'passman-next';
	public const CONFLICTING_APP_ID = 'passman';

	/**
	 * @throws \Exception
	 */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$container = $this->getContainer();
		/** @var IAppManager $appManager */
		$appManager = $container->get(IAppManager::class);

		if ($appManager->isInstalled(self::CONFLICTING_APP_ID)) {
			throw new \Exception(
				'Passman Next won\'t be registered while Passman is installed.'
			);
		}

		$context->registerEventListener(
			BeforeUserDeletedEvent::class,
			UserDeletedListener::class
		);

		$context->registerSearchProvider(Provider::class);

		$context->registerService(View::class, fn() => new View(''), false);

		$context->registerService('isCLI', fn() => \OC::$CLI);

		$context->registerMiddleware(ShareMiddleware::class);
		$context->registerMiddleware(APIMiddleware::class);

		$context->registerService('ShareController', function (ContainerInterface $c) {
			/** @var IUserManager $userManager */
			$userManager = $c->get(IUserManager::class);
			/** @var IUserSession $userSession */
			$userSession = $c->get(IUserSession::class);

			return new ShareController(
				$c->get('AppName'),
				$c->get('Request'),
				$userSession->getUser(),
				$userManager,
				$c->get(ActivityService::class),
				$c->get(VaultService::class),
				$c->get(ShareService::class),
				$c->get(CredentialService::class),
				$c->get(NotificationService::class),
				$c->get(FileService::class),
				$c->get(IManager::class)
			);
		});


		$context->registerService('CronService', fn(ContainerInterface $c) => new CronService(
				$c->get(CredentialService::class),
				$c->get(LoggerInterface::class),
				$c->get(Utils::class),
				$c->get(NotificationService::class),
				$c->get(ActivityService::class)
			));

		$context->registerService('Logger', fn(ContainerInterface $c) => $c->get(ServerContainer::class)->getLogger());
	}

	public function boot(IBootContext $context): void {
		/** @var IManager $manager */
		$manager = $context->getAppContainer()->query(IManager::class);
		$manager->registerNotifierService(Notifier::class);

		Util::addTranslations(self::APP_ID);
	}
}
