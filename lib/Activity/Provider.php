<?php

declare(strict_types=1);

namespace OCA\DocuSeal\Activity;

use OCA\DocuSeal\AppInfo\Application;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider as IActivityProvider;
use OCP\L10N\IFactory;
use OCP\IURLGenerator;

class Provider implements IActivityProvider {

	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID) {
			throw new \InvalidArgumentException();
		}

		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$params = $event->getSubjectParameters();
		$fileName = $params['fileName'] ?? 'document';
		$signerName = $params['signerName'] ?? $params['signerEmail'] ?? '';

		$event->setIcon(
			$this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
			)
		);

		switch ($event->getSubject()) {
			case 'signature_requested':
				$event->setParsedSubject(
					$l->t('You requested a signature for "%s"', [$fileName])
				);
				break;
			case 'signature_completed':
				$event->setParsedSubject(
					$l->t('%s signed "%s"', [$signerName, $fileName])
				);
				break;
			case 'signature_declined':
				$event->setParsedSubject(
					$l->t('%s declined to sign "%s"', [$signerName, $fileName])
				);
				break;
			case 'all_signatures_completed':
				$event->setParsedSubject(
					$l->t('All signatures completed for "%s"', [$fileName])
				);
				break;
			case 'signature_cancelled':
				$event->setParsedSubject(
					$l->t('Signature request cancelled for "%s"', [$fileName])
				);
				break;
			default:
				throw new \InvalidArgumentException('Unknown subject: ' . $event->getSubject());
		}

		return $event;
	}
}
