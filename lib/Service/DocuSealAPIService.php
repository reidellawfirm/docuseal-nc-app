<?php

declare(strict_types=1);

namespace OCA\DocuSeal\Service;

use Exception;
use OCA\DocuSeal\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DocuSealAPIService {

	private const ENCRYPTED_KEYS = ['api_key'];

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private ICrypto $crypto,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Get DocuSeal server base URL
	 */
	public function getServerUrl(): string {
		return rtrim(
			$this->appConfig->getValueString(Application::APP_ID, 'server_url', ''),
			'/'
		);
	}

	/**
	 * Detect a legacy / unencrypted API key value. Encrypted values produced
	 * by ICrypto contain a pipe separator (cipher|iv[|hmac]) with hex/base64
	 * characters; legacy plaintext keys typically don't match that shape.
	 */
	public function looksLikePlaintextKey(string $value): bool {
		if ($value === '') {
			return false;
		}
		if (strpos($value, '|') === false) {
			return true;
		}
		if (!preg_match('/^[A-Za-z0-9+\/=|]+$/', $value)) {
			return true;
		}
		return false;
	}

	/**
	 * Get decrypted API key. Transparently re-encrypts legacy plaintext values.
	 * Throws RuntimeException on a real decryption failure.
	 */
	public function getApiKey(): string {
		$stored = $this->appConfig->getValueString(Application::APP_ID, 'api_key', '');
		if ($stored === '') {
			return '';
		}

		if ($this->looksLikePlaintextKey($stored)) {
			try {
				$encrypted = $this->crypto->encrypt($stored);
				$this->appConfig->setValueString(Application::APP_ID, 'api_key', $encrypted);
			} catch (Exception $e) {
				$this->logger->warning('Failed to re-encrypt legacy DocuSeal API key: ' . $e->getMessage(), [
					'app' => Application::APP_ID,
				]);
			}
			return $stored;
		}

		try {
			return $this->crypto->decrypt($stored);
		} catch (Exception $e) {
			$this->logger->error('Failed to decrypt DocuSeal API key: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
			throw new RuntimeException('Unable to decrypt stored DocuSeal API key. Please re-enter it in the admin settings.', 0, $e);
		}
	}

	/**
	 * Read SSL verification preference. Returns false when self-signed
	 * certificates are explicitly allowed by the admin, true otherwise.
	 */
	public function getVerifyOption(): bool {
		$allowSelfSigned = $this->appConfig->getValueString(Application::APP_ID, 'allow_self_signed', '0');
		return !($allowSelfSigned === '1' || $allowSelfSigned === 'true');
	}

	/**
	 * Check if the integration is configured
	 */
	public function isConfigured(): bool {
		return $this->getServerUrl() !== '' && $this->getApiKey() !== '';
	}

	/**
	 * Make an API request to DocuSeal
	 */
	public function request(string $method, string $endpoint, array $params = []): array {
		$serverUrl = $this->getServerUrl();
		$apiKey = $this->getApiKey();

		if ($serverUrl === '' || $apiKey === '') {
			throw new RuntimeException('DocuSeal is not configured');
		}

		$url = $serverUrl . '/api' . $endpoint;
		$client = $this->clientService->newClient();

		$options = [
			'headers' => [
				'X-Auth-Token' => $apiKey,
				'Accept' => 'application/json',
			],
			'timeout' => 30,
			'http_errors' => false,
			'verify' => $this->getVerifyOption(),
		];

		try {
			if ($method === 'GET') {
				if (!empty($params)) {
					$options['query'] = $params;
				}
				$response = $client->get($url, $options);
			} elseif ($method === 'POST') {
				$options['json'] = $params;
				$response = $client->post($url, $options);
			} elseif ($method === 'PUT') {
				$options['json'] = $params;
				$response = $client->put($url, $options);
			} elseif ($method === 'DELETE') {
				$response = $client->delete($url, $options);
			} else {
				throw new RuntimeException('Unsupported HTTP method: ' . $method);
			}

			$status = $response->getStatusCode();
			$body = (string)$response->getBody();

			if ($status >= 400) {
				$this->logger->error('DocuSeal API HTTP error', [
					'app' => Application::APP_ID,
					'method' => $method,
					'endpoint' => $endpoint,
					'status' => $status,
					'body' => $body,
				]);
				throw new RuntimeException('DocuSeal API error (HTTP ' . $status . '): ' . $body);
			}

			return json_decode($body, true) ?? [];
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Exception $e) {
			$this->logger->error('DocuSeal API error: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
				'method' => $method,
				'endpoint' => $endpoint,
			]);
			throw $e;
		}
	}

	/**
	 * Assign each recipient to a unique party defined by the selected template.
	 * Explicit mappings are keyed as user:<uid> or email:<lowercase-address>.
	 */
	public function assignSubmitterRoles(
		array $submitters,
		array $template,
		array $recipientRoles = [],
		?string $currentUserRole = null,
	): array {
		$parties = array_values($template['submitters'] ?? []);
		if (count($parties) === 0) {
			throw new \InvalidArgumentException('The selected DocuSeal template has no signing parties configured.');
		}
		if (count($submitters) > count($parties)) {
			throw new \InvalidArgumentException(
				'This template supports ' . count($parties) . ' signing parties, but '
				. count($submitters) . ' recipients were selected. Add another signing party in DocuSeal or remove recipients.'
			);
		}

		$roleMap = [];
		foreach ($parties as $party) {
			$name = trim((string)($party['name'] ?? ''));
			if ($name === '') {
				throw new \InvalidArgumentException('Every DocuSeal signing party must have a name.');
			}
			$lookup = mb_strtolower($name);
			if (isset($roleMap[$lookup])) {
				throw new \InvalidArgumentException('The selected DocuSeal template has duplicate signing party names.');
			}
			$roleMap[$lookup] = $name;
		}

		// Reserve explicit choices first so an automatic fallback cannot steal
		// a party that the user assigned to a later recipient.
		$assigned = [];
		$used = [];
		foreach ($submitters as $index => $submitter) {
			$key = $this->recipientRoleKey($submitter);
			$emailKey = 'email:' . mb_strtolower(trim((string)($submitter['email'] ?? '')));
			$role = null;
			if (array_key_exists($key, $recipientRoles)) {
				$role = $recipientRoles[$key];
			} elseif (array_key_exists($emailKey, $recipientRoles)) {
				$role = $recipientRoles[$emailKey];
			}
			if (!empty($submitter['is_current_user']) && $currentUserRole !== null && trim($currentUserRole) !== '') {
				$role = $currentUserRole;
			}
			if ($role !== null && !is_scalar($role)) {
				throw new \InvalidArgumentException('Signing party assignments must be text values.');
			}
			$role = trim((string)$role);
			if ($role === '') {
				$assigned[$index] = null;
				continue;
			}
			$lookup = mb_strtolower($role);
			if (!isset($roleMap[$lookup])) {
				throw new \InvalidArgumentException(
					'Unknown signing party "' . $role . '". Choose a party defined in the selected DocuSeal template.'
				);
			}
			if (isset($used[$lookup])) {
				throw new \InvalidArgumentException(
					'Each recipient must have a different signing party. "' . $roleMap[$lookup] . '" is assigned more than once.'
				);
			}
			$used[$lookup] = true;
			$assigned[$index] = $roleMap[$lookup];
		}

		foreach ($submitters as $index => &$submitter) {
			if ($assigned[$index] === null) {
				$assigned[$index] = $this->firstUnusedParty($parties, $used);
				if ($assigned[$index] === null) {
					throw new \InvalidArgumentException('There are not enough unique signing parties for the selected recipients.');
				}
				$used[mb_strtolower($assigned[$index])] = true;
			}
			$submitter['role'] = $assigned[$index];
		}
		unset($submitter);

		return $submitters;
	}

	private function firstUnusedParty(array $parties, array $used): ?string {
		foreach ($parties as $party) {
			$name = trim((string)($party['name'] ?? ''));
			if ($name !== '' && !isset($used[mb_strtolower($name)])) {
				return $name;
			}
		}
		return null;
	}

	private function recipientRoleKey(array $submitter): string {
		if (!empty($submitter['nc_user_id'])) {
			return 'user:' . $submitter['nc_user_id'];
		}
		return 'email:' . mb_strtolower(trim((string)($submitter['email'] ?? '')));
	}

	/** Ensure a newly uploaded direct document has enough DocuSeal parties. */
	private function ensureTemplateParties(int $templateId, array $templateResult, int $requiredCount): array {
		$parties = array_values($templateResult['submitters'] ?? []);
		$originalCount = count($parties);
		for ($i = $originalCount; $i < $requiredCount; $i++) {
			$parties[] = [
				'name' => $this->ordinalPartyName($i + 1),
				'uuid' => $this->generateUuid(),
			];
		}
		if (count($parties) !== $originalCount) {
			$this->request('PUT', '/templates/' . $templateId, ['submitters' => $parties]);
		}
		return $parties;
	}

	private function ordinalPartyName(int $number): string {
		$names = [
			1 => 'First', 2 => 'Second', 3 => 'Third', 4 => 'Fourth', 5 => 'Fifth',
			6 => 'Sixth', 7 => 'Seventh', 8 => 'Eighth', 9 => 'Ninth', 10 => 'Tenth',
		];
		return ($names[$number] ?? $number . 'th') . ' Party';
	}

	private function generateUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}

	/**
	 * Upload a file directly and create a submission
	 *
	 * DocuSeal workflow: 1) Create template from file via /templates/pdf
	 *                    2) Create submission from that template via /submissions
	 */
	public function createDirectSubmission(
		string $fileContent,
		string $fileName,
		array &$submitters,
		?string $subject = null,
		?string $message = null,
		bool $sendEmail = true,
		?string $expireAt = null,
		array $recipientRoles = [],
		?string $currentUserRole = null,
	): array {
		// Step 1: Create a template from the uploaded file
		$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
		$mimeMap = [
			'pdf' => 'application/pdf',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'doc' => 'application/msword',
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
		];
		$mime = $mimeMap[$ext] ?? 'application/pdf';

		$endpointMap = [
			'pdf' => '/templates/pdf',
			'docx' => '/templates/docx',
			'doc' => '/templates/docx',
			'png' => '/templates/pdf',
			'jpg' => '/templates/pdf',
			'jpeg' => '/templates/pdf',
		];
		$templateEndpoint = $endpointMap[$ext] ?? '/templates/pdf';

		$base64File = 'data:' . $mime . ';base64,' . base64_encode($fileContent);
		$templateName = pathinfo($fileName, PATHINFO_FILENAME) . ' - ' . date('Y-m-d H:i');

		$templateResult = $this->request('POST', $templateEndpoint, [
			'name' => $templateName,
			'documents' => [
				[
					'name' => $fileName,
					'file' => $base64File,
				],
			],
		]);

		$templateId = $templateResult['id'] ?? null;
		if ($templateId === null) {
			throw new RuntimeException('Failed to create template from file');
		}

		$this->logger->info('Created template #' . $templateId . ' from file: ' . $fileName, [
			'app' => Application::APP_ID,
		]);

		// Direct uploads start with one DocuSeal party. Add parties before
		// creating fields so every recipient has a valid submitter UUID.
		$templateResult['submitters'] = $this->ensureTemplateParties(
			$templateId,
			$templateResult,
			count($submitters),
		);

		// Add default signature/date fields only for the legacy direct flow.
		// Builder-configured templates already contain their own fields.
		if (empty($templateResult['fields'])) {
			$documentAttachmentUuid = $templateResult['schema'][0]['attachment_uuid'] ?? null;
			$fields = [];
			foreach ($templateResult['submitters'] as $index => $party) {
				$submitterUuid = $party['uuid'] ?? null;
				if ($submitterUuid === null) {
					continue;
				}
				$y = max(0.08, 0.85 - ($index * 0.12));
				$fields[] = [
					'name' => $this->l10n->t('Signature'),
					'type' => 'signature',
					'required' => true,
					'submitter_uuid' => $submitterUuid,
					'areas' => [[
						'x' => 0.1,
						'y' => $y,
						'w' => 0.35,
						'h' => 0.06,
						'page' => 0,
						'attachment_uuid' => $documentAttachmentUuid,
					]],
				];
				$fields[] = [
					'name' => $this->l10n->t('Date'),
					'type' => 'date',
					'required' => true,
					'submitter_uuid' => $submitterUuid,
					'areas' => [[
						'x' => 0.55,
						'y' => $y,
						'w' => 0.2,
						'h' => 0.04,
						'page' => 0,
						'attachment_uuid' => $documentAttachmentUuid,
					]],
				];
			}
			foreach ($fields as &$field) {
				foreach ($field['areas'] as &$area) {
					if (($area['attachment_uuid'] ?? null) === null) {
						unset($area['attachment_uuid']);
					}
				}
				unset($area);
			}
			unset($field);
			if (!empty($fields)) {
				$this->request('PUT', '/templates/' . $templateId, ['fields' => $fields]);
			}
		}

		// Step 2: Create submission from the template.
		return $this->createTemplateSubmission(
			$templateId,
			$submitters,
			$sendEmail,
			$subject,
			$message,
			$expireAt,
			$recipientRoles,
			$currentUserRole,
		);
	}

	/**
	 * Create a submission from an existing template
	 */
	public function createTemplateSubmission(
		int $templateId,
		array &$submitters,
		bool $sendEmail = true,
		?string $subject = null,
		?string $message = null,
		?string $expireAt = null,
		array $recipientRoles = [],
		?string $currentUserRole = null,
	): array {
		$template = $this->getTemplate($templateId);
		$submitters = $this->assignSubmitterRoles($submitters, $template, $recipientRoles, $currentUserRole);

		$apiSubmitters = $submitters;
		foreach ($apiSubmitters as &$apiSubmitter) {
			unset($apiSubmitter['nc_user_id'], $apiSubmitter['is_current_user']);
		}
		unset($apiSubmitter);

		$params = [
			'template_id' => $templateId,
			'send_email' => $sendEmail,
			'submitters' => $apiSubmitters,
		];

		if ($subject !== null || $message !== null) {
			$params['message'] = [
				'subject' => $subject ?? $this->l10n->t('Signature request'),
				'body' => $message ?? $this->l10n->t('Please sign the attached document.'),
			];
		}

		if ($expireAt !== null) {
			$params['expire_at'] = $expireAt;
		}

		return $this->request('POST', '/submissions', $params);
	}

	/**
	 * Get a submission's details
	 */
	public function getSubmission(int $submissionId): array {
		return $this->request('GET', '/submissions/' . $submissionId);
	}

	/**
	 * List all templates
	 */
	public function getTemplates(?int $limit = 100, ?string $folder = null): array {
		$params = [];
		if ($limit !== null) {
			$params['limit'] = $limit;
		}
		if ($folder !== null) {
			$params['template_folder'] = $folder;
		}
		return $this->request('GET', '/templates', $params);
	}

	/**
	 * Get a single template
	 */
	public function getTemplate(int $templateId): array {
		return $this->request('GET', '/templates/' . $templateId);
	}

	/**
	 * Download a document from a URL
	 */
	public function downloadDocument(string $url): string {
		$client = $this->clientService->newClient();
		$response = $client->get($url, [
			'headers' => [
				'X-Auth-Token' => $this->getApiKey(),
			],
			'timeout' => 120,
			'verify' => $this->getVerifyOption(),
			// Never forward the API key through a redirect to another origin.
			'allow_redirects' => false,
		]);
		return (string)$response->getBody();
	}

	/**
	 * Test the connection to DocuSeal
	 */
	public function testConnection(): array {
		try {
			$result = $this->getTemplates(1);
			return [
				'success' => true,
				'message' => $this->l10n->t('Connection successful'),
			];
		} catch (Exception $e) {
			return [
				'success' => false,
				'message' => $e->getMessage(),
			];
		}
	}
}
