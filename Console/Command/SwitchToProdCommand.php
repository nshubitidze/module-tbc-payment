<?php

declare(strict_types=1);

namespace Shubo\TbcPayment\Console\Command;

use Magento\Config\Model\ResourceModel\Config as ConfigResourceModel;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Atomic launch-day cutover from TBC (Flitt) sandbox credentials to production.
 *
 * Usage:
 *     bin/magento shubo:payment:switch-to-prod:tbc \
 *         --merchant-id=<id> --secret=<secret> [--dry-run] [--force]
 *
 * Behaviour (launch-day contract):
 *   1. Snapshot the current merchant_id / password / sandbox_mode values to
 *      var/log/payment-cutover.log with the secret masked to last 4 chars only.
 *   2. Dry-run prints the planned diff and exits 0 without writing anything.
 *   3. Otherwise encrypt the secret via EncryptorInterface and save via
 *      ConfigResourceModel at default scope (matches the system.xml
 *      backend_model=Magento\Config\Model\Config\Backend\Encrypted path).
 *   4. Flip sandbox_mode -> 0 (the project's "production" boolean; environment
 *      as a string key does not exist on TBC — see etc/adminhtml/system.xml).
 *   5. Clear config + full_page caches so the next request reads the new values.
 *   6. Log an after-snapshot + print a one-line confirmation.
 *
 * Guard rails:
 *   - Empty merchant-id or secret => clear error + non-zero exit.
 *   - If sandbox_mode is already 0 (previously switched to prod) => require
 *     --force to overwrite, so a replay doesn't silently re-key prod creds.
 *   - Secret is NEVER printed to stdout or the log — only masked to last 4.
 */
class SwitchToProdCommand extends Command
{
    public const NAME = 'shubo:payment:switch-to-prod:tbc';

    public const OPT_MERCHANT_ID = 'merchant-id';
    public const OPT_SECRET = 'secret';
    public const OPT_DRY_RUN = 'dry-run';
    public const OPT_FORCE = 'force';

    public const CONFIG_PATH_MERCHANT_ID = 'payment/shubo_tbc/merchant_id';
    public const CONFIG_PATH_PASSWORD = 'payment/shubo_tbc/password';
    public const CONFIG_PATH_SANDBOX_MODE = 'payment/shubo_tbc/sandbox_mode';

