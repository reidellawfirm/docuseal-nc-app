<?php

declare(strict_types=1);

namespace OCP\AppFramework\Db {
	class Entity {
		protected ?int $id = null;
		public function addType(string $name, string $type): void {}
		public function __call(string $name, array $args) {
			$field = lcfirst(substr($name, 3));
			if (str_starts_with($name, 'get')) return $this->$field;
			if (str_starts_with($name, 'set')) { $this->$field = $args[0]; return; }
			throw new \BadMethodCallException($name);
		}
		public function getId(): ?int { return $this->id; }
		public function setId(int $id): void { $this->id = $id; }
		public static function fromRow(array $row): static {
			$instance = new static();
			foreach ($row as $key => $value) {
				$parts = explode('_', $key);
				$property = array_shift($parts);
				$property .= implode('', array_map('ucfirst', $parts));
				if (!property_exists($instance, $property)) {
					throw new \BadFunctionCallException($property . ' is not a valid attribute');
				}
				$instance->{'set' . ucfirst($property)}($value);
			}
			return $instance;
		}
	}
}
namespace OCP\Files {
	interface File {}
	interface Folder {}
	interface IRootFolder {}
	class NotFoundException extends \Exception {}
}
namespace OCP { interface IUserManager {} }
namespace Psr\Log { interface LoggerInterface {} }
namespace OCA\DocuSeal\AppInfo { class Application { public const APP_ID = 'docuseal_nc_app'; } }
namespace OCA\DocuSeal\Service { class DocuSealAPIService {} }
namespace OCA\DocuSeal\Db { class SignatureRequestMapper {} }

namespace {
	require dirname(__DIR__, 2) . '/lib/Db/SignatureRequest.php';
	require dirname(__DIR__, 2) . '/lib/Service/UtilsService.php';
	require dirname(__DIR__, 2) . '/lib/Service/SignedDocumentService.php';
}

namespace TestDoubles {
	class Logger implements \Psr\Log\LoggerInterface {
		public function __call(string $name, array $args): void {}
	}
	class Api extends \OCA\DocuSeal\Service\DocuSealAPIService {
		public int $downloads = 0;
		public int $submissionReads = 0;
		public string $content = "%PDF-1.7\nsigned";
		public array $submission = [];
		public function getServerUrl(): string { return 'https://sign.reidellawfirm.com'; }
		public function downloadDocument(string $url): string { ++$this->downloads; return $this->content; }
		public function getSubmission(int $id): array { ++$this->submissionReads; return $this->submission; }
	}
	class Mapper extends \OCA\DocuSeal\Db\SignatureRequestMapper {
		public int $claimCalls = 0;
		public int $completeCalls = 0;
		public int $releaseCalls = 0;
		public bool $allowClaim = true;
		public bool $allowComplete = true;
		public function __construct(public \OCA\DocuSeal\Db\SignatureRequest $request) {}
		public function claimDelivery(int $requestId, string $token, int $claimedAt, int $staleBefore): bool {
			++$this->claimCalls;
			return $this->allowClaim;
		}
		public function find(int $id): \OCA\DocuSeal\Db\SignatureRequest { return $this->request; }
		public function completeDelivery(int $requestId, string $token, int $fileId, int $completedAt): bool {
			++$this->completeCalls;
			if ($this->allowComplete) $this->request->setSignedFileId($fileId);
			return $this->allowComplete;
		}
		public function releaseDeliveryClaim(int $requestId, string $token): void { ++$this->releaseCalls; }
	}
	class FileNode implements \OCP\Files\File {
		public function __construct(
			private int $id,
			private string $name = 'original.pdf',
			private $parent = null,
			private string $content = '',
		) {}
		public function getId(): int { return $this->id; }
		public function getName(): string { return $this->name; }
		public function getParent() { return $this->parent; }
		public function getContent(): string { return $this->content; }
	}
	class FolderNode implements \OCP\Files\Folder {
		public array $created = [];
		public string $requestedName = '';
		public function __construct(private ?\OCP\Files\File $existing = null) {}
		public function nodeExists(string $name): bool { return $this->existing !== null; }
		public function get(string $name): \OCP\Files\File { return $this->existing; }
		public function newFile(string $name, $content = null): \OCP\Files\File {
			$this->created = [$name, $content];
			return new FileNode(777, $name, $this, (string)$content);
		}
	}
	class Utils extends \OCA\DocuSeal\Service\UtilsService {
		public int $saves = 0;
		public function __construct(private \OCP\Files\File $original) {}
		public function getOriginalFileForRequest(\OCA\DocuSeal\Db\SignatureRequest $request): \OCP\Files\File {
			return $this->original;
		}
		public function saveSignedDocument(\OCA\DocuSeal\Db\SignatureRequest $request, string $content, ?\OCP\Files\File $originalFile = null): int {
			++$this->saves;
			return 501;
		}
	}
	class Root implements \OCP\Files\IRootFolder {
		public array $byIdInPathCalls = [];
		public function __construct(private array $byPath, private array $byUser) {}
		public function getByIdInPath(int $id, string $path): array {
			$this->byIdInPathCalls[] = [$id, $path];
			return $this->byPath;
		}
		public function getUserFolder(string $userId): self { return $this; }
		public function getById(int $id): array { return $this->byUser; }
	}
}

