<?php

declare(strict_types=1);

namespace OCA\FilesCompress\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\FilesCompress\Listener\LoadFilesScriptsListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
	public const APP_ID = 'files_compress';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptsListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
