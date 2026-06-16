<?php

declare(strict_types=1);

namespace OCA\FilesCompress\Controller;

use OCA\FilesCompress\Service\ArchiveException;
use OCA\FilesCompress\Service\ArchiveService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends OCSController {
	public function __construct(
		string $appName,
		IRequest $request,
		private ArchiveService $archiveService,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Compress the selected node(s) into a single zip in their parent folder.
	 *
	 * @param int[] $fileids
	 */
	#[NoAdminRequired]
	public function compress(array $fileids = []): DataResponse {
		try {
			return new DataResponse($this->archiveService->compress($this->uid(), $fileids));
		} catch (ArchiveException $e) {
			return new DataResponse(['message' => $e->getMessage()], 400);
		}
	}

	/** Extract one archive into its parent folder. */
	#[NoAdminRequired]
	public function extract(int $fileid = 0): DataResponse {
		try {
			return new DataResponse($this->archiveService->extract($this->uid(), $fileid));
		} catch (ArchiveException $e) {
			return new DataResponse(['message' => $e->getMessage()], 400);
		}
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}
}