namespace {
	use OCA\DocuSeal\Db\SignatureRequest;
	use OCA\DocuSeal\Service\SignedDocumentService;
	use OCA\DocuSeal\Service\UtilsService;
	use TestDoubles\{Api, Mapper, Utils, Logger, FileNode, FolderNode, Root};

	$assertions = 0;
	$assert = static function (bool $condition, string $message) use (&$assertions): void {
		++$assertions;
		if (!$condition) throw new \RuntimeException($message);
	};
	$request = static function (): SignatureRequest {
		$r = new SignatureRequest();
		$r->setId(7); $r->setUserId('user'); $r->setFileId(10);
		$r->setSubmissionId(123); $r->setStatus('completed');
		$r->setFilePath('/user/files/Case/original.pdf');
		return $r;
	};
	$fixture = static function () use ($request): array {
		$api = new Api(); $r = $request(); $mapper = new Mapper($r);
		$utils = new Utils(new FileNode(10));
		return [new SignedDocumentService($api, $mapper, $utils, new Logger()), $api, $mapper, $utils, $r];
	};

	[$service, $api, $mapper, $utils, $r] = $fixture();
	$id = $service->ensureSignedDocument($r, ['status'=>'completed', 'combined_document_url'=>'https://sign.reidellawfirm.com/download/signed.pdf']);
	$assert($id === 501, 'New delivery should return the saved file ID');
	$assert($mapper->claimCalls === 1 && $mapper->completeCalls === 1, 'New delivery must atomically claim and complete');
	$assert($api->downloads === 1 && $utils->saves === 1, 'New delivery should download and save exactly once');
	$assert($mapper->releaseCalls === 0, 'Successful delivery must not release its completed claim');
	$assert($service->ensureSignedDocument($r, ['status'=>'completed']) === null, 'Already delivered request must not claim or notify again');
	$assert($mapper->claimCalls === 1 && $api->downloads === 1 && $utils->saves === 1, 'Repeated delivery must be idempotent');

	[$service, $api, $mapper, $utils, $r] = $fixture();
	$mapper->allowClaim = false;
	$assert($service->ensureSignedDocument($r, ['status'=>'completed']) === null, 'Competing worker must not acquire delivery');
	$assert($api->downloads === 0 && $utils->saves === 0, 'Competing worker must not download or create a duplicate');

	[$service, $api, $mapper, $utils, $r] = $fixture();
	$api->submission = ['status'=>'completed', 'documents'=>[['url'=>'/documents/signed.pdf']]];
	$assert($service->ensureSignedDocument($r, ['id'=>123]) === 501, 'Completion webhook without status or URL should fetch authoritative submission');
	$assert($api->submissionReads === 1, 'Missing webhook URL should retrieve submission once');

	[$service, $api, $mapper, $utils, $r] = $fixture();
	$assert($service->ensureSignedDocument($r, ['status'=>'sent']) === null, 'Incomplete submissions must not be delivered');
	$assert($mapper->claimCalls === 0 && $api->downloads === 0, 'Incomplete submissions must not claim or download');

	[$service, $api, $mapper, $utils, $r] = $fixture();
	$assert($service->ensureSignedDocument($r, ['status'=>'completed', 'combined_document_url'=>'https://attacker.invalid/signed.pdf']) === null, 'Cross-origin download must fail closed');
	$assert($api->downloads === 0 && $mapper->releaseCalls === 1 && $r->getSignedFileId() === null, 'Rejected origin must release claim without recording delivery');

	[$service, $api, $mapper, $utils, $r] = $fixture();
	$api->content = '<html>not a pdf</html>';
	$assert($service->ensureSignedDocument($r, ['status'=>'completed', 'combined_document_url'=>'https://sign.reidellawfirm.com/download/signed.pdf']) === null, 'Invalid PDF must not be delivered');
	$assert($utils->saves === 0 && $mapper->releaseCalls === 1, 'Invalid PDF must not save a file and must release claim');

