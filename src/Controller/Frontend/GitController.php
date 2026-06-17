<?php

namespace App\Controller\Frontend;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Controller\Controller;
use App\Entity\Manager\SiteManager;
use App\Entity\Manager\UserManager;
use App\Service\Logger;

class GitController extends Controller
{
    private SiteManager $siteEntityManager;
    private UserManager $userManager;
    private string $sshDir;
    private string $configDir;
    private string $homeDir;
    private string $siteDir;
    private string $siteUser;
    private string $webhookHooksFile = '/opt/clp-git-addon/config/webhook-hooks.json';
    private string $webhookPort = '9000';
    private string $deployWrapper = '/opt/clp-git-addon/scripts/clp-git-deploy-wrapper.sh';
    private string $logsDir = '/opt/clp-git-addon/logs';

    public function __construct(
        TranslatorInterface $translator,
        Logger $logger,
        SiteManager $siteEntityManager,
        UserManager $userManager
    ) {
        parent::__construct($translator, $logger);
        $this->siteEntityManager = $siteEntityManager;
        $this->userManager = $userManager;
        $this->sshDir = $_SERVER['HOME'] . '/.ssh';
        $this->homeDir = $_SERVER['HOME'];
    }

    /**
     * Initialize configuration for a specific domain
     *
     * Sets up the site user, directory paths, and SSH configuration file.
     * Creates the config file and directory if they don't exist.
     * Config file is stored in the user's home directory.
     * All file operations are performed as the site user.
     *
     * @param string $domain The domain name to initialize configuration for
     * @return void
     */
    private function initConfig($domain) {
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domain);
        $this->siteUser = $siteEntity->getUser();
        $this->siteDir = "/home/{$siteEntity->getUser()}/htdocs/{$siteEntity->getRootDirectory()}";
        $this->sshDir = "/home/{$siteEntity->getUser()}/.ssh";

        $this->configDir = "/home/{$siteEntity->getUser()}/git-config.json";