    public const CACHE_TYPE_CONFIG = 'config';
    public const CACHE_TYPE_FULL_PAGE = 'full_page';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly ConfigResourceModel $configResourceModel,
        private readonly EncryptorInterface $encryptor,
        private readonly TypeListInterface $cacheTypeList,
        private readonly LoggerInterface $cutoverLogger,
        ?string $name = self::NAME,
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setDescription(
            (string) __(
                'Atomically switch TBC (Flitt) payment config from sandbox to production credentials.'
            )
        );
        $this->addOption(
            self::OPT_MERCHANT_ID,
            null,
            InputOption::VALUE_REQUIRED,
            (string) __('Production Flitt merchant ID'),
        );
        $this->addOption(
            self::OPT_SECRET,
            null,
            InputOption::VALUE_REQUIRED,
            (string) __('Production Flitt password / secret key'),
        );
        $this->addOption(
            self::OPT_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            (string) __('Print the planned diff and exit without writing'),
        );
        $this->addOption(
            self::OPT_FORCE,
            null,
            InputOption::VALUE_NONE,
            (string) __('Allow re-running when sandbox_mode is already 0 (production)'),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $merchantId = (string) ($input->getOption(self::OPT_MERCHANT_ID) ?? '');
        $secret = (string) ($input->getOption(self::OPT_SECRET) ?? '');
        $dryRun = (bool) $input->getOption(self::OPT_DRY_RUN);
        $force = (bool) $input->getOption(self::OPT_FORCE);

        if (trim($merchantId) === '') {
            $output->writeln('<error>' . (string) __('merchant-id is required and must not be empty.') . '</error>');
            return Command::FAILURE;
        }
        if (trim($secret) === '') {
            $output->writeln('<error>' . (string) __('secret is required and must not be empty.') . '</error>');
            return Command::FAILURE;
        }

        $currentMerchantId = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_MERCHANT_ID,
            ScopeConfig::SCOPE_TYPE_DEFAULT,
        );
        $currentPasswordRaw = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_PASSWORD,
            ScopeConfig::SCOPE_TYPE_DEFAULT,
        );
        $currentSandboxMode = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_SANDBOX_MODE,
            ScopeConfig::SCOPE_TYPE_DEFAULT,
        );

        $currentPasswordDecrypted = $currentPasswordRaw !== ''
            ? $this->encryptor->decrypt($currentPasswordRaw)
            : '';

        $beforeLine = sprintf(
            'TBC BEFORE merchant_id=%s secret=%s sandbox_mode=%s',
            self::maskTail($currentMerchantId),
            self::maskTail($currentPasswordDecrypted),
            $currentSandboxMode,
        );
        $this->cutoverLogger->info($beforeLine);

        if ($currentSandboxMode === '0' && !$force) {
            $output->writeln(
                '<error>'
                . (string) __(
                    'TBC sandbox_mode is already 0 (production). '
                    . 'Re-run with --force to overwrite the production credentials.'
                )
                . '</error>'
            );
            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln('<info>' . (string) __('[dry-run] Planned TBC production cutover:') . '</info>');
            $output->writeln(sprintf(
                '  merchant_id: %s -> %s',
                $currentMerchantId !== '' ? self::maskTail($currentMerchantId) : '(empty)',
                self::maskTail($merchantId),
            ));
            $output->writeln(sprintf(
                '  password:    %s -> %s',
                $currentPasswordDecrypted !== '' ? self::maskTail($currentPasswordDecrypted) : '(empty)',
                self::maskTail($secret),
            ));
            $output->writeln(sprintf('  sandbox_mode: %s -> 0', $currentSandboxMode));
            $output->writeln('<comment>' . (string) __('No values written (dry-run).') . '</comment>');
            return Command::SUCCESS;
        }

        $encryptedSecret = $this->encryptor->encrypt($secret);

        $this->configResourceModel->saveConfig(
            self::CONFIG_PATH_MERCHANT_ID,
            $merchantId,
            ScopeInterface::SCOPE_DEFAULT,
            0,
        );
        $this->configResourceModel->saveConfig(
            self::CONFIG_PATH_PASSWORD,
            $encryptedSecret,
            ScopeInterface::SCOPE_DEFAULT,
            0,
        );
        $this->configResourceModel->saveConfig(
            self::CONFIG_PATH_SANDBOX_MODE,
            '0',
            ScopeInterface::SCOPE_DEFAULT,
            0,
        );

        $this->cacheTypeList->cleanType(self::CACHE_TYPE_CONFIG);
        $this->cacheTypeList->cleanType(self::CACHE_TYPE_FULL_PAGE);

        $afterLine = sprintf(
            'TBC AFTER merchant_id=%s secret=%s sandbox_mode=%s',
            self::maskTail($merchantId),
            self::maskTail($secret),
            '0',
        );
        $this->cutoverLogger->info($afterLine);

        $output->writeln(
            (string) __(
                'TBC switched to production. Test card in prod is a real card — '
                . 'run the Playwright cutover-smoke spec now.'
            )
        );

        return Command::SUCCESS;
    }

    /**
     * Mask a secret to `****<last 4>`, or `****` when shorter than 4 chars.
     * Only used for log + stdout — never for storage.
     */
    public static function maskTail(string $value): string
    {
        if ($value === '') {
            return '(empty)';
        }
        if (strlen($value) <= 4) {
            return '****';
        }
        return '****' . substr($value, -4);
    }
}
