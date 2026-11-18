<?php

declare(strict_types=1);

namespace OCA\DocuSeal\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<SignatureRequest> */
class SignatureRequestMapper extends QBMapper {

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'docuseal_requests', SignatureRequest::class);
	}

	public function find(int $id): SignatureRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findBySubmissionId(int $submissionId): SignatureRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('submission_id', $qb->createNamedParameter($submissionId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return SignatureRequest[] */
	public function findByUserId(string $userId, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'DESC');
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
		return $this->findEntities($qb);
	}

	/** @return SignatureRequest[] */
	public function findByFileId(int $fileId, ?string $userId = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->orderBy('created_at', 'DESC');
		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Return all submissions needing status or delivery work, including a
	 * terminal local state that may be stale after a missed DocuSeal webhook.
	 *
	 * @return SignatureRequest[]
	 */
	public function findPending(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('signed_file_id'))
			->andWhere($qb->expr()->isNotNull('submission_id'))
			->orderBy('created_at', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Atomically claim delivery, or recover a claim older than its lease.
	 * Only one worker can change an unclaimed row in a single UPDATE.
	 */
	public function claimDelivery(int $requestId, string $token, int $claimedAt, int $staleBefore): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('delivery_token', $qb->createNamedParameter($token))
			->set('delivery_claimed_at', $qb->createNamedParameter($claimedAt, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($claimedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('signed_file_id'))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('delivery_token'),
				$qb->expr()->isNull('delivery_claimed_at'),
				$qb->expr()->lte('delivery_claimed_at', $qb->createNamedParameter($staleBefore, IQueryBuilder::PARAM_INT))
			));
		return $qb->executeStatement() === 1;
	}

	/** Record delivery only while this worker still owns its claim. */
	public function completeDelivery(int $requestId, string $token, int $fileId, int $completedAt): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('signed_file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT))
			->set('delivery_token', $qb->createNamedParameter(null))
			->set('delivery_claimed_at', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter($completedAt, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('delivery_token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->isNull('signed_file_id'));
		return $qb->executeStatement() === 1;
	}

	/** Release a failed worker's claim without touching another worker's claim. */
	public function releaseDeliveryClaim(int $requestId, string $token): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('delivery_token', $qb->createNamedParameter(null))
			->set('delivery_claimed_at', $qb->createNamedParameter(null))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('delivery_token', $qb->createNamedParameter($token)))
			->andWhere($qb->expr()->isNull('signed_file_id'));
		$qb->executeStatement();
	}
}