        // Check if config file exists as the site user
        if (!$this->fileExistsAsUser($this->configDir)) {
            // Create empty config file as the site user using tee
            $createCommand = sprintf(
                'sudo -u %s bash -c \'echo "{}" > %s\'',
                escapeshellarg($this->siteUser),
                escapeshellarg($this->configDir)
            );
            $output = shell_exec($createCommand . ' 2>&1');

            // Set proper permissions
            $chmodCmd = sprintf(
                'sudo -u %s chmod 600 %s',
                escapeshellarg($this->siteUser),
                escapeshellarg($this->configDir)
            );
            shell_exec($chmodCmd . ' 2>&1');
        }
    }

    /**
     * Get the server's primary public IP address
     *
     * Uses hostname -I as the preferred method, falling back to SERVER_ADDR
     * or localhost if nothing else is available.
     *
     * @return string The server IP address
     */
    private function getServerIp(): string
    {
        $ip = trim(shell_exec("hostname -I | awk '{print \$1}'") ?? '');
        if (empty($ip)) {
            $ip = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
        }
        return $ip;
    }

    /**
     * Generate the webhook URL for a given domain
     *
     * The webhook ID matches the domain name so the URL endpoint is
     * http://<server-ip>:9000/hooks/<domainName>.
     *
     * @param string $domainName The site domain name
     * @return string The generated webhook URL
     */
    private function generateWebhookUrl(string $domainName): string
    {
        return 'http://' . $this->getServerIp() . ':' . $this->webhookPort . '/hooks/' . $domainName;
    }

    /**
     * Ensure a webhook URL exists for a site
     *
     * Generates and persists a webhook URL in the site config if one
     * is not already present.
     *
     * @param string $domainName The site domain name
     * @return string The webhook URL
     */
    private function ensureWebhookUrl(string $domainName): string
    {
        $config = $this->getConfig($domainName) ?? [];
        if (!empty($config['webhook_url'])) {
            return $config['webhook_url'];
        }

        $url = $this->generateWebhookUrl($domainName);
        $this->saveConfig(['webhook_url' => $url], $domainName);
        return $url;
    }

    /**
     * Update the global webhook hooks file for a site
     *
     * Adds or updates a hook entry whose ID matches the domain name and
     * points to the site's deploy script. Removes the entry when no deploy
     * script path is provided.
     *
     * @param string $domainName The site domain name
     * @param string|null $deployScriptPath Path to the deploy script, or null to remove
     * @return void
     */
    private function updateWebhookHooks(string $domainName, ?string $deployScriptPath, ?string $keyFilename = null): array
    {
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);
        $siteUser = $siteEntity->getUser();

        $hooksFile = $this->webhookHooksFile;
        $hooks = [];
        $debug = [
            'domain' => $domainName,
            'site_user' => $siteUser,
            'deploy_script_path' => $deployScriptPath,
            'hooks_file' => $hooksFile,
            'hooks_file_exists' => file_exists($hooksFile),
            'hooks_file_readable' => is_readable($hooksFile),
            'php_user' => function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : 'unknown',
        ];

        if (file_exists($hooksFile) && is_readable($hooksFile)) {
            $content = file_get_contents($hooksFile);
            $hooks = json_decode($content, true) ?: [];
        }

        $debug['existing_hooks_count'] = count($hooks);

        if ($deployScriptPath !== null) {
            $existingIndex = null;
            foreach ($hooks as $index => $hook) {
                if (($hook['id'] ?? '') === $domainName) {
                    $existingIndex = $index;
                    break;
                }
            }

            $arguments = [
                ['source' => 'string', 'name' => $domainName],
                ['source' => 'string', 'name' => $deployScriptPath],
                ['source' => 'string', 'name' => $siteUser],
            ];

            if (!empty($keyFilename)) {
                $keyPath = $this->sshDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $keyFilename);
                $arguments[] = ['source' => 'string', 'name' => $keyPath];
            }

            $hookEntry = [
                'id' => $domainName,
                'execute-command' => $this->deployWrapper,
                'command-working-directory' => dirname($deployScriptPath),
                'pass-arguments-to-command' => $arguments,
                'response-message' => 'Deploy triggered for ' . $domainName,
            ];

            if ($existingIndex !== null) {
                $hooks[$existingIndex] = $hookEntry;
            } else {
                $hooks[] = $hookEntry;
            }
        } else {
            $hooks = array_values(array_filter($hooks, function ($hook) use ($domainName) {
                return ($hook['id'] ?? '') !== $domainName;
            }));
        }

        $jsonContent = json_encode($hooks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $debug['new_hooks_count'] = count($hooks);
        $debug['json_content'] = $jsonContent;
        $debug['php_user'] = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown') : 'unknown';

        // Make sure the config directory exists and is writable.
        // This is normally done by the install script, but we try to recover if permissions were changed.
        $configDir = dirname($hooksFile);
        if (!is_dir($configDir)) {
            @mkdir($configDir, 0755, true);
        }
        if (!file_exists($hooksFile)) {
            @file_put_contents($hooksFile, "[]\n");
        }

        $written = false;

        // Strategy 1: direct write (works when PHP runs as clp and the file is writable)
        if (is_writable($hooksFile) || (!file_exists($hooksFile) && is_writable($configDir))) {
            $written = @file_put_contents($hooksFile, $jsonContent, LOCK_EX);
            $debug['strategy'] = 'direct';
            $debug['direct_result'] = $written;
        }

        // Strategy 2: write via shell as clp using tee
        if ($written === false || $written === 0) {
            $shellCmd = sprintf(
                'echo %s | sudo -n -u clp tee %s > /dev/null && sync',
                escapeshellarg($jsonContent),
                escapeshellarg($hooksFile)
            );
            $shellOutput = shell_exec($shellCmd . ' 2>&1');
            $debug['strategy'] = 'shell_tee';
            $debug['shell_output'] = trim($shellOutput ?? '');

            $verify = @file_get_contents($hooksFile);
            if ($verify === $jsonContent) {
                $written = strlen($jsonContent);
            }
            $debug['shell_verify_match'] = ($verify === $jsonContent);
        }

        // Strategy 3: write via a clp bash redirection
        if ($written === false || $written === 0) {
            $shellCmd = sprintf(
                'sudo -n -u clp bash -c \'echo -n %s > %s\' 2>&1',
                escapeshellarg($jsonContent),
                escapeshellarg($hooksFile)
            );
            $shellOutput = shell_exec($shellCmd);
            $debug['strategy'] = 'shell_redirect';
            $debug['shell_redirect_output'] = trim($shellOutput ?? '');

            $verify = @file_get_contents($hooksFile);
            if ($verify === $jsonContent) {
                $written = strlen($jsonContent);
            }
            $debug['shell_redirect_verify_match'] = ($verify === $jsonContent);
        }

        $debug['final_written'] = $written;

        if ($written === false || $written === 0) {
            $this->logger->error('[updateWebhookHooks] Failed to write hooks file', $debug);
            return [
                'success' => false,
                'debug' => $debug,
            ];
        }

        return [
            'success' => true,
            'debug' => $debug,
        ];
    }

    /**
     * Get the home directory path for a specific domain
     *
     * Constructs the htdocs directory path for the site user.
     *
     * @param string $domain The domain name to get the home directory for
     * @return string The home directory path
     */
    private function getHomeDirectory($domain): string
    {
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domain);
        $path = '/home/';
        $user = $siteEntity->getUser() . '/htdocs';
        return $path . $user;
    }

    /**
     * Display the Git configuration page
     */
    public function index(Request $request, string $domainName): Response
    {
        $this->initConfig($domainName);
        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);
        $defaultKey = $this->getDefaultKey($domainName);
        $config = $this->getConfig($domainName);
        $webhookUrl = $this->ensureWebhookUrl($domainName);

        $deployPath = $config['deploy_path'] ?? $this->siteDir;
        $hasRepo = !empty($deployPath) && $this->hasGitRepo($deployPath, $domainName);

        return $this->render('Frontend/Site/git.html.twig', [
            'site' => $siteEntity,
            'defaultKey' => $defaultKey,
            'gitUrl' => '',
            'config' => $config,
            'rootDirectory' => $this->siteDir,
            'webhookUrl' => $webhookUrl,
            'hasRepo' => $hasRepo
        ]);
    }

    /**
     * Save SSH configuration to the config file
     *
     * Stores the configuration array as JSON with proper permissions.
     * Merges with existing configuration to preserve other fields.
     * The config file is saved in the user's home directory as the site user.
     *
     * @param array $config The configuration array to save
     * @param string $domainName The domain name for the configuration
     * @return void
     */
    public function saveConfig(array $config, string $domainName): void
    {
        $this->initConfig($domainName);
        $file = $this->configDir;

        // Get existing config and merge with new config
        $existingConfig = $this->getConfig($domainName) ?? [];
        $mergedConfig = array_merge($existingConfig, $config);

        // Avoid saving the dynamic deploy_script content in the json file
        unset($mergedConfig['deploy_script']);

        // Encode config to JSON
        $jsonContent = json_encode($mergedConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        // Write directly using bash as the site user
        $writeCmd = sprintf(
            'sudo -u %s bash -c \'cat > %s << EOF
%s
EOF\'',
            escapeshellarg($this->siteUser),
            escapeshellarg($file),
            $jsonContent
        );
        shell_exec($writeCmd . ' 2>&1');

        // Set proper permissions as the site user
        $chmodCmd = sprintf(
            'sudo -u %s chmod 600 %s',
            escapeshellarg($this->siteUser),
            escapeshellarg($file)
        );
        shell_exec($chmodCmd . ' 2>&1');
    }

    /**
     * Retrieve SSH configuration from the config file
     *
     * Reads and decodes the JSON configuration file for the specified domain.
     * File is read as the site user to avoid permission issues.
     *
     * @param string $domainName The domain name to get configuration for
     * @return array|null The configuration array or null if file doesn't exist
     */
    public function getConfig(string $domainName): ?array
    {
        $this->initConfig($domainName);
        $file = $this->configDir;

        // Check if file exists as the site user
        if (!$this->fileExistsAsUser($file)) {
            return null;
        }

        // Read file content as the site user
        $content = $this->readFileAsUser($file);
        $config = json_decode($content, true);

        if (is_array($config) && !empty($config['deploy_script_path'])) {
            $scriptPath = $config['deploy_script_path'];
            if ($this->fileExistsAsUser($scriptPath)) {
                $config['deploy_script'] = $this->readFileAsUser($scriptPath);
            }
        }

        return $config;
    }

    /**
     * Generate a new SSH key pair
     */
    public function generateKey(Request $request, string $domainName): JsonResponse
    {
        $this->initConfig($domainName);

        $data = json_decode($request->getContent(), true);
        $email = $data['email'] ?? 'user@localhost';
        $filename = $data['filename'] ?? 'id_ed25519_' . $domainName;
        $comment = $data['comment'] ?? $email;


        // Sanitizar filename para evitar path traversal
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', $filename);
        $keyPaths = $this->getKeyPaths($filename);
        $privatePath = $keyPaths['private'];
        $publicPath = $keyPaths['public'];

        // create .ssh directory if it doesn't exist
        if (!is_dir($this->sshDir)) {
            $mkdirCmd = sprintf(
                'sudo -u %s mkdir -p %s && sudo -u %s chmod 700 %s',
                escapeshellarg($this->siteUser),
                escapeshellarg($this->sshDir),
                escapeshellarg($this->siteUser),
                escapeshellarg($this->sshDir)
            );

            shell_exec($mkdirCmd . ' 2>&1');
        }

        // prevent recreate key
        if (file_exists($privatePath)) {
            return $this->json([
                'success' => false,
                'path' => $privatePath,
                'message' => 'Key with name ' . $filename . ' already exists'
            ]);
        }

        // generate ssh key
        $command = sprintf(
            'sudo -u %s ssh-keygen -t ed25519 -C %s -f %s -N ""',
            escapeshellarg($this->siteUser),
            escapeshellarg($comment),
            escapeshellarg($privatePath)
        );

        // Using shell_exec (as you requested)
        $output = shell_exec($command . ' 2>&1');
        $returnCode = 0;

        // Alternative with exec to capture return code
        // exec($command . ' 2>&1', $outputArray, $returnCode);
        // $output = implode("\n", $outputArray);

        // wait
        sleep(2);

        if (!$this->fileExistsAsUser($publicPath)) {
            return $this->json([
                'success' => false,
                'message' => 'Error generating key',
                'path' => $privatePath,
                'output' => $output
            ], 500);
        }

        // Ensure correct permissions
        $this->ensureKeyPermissions($privatePath, $publicPath);

        $publicKey = $this->readFileAsUser($publicPath);

        // save the key to the config file
        $config = [
            'filename' => $filename
        ];
        $this->saveConfig($config, $domainName);

        return $this->json([
            'success' => true,
            'message' => 'Key generated successfully',
            'type' => 'ed25519',
            'filename' => $filename,
            'public_key' => $publicKey,
            'private_path' => $privatePath,
            'fingerprint' => $this->getFingerprint($privatePath)
        ]);
    }

    /**
     * Execute a shell command as the site user
     *
     * Runs the specified command using sudo to switch to the site user context.
     * This ensures file operations are performed with the correct user permissions.
     *
     * @param string $command The shell command to execute
     * @return string The command output
     */
    private function runAsUser(string $command): string
    {
        $cmd = sprintf(
            'sudo -u %s bash -c %s 2>&1',
            escapeshellarg($this->siteUser),
            escapeshellarg($command)
        );
        return shell_exec($cmd) ?? '';
    }

    /**
     * Read a file's contents as the site user
     *
     * Reads the specified file using cat command executed as the site user.
     *
     * @param string $path The path to the file to read
     * @return string The file contents
     */
    private function readFileAsUser(string $path): string
    {
        return $this->runAsUser("cat " . escapeshellarg($path));
    }

    /**
     * Check if a file exists as the site user
     *
     * Verifies file existence in the site user context, not as www-data.
     * This is important for checking SSH key files that belong to the site user.
     *
     * @param string $path The path to check
     * @return bool True if the file exists, false otherwise
     */
    private function fileExistsAsUser(string $path): bool
    {
        $output = $this->runAsUser("test -f " . escapeshellarg($path) . " && echo YES || echo NO");
        return trim($output) === 'YES';
    }

    /**
     * Get sanitized SSH key paths
     *
     * Sanitizes the key filename to prevent path traversal attacks and
     * returns both private and public key paths.
     *
     * @param string $keyFile The key filename
     * @return array Array containing 'private' and 'public' key paths
     */
    private function getKeyPaths(string $keyFile): array
    {
        $filename = preg_replace('/[^a-zA-Z0-9_-]/', '', $keyFile);
        return [
            'private' => $this->sshDir . '/' . $filename,
            'public' => $this->sshDir . '/' . $filename . '.pub'
        ];
    }

    /**
     * Ensure correct permissions for SSH keys
     *
     * Sets proper file permissions: 600 for private key (read/write only for owner)
     * and 644 for public key (read/write for owner, read for others).
     *
     * @param string $privatePath Path to the private key file
     * @param string $publicPath Path to the public key file
     * @return void
     */
    private function ensureKeyPermissions(string $privatePath, string $publicPath): void
    {
        $this->runAsUser("chmod 644 " . escapeshellarg($publicPath));
        $this->runAsUser("chmod 600 " . escapeshellarg($privatePath));
    }

    /**
     * Extract the Git host from a repository URL
     *
     * Supports git@host:path, ssh://git@host/path, and https://host/path URLs.
     *
     * @param string $repoUrl The repository URL
     * @return string|null The host name, or null if it cannot be parsed
     */
    private function parseGitHost(string $repoUrl): ?string
    {
        $repoUrl = trim($repoUrl);

        if (preg_match('/^git@([^:]+):/', $repoUrl, $matches)) {
            return $matches[1];
        }

        $host = parse_url($repoUrl, PHP_URL_HOST);
        if (!empty($host)) {
            return $host;
        }

        return null;
    }

    /**
     * Update the site user's SSH config to use the generated key for the Git host
     *
     * This makes plain `git pull` / `git clone` commands work inside the deploy
     * script without having to pass the private key explicitly.
     *
     * @param string $domainName The site domain name
     * @param string $repoUrl The repository URL
     * @param string $keyFilename The SSH key filename (without path)
     * @return bool True on success, false on failure
     */
    private function updateSshConfig(string $domainName, string $repoUrl, string $keyFilename): bool
    {
        $this->initConfig($domainName);
        $host = $this->parseGitHost($repoUrl);

        if (!$host) {
            $this->logger->warning(sprintf('[updateSshConfig] Could not parse host from repo URL: %s', $repoUrl));
            return false;
        }

        $keyFile = $this->sshDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $keyFilename);
        $configFile = $this->sshDir . '/config';

        $entry = sprintf(
            "Host %s\n    HostName %s\n    User git\n    IdentityFile %s\n    IdentitiesOnly yes\n    StrictHostKeyChecking accept-new\n",
            $host,
            $host,
            $keyFile
        );

        // Read existing config as the site user
        $existingConfig = '';
        if ($this->fileExistsAsUser($configFile)) {
            $existingConfig = $this->readFileAsUser($configFile);
        }

        // Replace existing Host entry for the same host, or append a new one
        $pattern = '/Host\s+' . preg_quote($host, '/') . '\b.*?\n(?=Host\s|\z)/s';
        if (preg_match($pattern, $existingConfig)) {
            $newConfig = preg_replace($pattern, $entry, $existingConfig);
        } else {
            $newConfig = rtrim($existingConfig) . "\n\n" . $entry;
        }

        // Ensure .ssh directory exists
        if (!is_dir($this->sshDir)) {
            $mkdirCmd = sprintf(
                'sudo -u %s mkdir -p %s && sudo -u %s chmod 700 %s',
                escapeshellarg($this->siteUser),
                escapeshellarg($this->sshDir),
                escapeshellarg($this->siteUser),
                escapeshellarg($this->sshDir)
            );
            shell_exec($mkdirCmd . ' 2>&1');
        }

        // Write the config file using a temporary file to avoid shell quoting issues
        $tempFile = tempnam(sys_get_temp_dir(), 'ssh-config-');
        file_put_contents($tempFile, $newConfig);
        chmod($tempFile, 0644);

        $copyCmd = sprintf(
            'sudo -u %s cp %s %s && sudo -u %s chmod 600 %s',
            escapeshellarg($this->siteUser),
            escapeshellarg($tempFile),
            escapeshellarg($configFile),
            escapeshellarg($this->siteUser),
            escapeshellarg($configFile)
        );
        $output = shell_exec($copyCmd . ' 2>&1');
        unlink($tempFile);

        if (!$this->fileExistsAsUser($configFile)) {
            $this->logger->error(sprintf('[updateSshConfig] Failed to write SSH config: %s', trim($output ?? '')));
            return false;
        }

        return true;
    }

    /**
     * Wrap a shell command so it runs inside an ssh-agent with the site's key
     *
     * This lets plain `git` commands authenticate without explicit SSH flags.
     *
     * @param string $command The command to wrap
     * @param string|null $keyFilename The SSH key filename (without path)
     * @return string The wrapped command
     */
    private function wrapWithSshAgent(string $command, ?string $keyFilename): string
    {
        if (empty($keyFilename)) {
            return $command;
        }

        $sanitizedKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $keyFilename);
        $keyPath = $this->sshDir . '/' . $sanitizedKey;

        return sprintf(
            'bash -c \'eval $(ssh-agent -s) >/dev/null && ssh-add %s >/dev/null 2>&1 && %s\'',
            escapeshellarg($keyPath),
            $command
        );
    }

    /**
     * Check whether the deploy path already contains a Git repository
     *
     * @param string $deployPath The path to check
     * @param string $domainName The site domain name
     * @return bool True if a .git directory exists
     */
    private function hasGitRepo(string $deployPath, string $domainName): bool
    {
        $this->initConfig($domainName);
        $gitDir = rtrim($deployPath, '/') . '/.git';
        $output = $this->runAsUser("test -d " . escapeshellarg($gitDir) . " && echo YES || echo NO");
        return trim($output) === 'YES';
    }

    /**
     * Check whether the deploy path is empty or does not exist yet
     *
     * @param string $deployPath The path to check
     * @param string $domainName The site domain name
     * @return bool True if the path does not exist or is an empty directory
     */
    private function isDeployPathEmpty(string $deployPath, string $domainName): bool
    {
        $this->initConfig($domainName);
        $output = $this->runAsUser(
            "if [ ! -e " . escapeshellarg($deployPath) . " ]; then echo EMPTY; " .
            "elif [ -d " . escapeshellarg($deployPath) . " ] && [ -z \"\$(ls -A " . escapeshellarg($deployPath) . ")\" ]; then echo EMPTY; " .
            "else echo NOT_EMPTY; fi"
        );
        return trim($output) === 'EMPTY';
    }

    /**
     * Clone a repository into the deploy path as the site user
     *
     * @param string $repoUrl The repository URL
     * @param string $deployPath The destination directory
     * @param string $domainName The site domain name
     * @return array ['success' => bool, 'output' => string]
     */
    private function cloneRepo(string $repoUrl, string $deployPath, string $domainName, ?string $keyFilename = null, bool $forceOverwrite = false): array
    {
        $this->initConfig($domainName);

        if (!$this->isDeployPathEmpty($deployPath, $domainName)) {
            if ($forceOverwrite) {
                $this->runAsUser("rm -rf " . escapeshellarg($deployPath));
            } else {
                return [
                    'success' => false,
                    'output' => sprintf(
                        'Deploy path "%s" already exists and is not empty. ' .
                        'Choose an empty directory, enable "Force overwrite", ' .
                        'or manually initialize a git repository in the existing directory.',
                        $deployPath
                    ),
                    'exit_code' => 1,
                    'blocked' => true,
                ];
            }
        }

        // Ensure parent directory exists
        $parentDir = dirname($deployPath);
        $this->runAsUser("mkdir -p " . escapeshellarg($parentDir));

        $gitCmd = sprintf('cd %s && git clone %s %s 2>&1',
            escapeshellarg($parentDir),
            escapeshellarg($repoUrl),
            escapeshellarg(basename($deployPath))
        );
        $gitCmd = $this->wrapWithSshAgent($gitCmd, $keyFilename);

        $cmd = sprintf(
            'sudo -u %s %s',
            escapeshellarg($this->siteUser),
            $gitCmd
        );

        $exitCode = 0;
        exec($cmd, $outputArray, $exitCode);
        $output = implode("\n", $outputArray);

        return [
            'success' => $exitCode === 0 && $this->hasGitRepo($deployPath, $domainName),
            'output' => $output,
            'exit_code' => $exitCode,
            'blocked' => false,
        ];
    }

    /**
     * Run the deploy script as the site user
     *
     * @param string $deployScriptPath Path to the deploy script
     * @param string $domainName The site domain name
     * @return array ['success' => bool, 'output' => string, 'exit_code' => int]
     */
    private function runDeployScript(string $deployScriptPath, string $domainName, ?string $keyFilename = null): array
    {
        $this->initConfig($domainName);

        $deployCmd = sprintf('cd %s && %s 2>&1',
            escapeshellarg(dirname($deployScriptPath)),
            escapeshellarg($deployScriptPath)
        );
        $deployCmd = $this->wrapWithSshAgent($deployCmd, $keyFilename);

        $cmd = sprintf(
            'sudo -u %s %s',
            escapeshellarg($this->siteUser),
            $deployCmd
        );

        exec($cmd, $outputArray, $exitCode);

        return [
            'success' => $exitCode === 0,
            'output' => implode("\n", $outputArray),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Execute git command with SSH configuration
     *
     * Runs a git command using the specified SSH private key for authentication.
     * Handles SSH options including host key checking and identity file specification.
     * Automatically retries without StrictHostKeyChecking if a host key warning appears.
     * Changes to a temporary directory to avoid permission issues with site directories.
     *
     * @param string $gitCommand The git command to execute (e.g., 'git ls-remote')
     * @param string $repoUrl The repository URL
     * @param string $privateKey Path to the SSH private key
     * @param bool $acceptNewHost Whether to accept new host keys (default: true)
     * @return string The command output
     */
    private function executeGitCommand(string $gitCommand, string $repoUrl, string $privateKey, bool $acceptNewHost = true): string
    {
        $sshOptions = '-o IdentitiesOnly=yes';
        if ($acceptNewHost) {
            $sshOptions .= ' -o StrictHostKeyChecking=accept-new';
        }

        // Use the user's actual home directory instead of site directory to avoid permission issues
        $userHome = "/home/{$this->siteUser}";
        $tmpDir = "{$userHome}/tmp";

        // Create temp directory if it doesn't exist
        $this->runAsUser("mkdir -p " . escapeshellarg($tmpDir));

        $cmd = sprintf(
            'sudo -u %s bash -c \'cd %s && HOME=%s GIT_SSH_COMMAND="ssh -i %s %s" %s %s\' 2>&1',
            escapeshellarg($this->siteUser),
            escapeshellarg($tmpDir),
            escapeshellarg($userHome),
            escapeshellarg($privateKey),
            $sshOptions,
            $gitCommand,
            escapeshellarg($repoUrl)
        );

        $output = shell_exec($cmd);

        // Retry without StrictHostKeyChecking if warning appears
        if ($acceptNewHost && strpos($output, 'Warning: Permanently added') !== false) {
            $cmd = sprintf(
                'sudo -u %s bash -c \'cd %s && HOME=%s GIT_SSH_COMMAND="ssh -i %s -o IdentitiesOnly=yes" %s %s\' 2>&1',
                escapeshellarg($this->siteUser),
                escapeshellarg($tmpDir),
                escapeshellarg($userHome),
                escapeshellarg($privateKey),
                $gitCommand,
                escapeshellarg($repoUrl)
            );
            $output = shell_exec($cmd);
        }

        return $output;
    }

    /**
     * Validate that an SSH key exists and return its paths
     *
     * Checks if the public key file exists for the given key filename.
     * Returns the key paths if valid, null otherwise.
     *
     * @param string $keyFile The key filename to validate
     * @return array|null Array with key paths if valid, null if key doesn't exist
     */
    private function validateKeyExists(string $keyFile): ?array
    {
        $keyPaths = $this->getKeyPaths($keyFile);

        if (!$this->fileExistsAsUser($keyPaths['public'])) {
            return null;
        }

        return $keyPaths;
    }


    /**
     * Test SSH connection to a Git repository
     *
     * Validates that the configured SSH key can successfully authenticate
     * with the specified Git repository. Returns branch information if successful.
     *
     * @param Request $request The HTTP request containing repo_url in JSON body
     * @param string $domainName The domain name to test SSH for
     * @return JsonResponse JSON response with test results and branch information
     */
    public function testGit(Request $request, string $domainName): JsonResponse
    {
        $this->initConfig($domainName);
        $data = json_decode($request->getContent(), true);

        $keyConfig = $this->getConfig($domainName);
        $keyFile = $keyConfig['filename'];

        $keyPaths = $this->validateKeyExists($keyFile);

        if (!$keyPaths) {
            return $this->json([
                'ok' => false,
                'message' => 'Private key not found',
                'suggestion' => 'Generate an SSH key first'
            ]);
        }

        // ensure correct permissions
        $this->runAsUser("chmod 600 " . escapeshellarg($keyPaths['private']));

        // URL of the repo (if provided, test against that specific repo)
        $repoUrl = $data['repo_url'] ?? null;

        if (!$repoUrl) {
            return $this->json([
                'ok' => false,
                'message' => 'No repo URL provided',
                'suggestion' => 'Provide a repo URL to test'
            ]);
        }

        $output = $this->executeGitCommand('git ls-remote', $repoUrl . ' HEAD', $keyPaths['private']);

        if (preg_match('/^[a-f0-9]{40}\s+HEAD/', $output)) {
            $branches = $this->fetchBranches($request, $domainName);
            return $this->json([
                'ok' => true,
                'message' => '✅ SSH works correctly',
                'key' => $keyFile,
                'repo_tested' => $repoUrl,
                'output' => trim($output),
                'branches' => $branches
            ]);
        }

        return $this->json([
            'ok' => false,
            'message' => 'SSH test failed',
            'key' => $keyFile,
            'private' => $keyPaths['private'],
            'repo_tested' => $repoUrl,
            'output' => trim($output)
        ]);
    }

    /**
     * Fetch all branches from a Git repository
     *
     * Retrieves the list of all branches from the specified repository using SSH authentication.
     * Identifies the default branch (main or master) and returns branch information including hashes.
     *
     * @param Request $request The HTTP request containing repo_url in JSON body
     * @param string $domainName The domain name to fetch branches for
     * @return array Array containing branch information with names, hashes, and default branch
     */
    public function fetchBranches(Request $request, string $domainName) {
        $this->initConfig($domainName);

        $keyConfig = $this->getConfig($domainName);
        $keyFile = $keyConfig['filename'];

        $keyPaths = $this->getKeyPaths($keyFile);
        $data = json_decode($request->getContent(), true);
        $repoUrl = $data['repo_url'] ?? null;

        $output = $this->executeGitCommand('git ls-remote --heads', $repoUrl, $keyPaths['private']);

        $branches = [];
        $lines = explode("\n", trim($output));

        foreach ($lines as $line) {
            if (preg_match('/^([a-f0-9]{40})\s+refs\/heads\/(.+)$/', trim($line), $matches)) {
                $branches[] = [
                    'name' => $matches[2],
                    'hash' => $matches[1],
                    'default' => $matches[2] === 'main' || $matches[2] === 'master'
                ];
            }
        }

        // detect default (main or master)
        $defaultBranch = null;
        foreach ($branches as $branch) {
            if ($branch['default']) {
                $defaultBranch = $branch['name'];
                break;
            }
        }

        return [
            'ok' => true,
            'repo' => $repoUrl,
            'default_branch' => $defaultBranch,
            'branches' => $branches,
            'count' => count($branches)
        ];
    }

    /**
     * Get the fingerprint of an SSH key
     *
     * Extracts the SHA256 fingerprint from an SSH private key using ssh-keygen.
     * The fingerprint is useful for verifying key identity.
     *
     * @param string $privatePath Path to the SSH private key file
     * @return string|null The key fingerprint in format 'aa:bb:cc:...' or null if not found
     */
    private function getFingerprint(string $privatePath): ?string
    {
        $output = shell_exec('ssh-keygen -lf ' . escapeshellarg($privatePath) . ' 2>&1');

        if (preg_match('/([a-f0-9]{2}:){15}[a-f0-9]{2}/', $output, $matches)) {
            return $matches[0];
        }

        return null;
    }

    /**
     * Get the default SSH public key
     */
    public function getDefaultKey(string $domainName): array
    {
        $this->initConfig($domainName);
        $config = $this->getConfig($domainName);

        if(empty($config['filename'])) {
            return [
                'success' => false,
                'message' => 'No se encontró clave SSH por defecto',
                'public_key' => null
            ];
        }

        $keyPaths = $this->validateKeyExists($config['filename']);

        if (!$keyPaths) {
            return [
                'success' => false,
                'message' => 'No se encontró clave SSH por defecto',
                'public_key' => null
            ];
        }

        $publicKey = trim($this->readFileAsUser($keyPaths['public']));

        return [
            'success' => true,
            'type' => str_contains($keyPaths['private'], 'ed25519') ? 'ed25519' : 'rsa',
            'path' => $keyPaths['public'],
            'public_key' => $publicKey
        ];
    }

    /**
     * Save Git configuration for a domain
     *
     * Saves Git repository configuration including repo URL, branch, deploy path,
     * and deploy script. The deploy script is saved as a .sh file in the site directory.
     *
     * @param Request $request The HTTP request containing git configuration data
     * @param string $domainName The domain name to save configuration for
     * @return JsonResponse JSON response with save result
     */
    public function saveGitConfig(Request $request, string $domainName): JsonResponse
    {
        $this->initConfig($domainName);

        $data = json_decode($request->getContent(), true);

        $repoUrl = $data['repo_url'] ?? null;
        $branch = $data['branch'] ?? null;
        $deployPath = $data['deploy_path'] ?? null;
        $deployScript = $data['deploy_script'] ?? null;
        $forceOverwrite = !empty($data['force_overwrite']);

        $this->logger->info(sprintf(
            '[saveGitConfig] domain=%s hasDeployScript=%s forceOverwrite=%s',
            $domainName,
            ($deployScript && !empty(trim($deployScript))) ? 'yes' : 'no',
            $forceOverwrite ? 'yes' : 'no'
        ));

        // Get existing config to preserve key file name
        $existingConfig = $this->getConfig($domainName) ?? [];
        $keyFilename = $existingConfig['filename'] ?? null;

        // Save deploy script if provided
        $deployScriptPath = null;
        if ($deployScript && !empty(trim($deployScript))) {
            $deployScriptPath = $this->saveDeployScript($deployScript, $domainName);
            $this->logger->info(sprintf('[saveGitConfig] deployScriptPath=%s', $deployScriptPath ?? 'NULL'));
            if (!$deployScriptPath) {
                return $this->json([
                    'success' => false,
                    'message' => 'Failed to save deploy script'
                ], 500);
            }
        }

        // Build configuration array
        $config = [
            'repo_url' => $repoUrl,
            'branch' => $branch,
            'deploy_path' => $deployPath,
            'deploy_script_path' => $deployScriptPath,
            'filename' => $keyFilename
        ];

        // Remove null values
        $config = array_filter($config, function($value) {
            return $value !== null;
        });

        // Merge with existing config to preserve other fields
        $config = array_merge($existingConfig, $config);

        // Ensure webhook URL exists and persist it
        $webhookUrl = $this->ensureWebhookUrl($domainName);
        $config['webhook_url'] = $webhookUrl;

        // Save configuration
        $this->saveConfig($config, $domainName);

        // Update global webhook hooks file so the webhook server knows about this site
        $hooksResult = $this->updateWebhookHooks($domainName, $deployScriptPath, $keyFilename);

        if (!$hooksResult['success']) {
            $debugInfo = $hooksResult['debug'] ?? [];
            $errorDetail = '';
            if (!empty($debugInfo['strategy'])) {
                $errorDetail .= ' Tried strategy: ' . $debugInfo['strategy'] . '.';
            }
            if (!empty($debugInfo['shell_output'])) {
                $errorDetail .= ' Shell output: ' . substr($debugInfo['shell_output'], 0, 200);
            }
            if (!empty($debugInfo['shell_redirect_output'])) {
                $errorDetail .= ' Redirect output: ' . substr($debugInfo['shell_redirect_output'], 0, 200);
            }
            if (!empty($debugInfo['direct_result'])) {
                $errorDetail .= ' Direct write result: ' . var_export($debugInfo['direct_result'], true);
            }

            return $this->json([
                'success' => false,
                'message' => 'Configuration saved, but failed to update webhook hooks file. Run "clp-git-addon repair" as root to fix permissions.' . $errorDetail,
                'debug' => $debugInfo
            ], 500);
        }

        // Reload the webhook service so it picks up the new hooks file immediately
        shell_exec('systemctl restart clp-git-webhook.service 2>&1');

        // Ensure the SSH key is configured for the Git host so the deploy script can run plain git commands
        if (!empty($keyFilename) && !empty($repoUrl)) {
            $this->updateSshConfig($domainName, $repoUrl, $keyFilename);
        }

        // First deploy: clone the repo if the deploy path does not contain a Git repository yet
        $cloned = false;
        $cloneResult = null;
        $cloneBlocked = false;
        if (!empty($repoUrl) && !empty($deployPath) && !$this->hasGitRepo($deployPath, $domainName)) {
            $cloneResult = $this->cloneRepo($repoUrl, $deployPath, $domainName, $keyFilename, $forceOverwrite);
            $cloned = $cloneResult['success'];
            $cloneBlocked = !empty($cloneResult['blocked']);

            if (!$cloned && !$cloneBlocked) {
                return $this->json([
                    'success' => false,
                    'message' => 'Configuration saved, but failed to clone repository: ' . ($cloneResult['output'] ?: 'unknown error'),
                    'clone_output' => $cloneResult['output'],
                    'clone_exit_code' => $cloneResult['exit_code'],
                    'config' => $config
                ], 500);
            }
        }

        // Run the deploy script immediately after save / clone, unless clone was blocked
        $deployResult = null;
        if (!empty($deployScriptPath) && !$cloneBlocked) {
            $deployResult = $this->runDeployScript($deployScriptPath, $domainName, $keyFilename);
        }

        $message = 'Git configuration saved successfully';
        if ($cloneBlocked) {
            $message = 'Configuration saved. First clone skipped: ' . ($cloneResult['output'] ?? 'deploy path is not empty');
        } elseif ($cloned) {
            $message = 'Repository cloned and deploy script executed';
        } elseif ($deployResult && $deployResult['success']) {
            $message = 'Git configuration saved and deploy script executed';
        }

        return $this->json([
            'success' => true,
            'message' => $message,
            'cloned' => $cloned,
            'clone_blocked' => $cloneBlocked,
            'clone_output' => $cloneResult ? $cloneResult['output'] : null,
            'deploy_output' => $deployResult ? $deployResult['output'] : null,
            'deploy_exit_code' => $deployResult ? $deployResult['exit_code'] : null,
            'config' => $config
        ]);
    }

    /**
     * Save deploy script as a .sh file in the site directory
     *
     * Creates a deploy script file with proper permissions in the site's directory.
     * The script is saved in a .deploy-scripts subdirectory within the site directory.
     * Uses a temporary file to handle multi-line scripts with special characters.
     *
     * @param string $scriptContent The bash script content
     * @param string $domainName The domain name for the site
     * @return string|null The path to the saved script or null on failure
     */
    private function saveDeployScript(string $scriptContent, string $domainName): ?string
    {
        $this->initConfig($domainName);

        $deployScriptsDir = dirname($this->configDir);

        // Generate script filename
        $scriptFilename = 'deploy-' . $domainName . '.sh';
        $scriptPath = $deployScriptsDir . '/' . $scriptFilename;

        $this->logger->info(sprintf('[saveDeployScript] domain=%s scriptPath=%s', $domainName, $scriptPath));

        // Create a temporary file with the script content
        $tempFile = tempnam(sys_get_temp_dir(), 'deploy-script-');
        file_put_contents($tempFile, $scriptContent);
        chmod($tempFile, 0644); // Ensure the site user can read it

        // Copy the temp file to the destination as the site user
        $copyCmd = sprintf(
            'sudo -u %s cp %s %s',
            escapeshellarg($this->siteUser),
            escapeshellarg($tempFile),
            escapeshellarg($scriptPath)
        );
        $output = shell_exec($copyCmd . ' 2>&1');

        $this->logger->info(sprintf('[saveDeployScript] copy output: %s', trim($output)));

        // Remove temp file
        unlink($tempFile);

        if (!$this->fileExistsAsUser($scriptPath)) {
            $this->logger->error(sprintf('[saveDeployScript] script file does not exist after copy: %s', $scriptPath));
            return null;
        }

        // Set executable permissions (readable and executable by clp/webhook service)
        $chmodCmd = sprintf(
            'sudo -u %s chmod 755 %s',
            escapeshellarg($this->siteUser),
            escapeshellarg($scriptPath)
        );
        shell_exec($chmodCmd . ' 2>&1');

        $this->logger->info(sprintf('[saveDeployScript] script saved successfully: %s', $scriptPath));
        return $scriptPath;
    }

    /**
     * Retrieve deploy logs for a site
     *
     * Reads the captured stdout/stderr of the last deploy script executions
     * for the specified domain. Logs are written by the deploy wrapper script
     * invoked by the webhook server.
     *
     * @param Request $request The HTTP request
     * @param string $domainName The site domain name
     * @return JsonResponse JSON response containing the log content
     */
    public function getDeployLogs(Request $request, string $domainName): JsonResponse
    {
        $this->initConfig($domainName);
        $config = $this->getConfig($domainName) ?? [];

        if (empty($config['deploy_script_path'])) {
            return $this->json([
                'success' => true,
                'log' => '',
                'message' => 'No deploy script configured for this site'
            ]);
        }

        $logFile = $this->logsDir . '/deploy-' . $domainName . '.log';

        if (!file_exists($logFile) || !is_readable($logFile)) {
            return $this->json([
                'success' => true,
                'log' => '',
                'message' => 'No logs available yet'
            ]);
        }

        $log = file_get_contents($logFile);

        if ($log === false) {
            return $this->json([
                'success' => false,
                'message' => 'Could not read deploy log'
            ], 500);
        }

        return $this->json([
            'success' => true,
            'log' => $log
        ]);
    }

    /**
     * Trigger a deploy by executing the deploy wrapper directly
     *
     * This endpoint is used by the "Deploy Now" button. It runs the same wrapper
     * script that the webhook server uses, so deploy logs are written the same way.
     *
     * @param Request $request The current request
     * @param string $domainName The site domain name
     * @return JsonResponse The deploy result
     */
    public function triggerDeploy(Request $request, string $domainName): JsonResponse
    {
        $this->initConfig($domainName);
        $config = $this->getConfig($domainName) ?? [];

        if (empty($config['deploy_script_path'])) {
            return $this->json([
                'success' => false,
                'message' => 'No deploy script configured for this site'
            ], 400);
        }

        $siteEntity = $this->siteEntityManager->findOneByDomainName($domainName);
        if (!$siteEntity) {
            return $this->json(['success' => false, 'message' => 'Site not found'], 404);
        }

        $siteUser = $siteEntity->getUser();
        $keyFilename = $config['key_filename'] ?? null;
        $keyPath = '';
        if (!empty($keyFilename)) {
            $keyPath = $this->sshDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $keyFilename);
        }

        $cmd = sprintf(
            '%s %s %s %s %s',
            escapeshellarg($this->deployWrapper),
            escapeshellarg($domainName),
            escapeshellarg($config['deploy_script_path']),
            escapeshellarg($siteUser),
            escapeshellarg($keyPath)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd . ' 2>&1', $output, $exitCode);

        return $this->json([
            'success' => $exitCode === 0,
            'message' => $exitCode === 0 ? 'Deploy triggered successfully' : 'Deploy script failed',
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
        ]);
    }

}
