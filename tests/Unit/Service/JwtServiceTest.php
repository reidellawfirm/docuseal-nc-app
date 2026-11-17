<?php

declare(strict_types=1);

namespace OCA\DocuSeal\Tests\Unit\Service;

use OCA\DocuSeal\AppInfo\Application;
use OCA\DocuSeal\Service\DocuSealAPIService;
use OCA\DocuSeal\Service\JwtService;
use OCP\IAppConfig;
use OCP\Security\ICrypto;
use PHPUnit\Framework\TestCase;

class JwtServiceTest extends TestCase {
	private const API_KEY = 'unit-test-api-key';

	private function createService(string $ownerEmail): JwtService {
		$apiService = $this->createMock(DocuSealAPIService::class);
		$apiService->method('getApiKey')->willReturn(self::API_KEY);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')
			->with(Application::APP_ID, 'docuseal_user_email', '')
			->willReturn($ownerEmail);

		return new JwtService(
			$apiService,
			$appConfig,
			$this->createMock(ICrypto::class),
		);
	}

	public function testMissingOwnerEmailIsRejectedBeforeTokenCreation(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('DocuSeal account email is not configured');

		$this->createService('')->generateBuilderToken(
			'signer@example.com',
			'Test document',
			['https://nextcloud.example/document.pdf'],
		);
	}

	public function testInvalidOwnerEmailIsRejectedBeforeTokenCreation(): void {
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('not a valid email address');

		$this->createService('not-an-email')->generateBuilderToken(
			'signer@example.com',
			'Test document',
			['https://nextcloud.example/document.pdf'],
		);
	}

	public function testTokenUsesTrimmedOwnerEmailAndSeparateIntegrationEmail(): void {
		$token = $this->createService(' owner@example.com ')->generateBuilderToken(
			'signer@example.com',
			'Test document',
			['https://nextcloud.example/document.pdf'],
		);

		$payload = $this->decodePayload($token);
		$this->assertSame('owner@example.com', $payload['user_email']);
		$this->assertSame('signer@example.com', $payload['integration_email']);
		$this->assertSame('Test document', $payload['name']);
		$this->assertSame(['https://nextcloud.example/document.pdf'], $payload['document_urls']);
		$this->assertArrayNotHasKey('template_id', $payload);
		$this->assertGreaterThan($payload['iat'], $payload['exp']);

		[$header, $body, $signature] = explode('.', $token);
		$expected = hash_hmac('sha256', $header . '.' . $body, self::API_KEY, true);
		$this->assertSame($this->base64UrlEncode($expected), $signature);
	}

	public function testTokenOmitsEmptyOptionalIntegrationEmail(): void {
		$token = $this->createService('owner@example.com')->generateBuilderToken(
			'',
			'Test document',
			[],
			123,
		);

		$payload = $this->decodePayload($token);
		$this->assertSame('owner@example.com', $payload['user_email']);
		$this->assertArrayNotHasKey('integration_email', $payload);
		$this->assertSame(123, $payload['template_id']);
		$this->assertArrayNotHasKey('document_urls', $payload);
	}

	private function decodePayload(string $token): array {
		$parts = explode('.', $token);
		$this->assertCount(3, $parts);
		$encoded = $parts[1];
		$json = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
		$this->assertIsString($json);
		return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
	}

	private function base64UrlEncode(string $data): string {
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}
}