	[$service, $api, $mapper, $utils, $r] = $fixture();
	$mapper->allowComplete = false;
	$assert($service->ensureSignedDocument($r, ['status'=>'completed', 'combined_document_url'=>'https://sign.reidellawfirm.com/download/signed.pdf']) === null, 'Expired completion claim must not report delivery');
	$assert($mapper->releaseCalls === 1, 'Expired completion claim must not be released as another worker claim');

	// Real file utility: deterministic PDF naming and original-path resolution.
	$folder = new FolderNode();
	$original = new FileNode(10, 'contract.docx', $folder);
	$root = new Root([$original], []);
	$utils = new UtilsService($root, new class implements \OCP\IUserManager {}, new Logger());
	$requestWithPath = $request();
	$assert($utils->getOriginalFileForRequest($requestWithPath) === $original, 'Original mount must resolve through its stored parent path');
	$assert($root->byIdInPathCalls === [[10, '/user/files/Case']], 'Original file ID must resolve under its original parent');
	$id = $utils->saveSignedDocument($requestWithPath, "%PDF-1.7\nsigned", $original);
	$assert($id === 777, 'Real file utility should return new file ID');
	$assert($folder->created === ['contract_signed_submission_123.pdf', "%PDF-1.7\nsigned"], 'Signed PDF must be written into original current parent folder');

	$existing = new FileNode(901, 'contract_signed_submission_123.pdf', null, "%PDF-1.7\nsigned");
	$folder = new FolderNode($existing);
	$original = new FileNode(10, 'contract.docx', $folder);
	$utils = new UtilsService(new Root([$original], []), new class implements \OCP\IUserManager {}, new Logger());
	$assert($utils->saveSignedDocument($requestWithPath, "%PDF-1.7\nsigned", $original) === 901, 'Retry must adopt exact orphaned file left by crash');
	$assert($folder->created === [], 'Reconciling an orphaned file must not create a duplicate');

	$existing = new FileNode(902, 'contract_signed_submission_123.pdf', null, "%PDF-1.7\ndifferent");
	$folder = new FolderNode($existing);
	$original = new FileNode(10, 'contract.docx', $folder);
	$utils = new UtilsService(new Root([$original], []), new class implements \OCP\IUserManager {}, new Logger());
	try {
		$utils->saveSignedDocument($requestWithPath, "%PDF-1.7\nsigned", $original);
		throw new \RuntimeException('Different existing content was overwritten');
	} catch (\RuntimeException $e) {
		$assert(str_contains($e->getMessage(), 'different content'), 'Different existing file content must not be overwritten');
	}

	$utils = new UtilsService(new Root([], [new FileNode(10), new FileNode(10)]), new class implements \OCP\IUserManager {}, new Logger());
	try {
		$utils->getOriginalFileForRequest($requestWithPath);
		throw new \RuntimeException('Ambiguous file mounts were accepted');
	} catch (\RuntimeException $e) {
		$assert(str_contains($e->getMessage(), 'unambiguously'), 'Ambiguous mounts must fail closed instead of using arbitrary folder');
	}

	$apiSource = file_get_contents(dirname(__DIR__, 2) . '/lib/Service/DocuSealAPIService.php');
	$assert(str_contains($apiSource, "'allow_redirects' => false"), 'Credentialed PDF download must disable redirects');
	$mapperSource = file_get_contents(dirname(__DIR__, 2) . '/lib/Db/SignatureRequestMapper.php');
	$assert(str_contains($mapperSource, 'function claimDelivery') && str_contains($mapperSource, 'function completeDelivery'), 'Mapper must provide atomic claim/completion operations');
	$assert(str_contains($mapperSource, "isNull('signed_file_id')"), 'Delivery query must select undelivered requests');
	$assert(is_file(dirname(__DIR__, 2) . '/lib/Migration/Version1016Date20261118110000.php'), 'Delivery-claim schema migration must exist');

	$hydrated = \OCA\DocuSeal\Db\SignatureRequest::fromRow(['delivery_token' => 'claim-token', 'delivery_claimed_at' => 123]);
	$assert($hydrated->getDeliveryToken() === 'claim-token' && $hydrated->getDeliveryClaimedAt() === 123, 'Database rows must hydrate delivery-claim entity attributes');

	echo "PASS: $assertions hardened signed-document delivery assertions\n";
}
