<?php

declare(strict_types=1);

namespace OCA\DocuSeal\Service;

use OCA\DocuSeal\AppInfo\Application;
use OCA\DocuSeal\Db\SignatureRequest;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

class UtilsService {

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/** @throws NotFoundException */
	public function getFileForUser(string $userId, int $fileId): File {
		$files = $this->rootFolder->getUserFolder($userId)->getById($fileId);
		if (empty($files)) {
			throw new NotFoundException('File not found');
		}
		$file = $files[0];
		if (!($file instanceof File)) {
			throw new NotFoundException('Not a file');
		}
		return $file;
	}

	/**
	 * Resolve the original mount first, avoiding an arbitrary folder when the
	 * same file ID is mounted in more than one location.
	 */
	public function getOriginalFileForRequest(SignatureRequest $request): File {
		$filePath = trim($request->getFilePath());
		if ($filePath !== '') {
			$parentPath = dirname($filePath);
			if ($parentPath !== '.' && $parentPath !== '') {
				$files = array_values(array_filter(
					$this->rootFolder->getByIdInPath($request->getFileId(), $parentPath),
					static fn ($file) => $file instanceof File,
				));
				if (count($files) === 1) {
					return $files[0];
				}
			}
		}

		// The original may have moved. Use its current mount only when it is
		// unambiguous; never silently save beside a different shared mount.
		$files = array_values(array_filter(
			$this->rootFolder->getUserFolder($request->getUserId())->getById($request->getFileId()),
			static fn ($file) => $file instanceof File,
		));
		if (count($files) !== 1) {
			throw new RuntimeException('Cannot resolve the original document unambiguously');
		}
		return $files[0];
	}

	public function userHasAccessTo(string $userId, int $fileId): bool {
		try {
			$this->getFileForUser($userId, $fileId);
			return true;
		} catch (NotFoundException $e) {
			return false;
		}
	}

	/** @return array{name: string, email: string}|null */
	public function getUserInfo(string $userId): ?array {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			return null;
		}
		return [
			'name' => $user->getDisplayName(),
			'email' => $user->getEMailAddress() ?? '',
		];
	}

	/**
	 * Save or adopt a signed PDF in the original file's current parent folder.
	 * The deterministic submission name permits crash-safe reconciliation.
	 */
	public function saveSignedDocument(
		SignatureRequest $request,
		string $content,
		?File $originalFile = null,
	): int {
		$originalFile ??= $this->getOriginalFileForRequest($request);
		$folder = $originalFile->getParent();
		if (!($folder instanceof Folder)) {
			throw new RuntimeException('The original document has no writable parent folder');
		}

		$baseName = pathinfo($originalFile->getName(), PATHINFO_FILENAME);
		$signedName = $baseName . '_signed_submission_' . $request->getSubmissionId() . '.pdf';

		if ($folder->nodeExists($signedName)) {
			$existing = $folder->get($signedName);
			if (!($existing instanceof File)) {
				throw new RuntimeException('A folder already uses the signed-document filename');
			}
			if (!hash_equals(hash('sha256', $existing->getContent()), hash('sha256', $content))) {
				throw new RuntimeException('An existing signed-document filename contains different content');
			}
			return $existing->getId();
		}

		$newFile = $folder->newFile($signedName, $content);
		$this->logger->info('Signed document saved beside the original document', [
			'app' => Application::APP_ID,
			'userId' => $request->getUserId(),
			'requestId' => $request->getId(),
			'fileId' => $newFile->getId(),
		]);
		return $newFile->getId();
	}
}
