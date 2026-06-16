<?php

declare(strict_types=1);

namespace OCA\FilesCompress\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/** @template-implements IEventListener<LoadAdditionalScriptsEvent> */
class LoadFilesScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!($event instanceof LoadAdditionalScriptsEvent)) {
			return;
		}
		// Bundle carries the @nextcloud/files action registration + toasts;
		// loaded after the Files app so its action registry exists.
		Util::addScript('files_compress', 'files-action', 'files');
	}
}
