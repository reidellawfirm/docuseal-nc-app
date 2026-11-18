<?php

declare(strict_types=1);

namespace OCA\DocuSeal\Service;

use OCA\DocuSeal\AppInfo\Application;
use OCA\DocuSeal\Db\SignatureRequest;
use OCA\DocuSeal\Db\SignatureRequestMapper;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Deliver each completed DocuSeal submission to Nextcloud exactly once.
 */
class SignedDocumentService {
	private const CLAIM_LEASE_SECONDS = 900;

	public function __construct(
		private DocuSealAPIService $docuSealAPIService,
		private SignatureRequestMapper $requestMapper,
		private UtilsService $utilsService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Return a file ID only when this invocation newly records a delivery.
	 * Null means already delivered, incomplete, or owned by another worker.
	 */
	public function ensureSignedDocument(SignatureRequest $request, array $submission): ?int {
		if ($request->getSignedFileId() !== null) {
			return null;
		}
		// A submission.completed webhook may omit status; its event itself is
		// authoritative. Other events must explicitly report completion.
		if (isset($submission['status']) && $submission['status'] !== 'completed') {
			return null;
		}
		if ($request->getSubmissionId() === null) {
			throw new RuntimeException('Cannot save a signed document without a DocuSeal submission ID');
		}

		$token = bin2hex(random_bytes(16));
		$now = time();
		if (!$this->requestMapper->claimDelivery(
			$request->getId(),
			$token,
			$now,
			$now - self::CLAIM_LEASE_SECONDS,
		)) {
			return null;
		}

		try {
			// Re-read after the atomic claim so status and saved-file state are current.
			$current = $this->requestMapper->find($request->getId());
			if ($current->getSignedFileId() !== null) {
				return null;
			}

			$originalFile = $this->utilsService->getOriginalFileForRequest($current);
			$documentUrl = $this->findDocumentUrl($submission);
			if ($documentUrl === null) {
				$submission = $this->docuSealAPIService->getSubmission($current->getSubmissionId());
				$documentUrl = $this->findDocumentUrl($submission);
			}
			if ($documentUrl === null) {
				throw new RuntimeException('No signed PDF URL is available for this completed submission');
			}
			$documentUrl = $this->validateDocumentUrl($documentUrl);

			$content = $this->docuSealAPIService->downloadDocument($documentUrl);
			if (!str_starts_with($content, '%PDF-')) {
				throw new RuntimeException('DocuSeal returned an invalid signed PDF response');
			}

			// The deterministic submission-specific name lets a later retry adopt
			// a file left behind by a crash, but only after an exact content match.
			$fileId = $this->utilsService->saveSignedDocument($current, $content, $originalFile);
			if (!$this->requestMapper->completeDelivery($current->getId(), $token, $fileId, time())) {
				throw new RuntimeException('Signed-document delivery claim expired before completion');
			}
			$request->setSignedFileId($fileId);

			$this->logger->info('Signed document delivered to Nextcloud', [
				'app' => Application::APP_ID,
				'requestId' => $current->getId(),
				'fileId' => $fileId,
			]);

			return $fileId;
		} catch (Throwable $e) {
			$this->requestMapper->releaseDeliveryClaim($request->getId(), $token);
			$this->logger->error('Signed document delivery failed: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'requestId' => $request->getId(),
			]);
			return null;
		}
	}

	private function findDocumentUrl(array $submission): ?string {
		if (!empty($submission['combined_document_url'])) {
			return $submission['combined_document_url'];
		}
		if (!empty($submission['documents'][0]['url'])) {
			return $submission['documents'][0]['url'];
		}
		foreach ($submission['submitters'] ?? [] as $submitter) {
			if (!empty($submitter['documents'][0]['url'])) {
				return $submitter['documents'][0]['url'];
			}
		}
		return null;
	}

	/** Only send the DocuSeal API key to the configured DocuSeal origin. */
	private function validateDocumentUrl(string $documentUrl): string {
		$serverUrl = $this->docuSealAPIService->getServerUrl();
		if ($serverUrl === '') {
			throw new RuntimeException('DocuSeal server URL is not configured');
		}
		if (str_starts_with($documentUrl, '/')) {
			$documentUrl = $serverUrl . $documentUrl;
		}

		$server = parse_url($serverUrl);
		$target = parse_url($documentUrl);
		if ($server === false || $target === false || empty($target['scheme']) || empty($target['host'])
			|| isset($target['user']) || isset($target['pass'])) {
			throw new RuntimeException('Invalid signed document URL');
		}

		$serverPort = $server['port'] ?? (($server['scheme'] ?? '') === 'https' ? 443 : 80);
		$targetPort = $target['port'] ?? ($target['scheme'] === 'https' ? 443 : 80);
		if (strcasecmp($server['scheme'] ?? '', $target['scheme']) !== 0
			|| strcasecmp($server['host'] ?? '', $target['host']) !== 0
			|| $serverPort !== $targetPort) {
			throw new RuntimeException('Signed document URL does not match the configured DocuSeal server');
		}

		return $documentUrl;
	}
}
