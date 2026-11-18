<?php

declare(strict_types=1);

namespace OCA\DocuSeal\BackgroundJob;

use Exception;
use OCA\DocuSeal\AppInfo\Application;
use OCA\DocuSeal\Db\SignatureRequest;
use OCA\DocuSeal\Db\SignatureRequestMapper;
use OCA\DocuSeal\Db\SignatureRequestSubmitterMapper;
use OCA\DocuSeal\Service\DocuSealAPIService;
use OCA\DocuSeal\Service\SignedDocumentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

class CheckSignatureStatus extends TimedJob {

	public function __construct(
		ITimeFactory $time,
		private DocuSealAPIService $docuSealAPIService,
		private SignedDocumentService $signedDocumentService,
		private SignatureRequestMapper $requestMapper,
		private SignatureRequestSubmitterMapper $submitterMapper,
		private INotificationManager $notificationManager,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		// Run every 15 minutes
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		if (!$this->docuSealAPIService->isConfigured()) {
			return;
		}

		$pendingRequests = $this->requestMapper->findPending();

		foreach ($pendingRequests as $request) {
			try {
				$this->checkRequest($request);
			} catch (Exception $e) {
				$this->logger->error('Error checking signature status for request ' . $request->getId() . ': ' . $e->getMessage(), [
					'app' => Application::APP_ID,
				]);
			}
		}
	}

	private function checkRequest(SignatureRequest $request): void {
		$submissionId = $request->getSubmissionId();
		if ($submissionId === null) {
			return;
		}

		$submission = $this->docuSealAPIService->getSubmission($submissionId);
		$remoteStatus = $submission['status'] ?? null;

		if ($remoteStatus === null) {
			return;
		}

		// Update submitters
		$localSubmitters = $this->submitterMapper->findByRequestId($request->getId());
		$remoteSubmitters = $submission['submitters'] ?? [];

		foreach ($localSubmitters as $localSub) {
			foreach ($remoteSubmitters as $remoteSub) {
				if ($localSub->getDocusealSubmitterId() === ($remoteSub['id'] ?? null)) {
					$newStatus = $remoteSub['status'] ?? $localSub->getStatus();
					if ($newStatus !== $localSub->getStatus()) {
						$localSub->setStatus($newStatus);
						if ($newStatus === 'completed' && $localSub->getCompletedAt() === null) {
							$localSub->setCompletedAt(time());

							// Send notification for individual completion
							$this->sendNotification(
								$request->getUserId(),
								'signature_completed',
								[
									'requestId' => $request->getId(),
									'fileName' => $request->getFileName(),
									'signerEmail' => $localSub->getEmail(),
									'signerName' => $localSub->getName(),
								]
							);
						}
						$this->submitterMapper->update($localSub);
					}
					break;
				}
			}
		}

		// A completed local status may be stale after a missed webhook. Always
		// retry delivery from the authoritative remote submission, even when
		// the local state is terminal, and only notify the worker that saves it.
		$deliveredFileId = null;
		if ($request->getSignedFileId() === null && $remoteStatus === 'completed') {
			$deliveredFileId = $this->signedDocumentService->ensureSignedDocument($request, $submission);
		}

		// Do not regress a completed local request to an older remote state.
		if ($remoteStatus !== $request->getStatus()
			&& ($request->getStatus() !== 'completed' || $remoteStatus === 'completed')) {
			$request->setStatus($remoteStatus);
			$request->setUpdatedAt(time());
			$this->requestMapper->update($request);
		}

		if ($deliveredFileId !== null) {
			$this->sendNotification(
				$request->getUserId(),
				'all_signatures_completed',
				[
					'requestId' => $request->getId(),
					'fileName' => $request->getFileName(),
				]
			);
		}
	}

	private function sendNotification(string $userId, string $subject, array $params): void {
		try {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setDateTime(new \DateTime())
				->setObject('signature_request', (string)($params['requestId'] ?? '0'))
				->setSubject($subject, $params);

			$this->notificationManager->notify($notification);
		} catch (Exception $e) {
			$this->logger->error('Error sending notification: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
		}
	}
}
