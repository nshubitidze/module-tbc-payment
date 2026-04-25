<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Test\Unit\Console\Command;

use Magento\Config\Model\ResourceModel\Config as ConfigResourceModel;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\TbcPayment\Console\Command\SwitchToProdCommand;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Launch-day cutover CLI guardrails for TBC (Flitt).
 *
 * Covers GO_LIVE_CHECKLIST.md §3.1 — the one-command prod switch must be
 * atomic (merchant_id + password + sandbox_mode all rotate in a single
 * invocation), auditable (before/after logged with the secret masked),
 * and reversible (never runs silently on top of an existing production
 * config — --force is required for replays).
 */
class SwitchToProdCommandTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private ConfigResourceModel&MockObject $configResourceModel;
    private EncryptorInterface&MockObject $encryptor;
    private TypeListInterface&MockObject $cacheTypeList;
    private LoggerInterface&MockObject $cutoverLogger;
    private SwitchToProdCommand $command;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->configResourceModel = $this->createMock(ConfigResourceModel::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->cacheTypeList = $this->createMock(TypeListInterface::class);
        $this->cutoverLogger = $this->createMock(LoggerInterface::class);

        $this->command = new SwitchToProdCommand(
            scopeConfig: $this->scopeConfig,
            configResourceModel: $this->configResourceModel,
            encryptor: $this->encryptor,
            cacheTypeList: $this->cacheTypeList,
            cutoverLogger: $this->cutoverLogger,
        );
        $this->tester = new CommandTester($this->command);
    }

    /**
     * Configure scopeConfig to return values for each of the three TBC keys.
     */
    private function primeCurrentConfig(string $merchantId, string $encryptedPassword, string $sandboxMode): void
    {
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path) use ($merchantId, $encryptedPassword, $sandboxMode): string {
                return match ($path) {
                    SwitchToProdCommand::CONFIG_PATH_MERCHANT_ID => $merchantId,
                    SwitchToProdCommand::CONFIG_PATH_PASSWORD => $encryptedPassword,
                    SwitchToProdCommand::CONFIG_PATH_SANDBOX_MODE => $sandboxMode,
                    default => '',
                };
            }
        );
    }

    /**
     * Happy path: with valid args against a sandbox config, we expect the
     * three config rows to be saved (merchant_id raw, password encrypted,
     * sandbox_mode=0), both cache types flushed, and BEFORE + AFTER
     * snapshots logged with the secret masked to last 4 only.
     */
    public function testHappyPathWritesCredsFlipsSandboxAndClearsCaches(): void
    {
        $this->primeCurrentConfig('1549901', 'encrypted_old_pw', '1');

        $this->encryptor->expects($this->once())
            ->method('decrypt')
            ->with('encrypted_old_pw')
            ->willReturn('test');
        $this->encryptor->expects($this->once())
            ->method('encrypt')
            ->with('PRODSECRET_XYZ_1234')
            ->willReturn('ENC:PRODSECRET_XYZ_1234');

        $savedRows = [];
        $this->configResourceModel->expects($this->exactly(3))
            ->method('saveConfig')
            ->willReturnCallback(
                function (
                    string $path,
                    string $value,
                    string $scope,
                    int $scopeId
                ) use (&$savedRows): ConfigResourceModel {
                    $savedRows[$path] = ['value' => $value, 'scope' => $scope, 'scopeId' => $scopeId];
                    return $this->configResourceModel;
                }
            );

        $cleanedTypes = [];
        $this->cacheTypeList->expects($this->exactly(2))
            ->method('cleanType')
            ->willReturnCallback(static function (string $type) use (&$cleanedTypes): void {
                $cleanedTypes[] = $type;
            });

        $loggedLines = [];
        $this->cutoverLogger->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(static function (string $line) use (&$loggedLines): void {
                $loggedLines[] = $line;
            });

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-77',
            '--secret' => 'PRODSECRET_XYZ_1234',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertArrayHasKey(SwitchToProdCommand::CONFIG_PATH_MERCHANT_ID, $savedRows);
        self::assertSame('PROD-77', $savedRows[SwitchToProdCommand::CONFIG_PATH_MERCHANT_ID]['value']);
        self::assertSame(ScopeInterface::SCOPE_DEFAULT, $savedRows[SwitchToProdCommand::CONFIG_PATH_MERCHANT_ID]['scope']);
        self::assertSame(0, $savedRows[SwitchToProdCommand::CONFIG_PATH_MERCHANT_ID]['scopeId']);

        self::assertSame(
            'ENC:PRODSECRET_XYZ_1234',
            $savedRows[SwitchToProdCommand::CONFIG_PATH_PASSWORD]['value']
        );
        self::assertSame('0', $savedRows[SwitchToProdCommand::CONFIG_PATH_SANDBOX_MODE]['value']);

        self::assertSame(
            [SwitchToProdCommand::CACHE_TYPE_CONFIG, SwitchToProdCommand::CACHE_TYPE_FULL_PAGE],
            $cleanedTypes,
        );

        self::assertCount(2, $loggedLines);
        self::assertStringContainsString('TBC BEFORE', $loggedLines[0]);
        self::assertStringContainsString('sandbox_mode=1', $loggedLines[0]);
        self::assertStringContainsString('TBC AFTER', $loggedLines[1]);
        self::assertStringContainsString('sandbox_mode=0', $loggedLines[1]);
        // Secret must never appear raw in the log. Only the ****last4 form.
        self::assertStringNotContainsString('PRODSECRET_XYZ_1234', $loggedLines[1]);
        self::assertStringContainsString('****1234', $loggedLines[1]);
        self::assertStringContainsString('Test card in prod is a real card', $this->tester->getDisplay());
    }

    /**
     * --dry-run MUST print a diff and exit 0 without touching saveConfig,
     * encryptor::encrypt, or cache cleanup. The BEFORE snapshot is still
     * logged (it's a read-only audit trail).
     */
    public function testDryRunPrintsDiffAndExitsWithoutWriting(): void
    {
        $this->primeCurrentConfig('1549901', 'enc_old', '1');

        $this->encryptor->expects($this->once())->method('decrypt')->with('enc_old')->willReturn('test');
        $this->encryptor->expects($this->never())->method('encrypt');
        $this->configResourceModel->expects($this->never())->method('saveConfig');
        $this->cacheTypeList->expects($this->never())->method('cleanType');
        // Only the BEFORE snapshot is logged on dry-run.
        $this->cutoverLogger->expects($this->once())->method('info');

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-77',
            '--secret' => 'PRODSECRET_XYZ_1234',
            '--dry-run' => true,
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('[dry-run]', $display);
        self::assertStringContainsString('sandbox_mode: 1 -> 0', $display);
        self::assertStringContainsString('****1234', $display);
        // Raw secret absolutely must not leak even on dry-run.
        self::assertStringNotContainsString('PRODSECRET_XYZ_1234', $display);
    }

    public function testEmptyMerchantIdRejected(): void
    {
        $this->configResourceModel->expects($this->never())->method('saveConfig');
        $this->cutoverLogger->expects($this->never())->method('info');

        $exitCode = $this->tester->execute([
            '--merchant-id' => '   ',
            '--secret' => 'nonempty',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('merchant-id is required', $this->tester->getDisplay());
    }

    public function testEmptySecretRejected(): void
    {
        $this->configResourceModel->expects($this->never())->method('saveConfig');
        $this->cutoverLogger->expects($this->never())->method('info');

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-77',
            '--secret' => '',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('secret is required', $this->tester->getDisplay());
    }

    /**
     * Re-run guard: if sandbox_mode is already 0 (a previous cutover
     * already landed), do not clobber the production config silently.
     */
    public function testReRunWithoutForceRejectedWhenAlreadyProduction(): void
    {
        $this->primeCurrentConfig('PROD-77', 'enc_prod', '0');
        $this->encryptor->expects($this->once())->method('decrypt')->willReturn('old_prod_pw');
        $this->encryptor->expects($this->never())->method('encrypt');
        $this->configResourceModel->expects($this->never())->method('saveConfig');

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-77',
            '--secret' => 'NEWER_SECRET_xxxx',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('already 0 (production)', $this->tester->getDisplay());
        self::assertStringContainsString('--force', $this->tester->getDisplay());
    }

    public function testReRunWithForceOverwritesProductionConfig(): void
    {
        $this->primeCurrentConfig('PROD-77', 'enc_prod', '0');
        $this->encryptor->method('decrypt')->willReturn('old_prod_pw');
        $this->encryptor->expects($this->once())->method('encrypt')->with('ROTATED_xyz_9999')->willReturn('ENC:ROTATED');

        $this->configResourceModel->expects($this->exactly(3))->method('saveConfig');
        $this->cacheTypeList->expects($this->exactly(2))->method('cleanType');

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-78',
            '--secret' => 'ROTATED_xyz_9999',
            '--force' => true,
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
    }

    /**
     * Trust boundary check: the raw secret must be passed to
     * EncryptorInterface::encrypt() and the RETURN value from encrypt()
     * (not the raw secret) must be what saveConfig() receives for the
     * password path. Skipping encrypt here would store plaintext in
     * core_config_data.
     */
    public function testSecretIsEncryptedBeforeSave(): void
    {
        $this->primeCurrentConfig('1549901', '', '1');
        $this->encryptor->method('decrypt')->willReturn('');

        $this->encryptor->expects($this->once())
            ->method('encrypt')
            ->with('rawSecretAbc1234')
            ->willReturn('ENCRYPTED::rawSecretAbc1234');

        $savedPasswordValue = null;
        $this->configResourceModel->method('saveConfig')
            ->willReturnCallback(
                function (string $path, string $value) use (&$savedPasswordValue): ConfigResourceModel {
                    if ($path === SwitchToProdCommand::CONFIG_PATH_PASSWORD) {
                        $savedPasswordValue = $value;
                    }
                    return $this->configResourceModel;
                }
            );

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-77',
            '--secret' => 'rawSecretAbc1234',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertSame('ENCRYPTED::rawSecretAbc1234', $savedPasswordValue);
    }

    public function testMaskTailHelper(): void
    {
        self::assertSame('(empty)', SwitchToProdCommand::maskTail(''));
        self::assertSame('****', SwitchToProdCommand::maskTail('abc'));
        self::assertSame('****', SwitchToProdCommand::maskTail('abcd'));
        self::assertSame('****1234', SwitchToProdCommand::maskTail('abcdef1234'));
    }
}
