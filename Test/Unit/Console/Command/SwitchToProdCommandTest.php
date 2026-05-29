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
 * reversible (never runs silently on top of an existing production config —
 * --force is required for replays), and self-verifying (a post-write
 * read-back from scopeConfig must confirm the persisted config matches what
 * was intended before the operator is told the cutover succeeded).
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

    /**
     * In-memory simulation of core_config_data for the three TBC paths.
     *
     * @var array<string, string>
     */
    private array $configStore = [];

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
     * Wire scopeConfig::getValue to read from the in-memory $configStore so
     * the BEFORE snapshot and the post-write read-back see consistent state.
     */
    private function primeCurrentConfig(string $merchantId, string $encryptedPassword, string $sandboxMode): void
    {
        $this->configStore = [
            SwitchToProdCommand::CONFIG_PATH_MERCHANT_ID => $merchantId,
            SwitchToProdCommand::CONFIG_PATH_PASSWORD => $encryptedPassword,
            SwitchToProdCommand::CONFIG_PATH_SANDBOX_MODE => $sandboxMode,
        ];

        $this->scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path): string => $this->configStore[$path] ?? ''
        );
    }

    /**
     * Wire saveConfig to mutate the in-memory $configStore so the subsequent
     * read-back sees the freshly persisted values (the realistic success
     * path). Returns the ConfigResourceModel mock to preserve fluency.
     */
    private function wireSaveConfigToStore(): void
    {
        $this->configResourceModel->method('saveConfig')
            ->willReturnCallback(
                function (string $path, string $value): ConfigResourceModel {
                    $this->configStore[$path] = $value;
                    return $this->configResourceModel;
                }
            );
    }

    /**
     * Symmetric encrypt/decrypt simulation: encrypt() prefixes "ENC::" and
     * decrypt() strips it. Lets the read-back compare a round-tripped secret
     * against the intended plaintext without a real Crypt key.
     */
    private function wireSymmetricEncryptor(): void
    {
        $this->encryptor->method('encrypt')
            ->willReturnCallback(static fn (string $plain): string => 'ENC::' . $plain);
        $this->encryptor->method('decrypt')
            ->willReturnCallback(
                static fn (string $cipher): string => str_starts_with($cipher, 'ENC::')
                    ? substr($cipher, 5)
                    : $cipher
            );
    }

    /**
     * Happy path: with valid args against a sandbox config, we expect the
     * three config rows to be saved (merchant_id raw, password encrypted,
     * sandbox_mode=0), both cache types flushed, the post-write read-back to
     * pass, and BEFORE + AFTER snapshots logged with the secret masked.
     */
    public function testHappyPathWritesCredsFlipsSandboxAndClearsCaches(): void
    {
        $this->primeCurrentConfig('1549901', 'ENC::test', '1');
        $this->wireSymmetricEncryptor();

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
                    $this->configStore[$path] = $value;
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
        $this->cutoverLogger->expects($this->never())->method('error');

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
            'ENC::PRODSECRET_XYZ_1234',
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
        self::assertStringContainsString('read-back OK', $loggedLines[1]);
        // Secret must never appear raw in the log. Only the ****last4 form.
        self::assertStringNotContainsString('PRODSECRET_XYZ_1234', $loggedLines[1]);
        self::assertStringContainsString('****1234', $loggedLines[1]);
        self::assertStringContainsString('Test card in prod is a real card', $this->tester->getDisplay());
    }

    /**
     * Read-back success path expressed against the realistic store-mutating
     * wiring: saveConfig actually updates the in-memory store, the read-back
     * re-reads it, and the command reports SUCCESS with the AFTER line drawn
     * from the PERSISTED values.
     */
    public function testReadBackVerificationPassesWhenPersistedConfigMatches(): void
    {
        $this->primeCurrentConfig('1549901', 'ENC::sandbox_pw', '1');
        $this->wireSymmetricEncryptor();
        $this->wireSaveConfigToStore();
        $this->cacheTypeList->method('cleanType');

        $loggedInfo = [];
        $this->cutoverLogger->method('info')
            ->willReturnCallback(static function (string $line) use (&$loggedInfo): void {
                $loggedInfo[] = $line;
            });
        $this->cutoverLogger->expects($this->never())->method('error');

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-MATCH',
            '--secret' => 'GoodSecret_4242',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        // Store reflects the new values after the write+read-back round-trip.
        self::assertSame('PROD-MATCH', $this->configStore[SwitchToProdCommand::CONFIG_PATH_MERCHANT_ID]);
        self::assertSame('ENC::GoodSecret_4242', $this->configStore[SwitchToProdCommand::CONFIG_PATH_PASSWORD]);
        self::assertSame('0', $this->configStore[SwitchToProdCommand::CONFIG_PATH_SANDBOX_MODE]);
        self::assertStringContainsString('read-back OK', $loggedInfo[1]);
        self::assertStringNotContainsString('FAILED', $this->tester->getDisplay());
    }

    /**
     * Read-back FAILURE path: a partial/mangled write — here the persisted
     * password decrypts to something other than the intended secret (e.g. a
     * backend model re-keyed it, or a cache flush masked a stale value). The
     * command MUST return FAILURE, log an ERROR (so Sentry alerts), warn the
     * operator NOT to take payments, and NEVER emit the AFTER "success" line.
     */
    public function testReadBackVerificationFailsOnMangledPassword(): void
    {
        $this->primeCurrentConfig('1549901', 'ENC::sandbox_pw', '1');

        // encrypt() round-trips fine for the write...
        $this->encryptor->method('encrypt')
            ->willReturnCallback(static fn (string $plain): string => 'ENC::' . $plain);
        // ...but decrypt() yields a DIFFERENT value on read-back, simulating a
        // corrupted/re-keyed persisted secret.
        $this->encryptor->method('decrypt')
            ->willReturnCallback(static function (string $cipher): string {
                if ($cipher === 'ENC::sandbox_pw') {
                    return 'sandbox_pw';
                }
                return 'TAMPERED_VALUE';
            });

        $this->wireSaveConfigToStore();
        $this->cacheTypeList->method('cleanType');

        $errorLines = [];
        $this->cutoverLogger->method('error')
            ->willReturnCallback(static function (string $line) use (&$errorLines): void {
                $errorLines[] = $line;
            });
        // The AFTER "success" info line must NOT be logged on read-back failure;
        // only the BEFORE snapshot is.
        $infoLines = [];
        $this->cutoverLogger->method('info')
            ->willReturnCallback(static function (string $line) use (&$infoLines): void {
                $infoLines[] = $line;
            });

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-99',
            '--secret' => 'IntendedSecret_8888',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('read-back verification FAILED', $display);
        self::assertStringContainsString('password', $display);
        self::assertStringContainsString('do NOT take payments', $display);

        self::assertNotEmpty($errorLines);
        self::assertStringContainsString('TBC READ-BACK FAILED', $errorLines[0]);
        self::assertStringContainsString('password', $errorLines[0]);
        // Raw intended secret must never leak into the error log.
        self::assertStringNotContainsString('IntendedSecret_8888', $errorLines[0]);
        self::assertStringContainsString('****8888', $errorLines[0]);
        // The tampered/persisted value must never be echoed either.
        self::assertStringNotContainsString('TAMPERED_VALUE', $errorLines[0]);

        // Only the BEFORE snapshot was logged at info — no AFTER success line.
        self::assertCount(1, $infoLines);
        self::assertStringContainsString('TBC BEFORE', $infoLines[0]);
    }

    /**
     * Read-back FAILURE when sandbox_mode did not actually flip to 0 (the
     * cache flush silently failed and scopeConfig still serves the stale '1').
     */
    public function testReadBackVerificationFailsWhenSandboxModeStuck(): void
    {
        $this->primeCurrentConfig('1549901', 'ENC::sandbox_pw', '1');
        $this->wireSymmetricEncryptor();

        // saveConfig writes merchant_id + password to the store but the
        // sandbox_mode flip is "lost" (stuck at '1') — simulating a flush
        // that didn't take.
        $this->configResourceModel->method('saveConfig')
            ->willReturnCallback(
                function (string $path, string $value): ConfigResourceModel {
                    if ($path !== SwitchToProdCommand::CONFIG_PATH_SANDBOX_MODE) {
                        $this->configStore[$path] = $value;
                    }
                    return $this->configResourceModel;
                }
            );
        $this->cacheTypeList->method('cleanType');

        $errorLines = [];
        $this->cutoverLogger->method('error')
            ->willReturnCallback(static function (string $line) use (&$errorLines): void {
                $errorLines[] = $line;
            });

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-STUCK',
            '--secret' => 'Secret_7777',
        ]);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('read-back verification FAILED', $this->tester->getDisplay());
        self::assertStringContainsString('sandbox_mode', $this->tester->getDisplay());
        self::assertNotEmpty($errorLines);
        self::assertStringContainsString('sandbox_mode', $errorLines[0]);
    }

    /**
     * --dry-run MUST print a diff and exit 0 without touching saveConfig,
     * encryptor::encrypt, cache cleanup, or the read-back. The BEFORE snapshot
     * is still logged (it's a read-only audit trail).
     */
    public function testDryRunPrintsDiffAndExitsWithoutWriting(): void
    {
        $this->primeCurrentConfig('1549901', 'ENC::test', '1');
        $this->wireSymmetricEncryptor();

        $this->encryptor->expects($this->never())->method('encrypt');
        $this->configResourceModel->expects($this->never())->method('saveConfig');
        $this->cacheTypeList->expects($this->never())->method('cleanType');
        // Only the BEFORE snapshot is logged on dry-run.
        $this->cutoverLogger->expects($this->once())->method('info');
        $this->cutoverLogger->expects($this->never())->method('error');

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
        $this->primeCurrentConfig('PROD-77', 'ENC::prod_pw', '0');
        $this->wireSymmetricEncryptor();
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
        $this->primeCurrentConfig('PROD-77', 'ENC::prod_pw', '0');
        $this->wireSymmetricEncryptor();
        $this->wireSaveConfigToStore();
        $this->cacheTypeList->expects($this->exactly(2))->method('cleanType');

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-78',
            '--secret' => 'ROTATED_xyz_9999',
            '--force' => true,
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertSame('PROD-78', $this->configStore[SwitchToProdCommand::CONFIG_PATH_MERCHANT_ID]);
        self::assertSame('ENC::ROTATED_xyz_9999', $this->configStore[SwitchToProdCommand::CONFIG_PATH_PASSWORD]);
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
        $this->encryptor->method('decrypt')
            ->willReturnCallback(static fn (string $cipher): string => str_starts_with($cipher, 'ENC::')
                ? substr($cipher, 5)
                : $cipher);

        $this->encryptor->expects($this->once())
            ->method('encrypt')
            ->with('rawSecretAbc1234')
            ->willReturn('ENC::rawSecretAbc1234');

        $savedPasswordValue = null;
        $this->configResourceModel->method('saveConfig')
            ->willReturnCallback(
                function (string $path, string $value) use (&$savedPasswordValue): ConfigResourceModel {
                    $this->configStore[$path] = $value;
                    if ($path === SwitchToProdCommand::CONFIG_PATH_PASSWORD) {
                        $savedPasswordValue = $value;
                    }
                    return $this->configResourceModel;
                }
            );
        $this->cacheTypeList->method('cleanType');

        $exitCode = $this->tester->execute([
            '--merchant-id' => 'PROD-77',
            '--secret' => 'rawSecretAbc1234',
        ]);

        self::assertSame(SymfonyCommand::SUCCESS, $exitCode);
        self::assertSame('ENC::rawSecretAbc1234', $savedPasswordValue);
    }

    public function testMaskTailHelper(): void
    {
        self::assertSame('(empty)', SwitchToProdCommand::maskTail(''));
        self::assertSame('****', SwitchToProdCommand::maskTail('abc'));
        self::assertSame('****', SwitchToProdCommand::maskTail('abcd'));
        self::assertSame('****1234', SwitchToProdCommand::maskTail('abcdef1234'));
    }
}
