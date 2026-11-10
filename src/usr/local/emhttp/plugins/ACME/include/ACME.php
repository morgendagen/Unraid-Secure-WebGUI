<?php
require_once(__DIR__ . "/DnsApiInfo.php");

class ACME {
    /** The plugin's config directory. */
    public $plugin;
    /** The plugin's emhttp directory. */
    public $emhttp;
    /** The install location of `acme.sh`. */
    public $acmeshHome;
    /** The `acme.sh` config home directory. */
    public $acmeshConfigHome;
    /** Cached contents of `account.conf`. */
    private $accountConf = null;
    /** Cached output of `acme.sh --info --domain`, per domain. */
    private $domainConf = array();
    /** Cached contents of `dnsapi_info.txt`, see `getDnsProviders()`. */
    private $dnsProviders = null;

    /** `acme.sh` syslog levels, see `acme.sh --help`. */
    const SYSLOG_LEVEL_ERROR = 3;
    const SYSLOG_LEVEL_DEBUG = 7;

    /**
     * `acme.sh` exit code when the certificate is still valid and was not
     * renewed, see `RENEW_SKIP` in `acme.sh`.
     */
    const RENEW_SKIP = 2;

    /** The staging server used by `acme.sh --staging`, see `DEFAULT_STAGING_CA` in `acme.sh`. */
    const STAGING_CA = 'https://acme-staging-v02.api.letsencrypt.org/directory';

    /**
     * Value sent to the browser in place of a saved secret. Submitting it
     * unchanged means "keep the saved value".
     */
    const SECRET_PLACEHOLDER = '***PASSWORD***';

    /** Option names matching this (case-insensitive) pattern are secrets. */
    const SECRET_PATTERN = 'pass|pwd|secret|token|key|auth|credential|cookie';

    /** Markers used by `acme.sh` for base64 encoded config values. */
    const B64CONF_START = '__ACME_BASE64__START_';
    const B64CONF_END = '__ACME_BASE64__END_';

    /** Fallback PATH if the web server runs PHP with an empty environment. */
    const DEFAULT_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    function __construct() {
        $this->plugin = '/boot/config/plugins/ACME';
        $this->emhttp = '/usr/local/emhttp/plugins/ACME';
        $this->acmeshHome = '/usr/share/ACME/acme.sh';
        $this->acmeshConfigHome = $this->plugin;
    }

    /**
     * Check whether a DNS provider option holds a secret.
     *
     * @param string $name Option name, e.g. `CF_Token`.
     * @return bool
     */
    static function isSecretOption($name) {
        return preg_match('/' . self::SECRET_PATTERN . '/i', $name) === 1;
    }

    /**
     * Check whether a string is usable as an environment variable name.
     *
     * Names starting with `Le_` are rejected, as `acme.sh` uses those for
     * its own per-domain settings.
     *
     * @param mixed $name
     * @return bool
     */
    static function isValidOptionName($name) {
        return is_string($name)
            && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1
            && !str_starts_with($name, 'Le_');
    }

    /**
     * Check whether `$dnsProvider` is the id of an installed dnsapi script,
     * described in `dnsapi_info.txt`.
     *
     * @param mixed $dnsProvider DNS provider id, e.g. `dns_cf`.
     * @return bool
     */
    function isValidDnsProvider($dnsProvider) {
        return is_string($dnsProvider)
            && preg_match('/^dns_[a-z0-9_]+$/', $dnsProvider) === 1
            && is_file($this->acmeshHome . "/dnsapi/$dnsProvider.sh")
            && in_array($dnsProvider, array_column($this->getDnsProviders() ?? array(), 'Id'), true);
    }

    /**
     * Get the DNS providers described in `dnsapi_info.txt`.
     *
     * @return array[]|null Providers, see `DnsApiInfo::parse()`, or `null`
     *                      if the info file could not be read.
     */
    function getDnsProviders() {
        if ($this->dnsProviders === null) {
            $text = @file_get_contents($this->getDnsProvidersFile());
            if ($text === false) {
                return null;
            }
            $this->dnsProviders = DnsApiInfo::parse($text);
        }
        return $this->dnsProviders;
    }

    /**
     * Get the location of `dnsapi_info.txt`.
     *
     * @return string File path.
     */
    function getDnsProvidersFile() {
        return $this->emhttp . "/dnsapi_info.txt";
    }

    /**
     * Get the option names a DNS provider declares, both `Options` and
     * `OptionsAlt`. These are the inputs the page shows for the provider.
     *
     * @param string $dnsProvider DNS provider id, see `isValidDnsProvider()`.
     * @return string[] Option names, empty if the provider is unknown.
     */
    function getDnsProviderOptionNames($dnsProvider) {
        foreach ($this->getDnsProviders() ?? array() as $provider) {
            if ($provider['Id'] === $dnsProvider) {
                return array_column(array_merge($provider['Opts'], $provider['OptsAlt']), 'Name');
            }
        }
        return array();
    }

    /**
     * Get the Unraid server settings.
     *
     * @return array Contents of `var.ini`.
     */
    protected function getServerVars() {
        $vars = @parse_ini_file('/var/local/emhttp/var.ini');
        return is_array($vars) ? $vars : array();
    }

    /**
     * Get the server name, validated as a DNS label.
     *
     * @return string|null Server name, or `null` if invalid.
     */
    private function getServerName() {
        $vars = $this->getServerVars();
        $name = ($vars['NAME'] ?? '') ?: 'tower';
        if (preg_match('/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $name) !== 1) {
            return null;
        }
        return $name;
    }

    /**
     * Get the domain to issue the certificate for: the server name plus the
     * local TLD configured in Management Access.
     *
     * @return string|null Domain, or `null` if the server name or local TLD is invalid.
     */
    function getCertificateDomain() {
        $name = $this->getServerName();
        if ($name === null) {
            return null;
        }
        $tld = $this->getServerVars()['LOCAL_TLD'] ?? '';
        $domain = $tld ? "$name.$tld" : $name;
        $label = '[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
        if (strlen($domain) > 253 || preg_match("/^$label(\\.$label)*$/", $domain) !== 1) {
            return null;
        }
        return $domain;
    }

    /**
     * Get the location of the nginx certificate bundle written by the plugin.
     *
     * @return string|null File path, or `null` if the server name is invalid.
     */
    function getCertificateFile() {
        $name = $this->getServerName();
        if ($name === null) {
            return null;
        }
        return "/boot/config/ssl/certs/{$name}_unraid_bundle.pem";
    }

    /**
     * Issue a certificate.
     *
     * @param string $dnsProvider Selected dns provider, see `isValidDnsProvider()`.
     * @param string $domain Domain to issue certificate for.
     * @param string $certFile Location of the resulting nginx certificate bundle file.
     * @param array $options Options for the dns provider, passed as environment variables.
     * @param bool $forceRenewal Set to `true` to force certificate renewal.
     *                           Renewal is also forced when switching between
     *                           the staging and production servers.
     * @param bool $useStaging Set to `true` to use staging/test acme server.
     *                         The staging certificate is not installed, see
     *                         `isStagingCertificate()`.
     * @param bool $verbose Set to `true` to enable verbose output. This
     *                     shows debug output for this run and enables debug
     *                     logging to syslog, which is persisted by `acme.sh`
     *                     and therefore also applies to the renewal cron job.
     *                     When `false`, only errors are logged to syslog.
     * @return integer Result code from `acme.sh`. `RENEW_SKIP` if the
     *                 certificate is still valid and was not renewed.
     */
    function issueCertificate($dnsProvider, $domain, $certFile, $options, $forceRenewal, $useStaging, $verbose) {
        // acme.sh evaluates the reload command with a shell.
        $reloadcmd = "/usr/share/ACME/scripts/reloadcmd " . escapeshellarg($certFile);
        $posthook = "/usr/share/ACME/scripts/post-hook";

        $args = array_merge($this->acmeshCommand(), array(
            "--issue",
            "--dns", $dnsProvider,
            "--domain", $domain,
            "--force-color",
            "--post-hook", $posthook,
        ));
        // acme.sh does not renew a certificate that isn't due, even if it
        // was issued by the other server.
        $switchingServer = $this->hasCertificate($domain) && $this->isStagingCertificate($domain) !== $useStaging;
        if ($forceRenewal || $switchingServer) {
            $args[] = "--force";
        }
        if ($useStaging) {
            // Staging certificates are for testing and not trusted by
            // browsers, so don't install them. acme.sh keeps a previously
            // saved reload command, also for renewals, so clear it.
            if (!$this->removeDomainConfValues($domain, array("Le_ReloadCmd"))) {
                $this->nchanWrite("\n", "--- Failed to clear the saved reload command", "\n");
                return -1;
            }
            $args[] = "--staging";
        } else {
            array_push($args, "--reloadcmd", $reloadcmd);
        }
        if ($switchingServer && !$forceRenewal) {
            $this->nchanWrite("\n", "--- Switching to the " . ($useStaging ? "staging" : "production") . " server, forcing certificate renewal", "\n");
        }
        if ($verbose) {
            // --debug only affects this run (output shown in the dialog).
            // --syslog is saved in account.conf, so it also applies to `acme.sh --cron`.
            array_push($args, "--debug", "2", "--syslog", strval(self::SYSLOG_LEVEL_DEBUG));
        } else {
            array_push($args, "--syslog", strval(self::SYSLOG_LEVEL_ERROR));
        }
        return $this->run($args, $verbose, $options, self::RENEW_SKIP);
    }

    /**
     * Check whether a certificate has been issued for the domain.
     *
     * @param string $domain Domain.
     * @return bool
     */
    function hasCertificate($domain) {
        return ($this->getDomainConf($domain)['Le_API'] ?? '') !== '';
    }

    /**
     * Check whether the domain's current `acme.sh` certificate was issued
     * by the staging server. Such a certificate is not installed, and
     * automatic renewals keep using the staging server, so the installed
     * certificate is no longer renewed.
     *
     * @param string $domain Domain.
     * @return bool
     */
    function isStagingCertificate($domain) {
        return ($this->getDomainConf($domain)['Le_API'] ?? '') === self::STAGING_CA;
    }

    /**
     * Work out which DNS provider options to pass to `acme.sh`, and which
     * saved options to clear, from the submitted form values.
     *
     * - A secret submitted as `SECRET_PLACEHOLDER` keeps its saved value.
     * - An empty value clears the saved value, if any. `acme.sh` will not do
     *   this itself, as the dnsapi scripts fall back to the saved value
     *   when a variable is empty.
     * - Any other value is passed on, and saved by `acme.sh`.
     *
     * @param string $domain Domain the certificate is issued for.
     * @param array $submitted Submitted option names and values.
     * @param string[] $allowedNames Option names the DNS provider declares,
     *                               see `getDnsProviderOptionNames()`. Any
     *                               other name is rejected, so the form
     *                               can't set e.g. `PATH` or `LD_PRELOAD`.
     * @return array `['options' => [name => value], 'clear' => [name], 'changed' => bool]`,
     *               where `changed` is `true` if any option differs from
     *               its saved value.
     * @throws InvalidArgumentException If an option name or value is invalid.
     */
    function prepareOptions($domain, $submitted, $allowedNames) {
        $saved = $this->getSavedOptions($domain);
        $options = array();
        $clear = array();
        $changed = false;
        foreach ($submitted as $name => $value) {
            if (!self::isValidOptionName($name)) {
                throw new InvalidArgumentException("Invalid option name");
            }
            if (!in_array($name, $allowedNames, true)) {
                throw new InvalidArgumentException("Unknown option $name for the selected DNS provider");
            }
            if (!is_string($value)) {
                throw new InvalidArgumentException("Invalid value for $name");
            }
            if (self::isSecretOption($name) && $value === self::SECRET_PLACEHOLDER) {
                if (isset($saved[$name])) {
                    $options[$name] = $saved[$name];
                }
            } elseif ($value === '') {
                if (isset($saved[$name])) {
                    $clear[] = $name;
                }
            } else {
                $options[$name] = $value;
                if (($saved[$name] ?? null) !== $value) {
                    $changed = true;
                }
            }
        }
        return array('options' => $options, 'clear' => $clear, 'changed' => $changed || count($clear) > 0);
    }

    /**
     * Check whether verbose (debug) logging to syslog is enabled.
     *
     * @return bool `true` if the saved `acme.sh` syslog level is debug or higher.
     */
    function isVerboseLoggingEnabled() {
        $conf = $this->getAccountConf();
        return intval($conf["SYS_LOG"] ?? 0) >= self::SYSLOG_LEVEL_DEBUG;
    }

    /**
     * Get the email address used for ZeroSSL account registration.
     *
     * @return string Email address or an empty string if no ZeroSSL account has been registered.
     */
    function getZeroSSLAccountEmail() {
        $account_path = $this->acmeshConfigHome . "/ca/acme.zerossl.com/v2/DV90/account.json";
        // Doesn't exist until an account has been registered.
        $json_string = @file_get_contents($account_path);
        if ($json_string === false) {
            return "";
        }
        $contact = json_decode($json_string, true)["contact"][0] ?? null;
        return is_string($contact) ? str_replace("mailto:", "", $contact) : "";
    }

    /**
     * Create the ZeroSSL account.
     *
     * @param string $email Account email.
     */
    function zeroSSLRegisterAccount($email) {
        $args = array_merge($this->acmeshCommand(), array("--register-account", "-m", $email));
        return $this->run($args) == 0;
    }

    /**
     * Update the ZeroSSL account.
     *
     * @param string $email New account email.
     */
    function zeroSSLUpdateAccount($email) {
        $args = array_merge($this->acmeshCommand(), array("--update-account", "-m", $email));
        return $this->run($args) == 0;
    }

    /**
     * Get the saved dns provider options, including secrets.
     *
     * `acme.sh` saves options either in `account.conf` (as `SAVED_<name>`)
     * or, for some providers, in the domain's config (as `<name>`). The
     * domain config takes precedence, as when `acme.sh` renews.
     *
     * Never send the result to the browser, use `getSavedEnvironment()`.
     *
     * @param string $domain Domain the certificate is issued for.
     * @return array Option names and values.
     */
    function getSavedOptions($domain) {
        $options = array();
        foreach ($this->getAccountConf() as $key => $value) {
            if (str_starts_with($key, "SAVED_")) {
                $name = substr($key, 6);
                if (self::isValidOptionName($name)) {
                    $options[$name] = $value;
                }
            }
        }
        foreach ($this->getDomainConf($domain) as $name => $value) {
            if ($name !== 'DOMAIN_CONF' && self::isValidOptionName($name)) {
                $options[$name] = $value;
            }
        }
        return $options;
    }

    /**
     * Get the saved dns provider options, safe for sending to the browser:
     * secrets are replaced by `SECRET_PLACEHOLDER`.
     *
     * @param string $domain Domain the certificate is issued for.
     * @return array Option names and values.
     */
    function getSavedEnvironment($domain) {
        $options = $this->getSavedOptions($domain);
        foreach ($options as $name => $value) {
            if (self::isSecretOption($name) && $value !== '') {
                $options[$name] = self::SECRET_PLACEHOLDER;
            }
        }
        return $options;
    }

    /**
     * Remove saved dns provider options from `account.conf` and the
     * domain's config, so `acme.sh` no longer uses them.
     *
     * Each file is rewritten once, for all options, so a failure doesn't
     * leave some of the options in a file cleared.
     *
     * @param string[] $names Option names.
     * @param string $domain Domain the certificate is issued for.
     * @return bool `true` on success.
     */
    function clearSavedOptions($names, $domain) {
        $accountKeys = array();
        foreach ($names as $name) {
            if (!self::isValidOptionName($name)) {
                return false;
            }
            // Same as acme.sh's _clearaccountconf_mutable: both the current
            // SAVED_<name> and the legacy <name> form.
            array_push($accountKeys, "SAVED_$name", $name);
        }
        $ok = $this->removeConfLines($this->acmeshConfigHome . "/account.conf", $accountKeys);
        $ok = $ok && $this->removeDomainConfValues($domain, $names);
        $this->accountConf = null;
        return $ok;
    }

    /**
     * Remove values from the domain's `acme.sh` config.
     *
     * @param string $domain Domain.
     * @param string[] $keys Keys, e.g. `Le_ReloadCmd`.
     * @return bool `true` on success, or if there are no such values.
     */
    private function removeDomainConfValues($domain, $keys) {
        $ok = true;
        $domainConfPath = $this->getDomainConf($domain)['DOMAIN_CONF'] ?? null;
        if ($domainConfPath) {
            $ok = $this->removeConfLines($domainConfPath, $keys);
        }
        $this->domainConf = array();
        return $ok;
    }

    /**
     * Remove `<key>=...` lines from an `acme.sh` config file.
     *
     * @param string $path Config file.
     * @param string[] $keys Keys to remove, matched exactly.
     * @return bool `true` on success, or if the file does not exist.
     */
    private function removeConfLines($path, $keys) {
        if (!is_file($path)) {
            return true;
        }
        $lines = file($path);
        if ($lines === false) {
            return false;
        }
        $kept = array_filter($lines, function ($line) use ($keys) {
            foreach ($keys as $key) {
                if (preg_match('/^' . preg_quote($key, '/') . ' *=/', $line) === 1) {
                    return false;
                }
            }
            return true;
        });
        if (count($kept) == count($lines)) {
            return true;
        }
        $tmp = "$path.tmp";
        if (file_put_contents($tmp, implode('', $kept)) === false) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, 0600);
        return rename($tmp, $path);
    }

    /**
     * Get the global `acme.sh` configuration (account.conf).
     *
     * @return array Key/value pairs from `acme.sh --info`.
     */
    function getAccountConf() {
        if ($this->accountConf === null) {
            $this->accountConf = array();
            $args = array_merge($this->acmeshCommand(), array("--info"));
            foreach ($this->runQuiet($args) as $string) {
                $s = explode("=", $string, 2);
                if (sizeof($s) == 2) {
                    $this->accountConf[$s[0]] = self::decodeConfValue(trim($s[1], "'"));
                }
            }
        }
        return $this->accountConf;
    }

    /**
     * Get the `acme.sh` configuration for a domain.
     *
     * @param string $domain Domain.
     * @return array Key/value pairs from `acme.sh --info --domain`, including
     *               `DOMAIN_CONF`, the location of the config file. Empty if
     *               `$domain` is empty.
     */
    function getDomainConf($domain) {
        if (!$domain) {
            return array();
        }
        if (!isset($this->domainConf[$domain])) {
            $conf = array();
            $args = array_merge($this->acmeshCommand(), array("--info", "--domain", $domain));
            foreach ($this->runQuiet($args) as $string) {
                $s = explode("=", $string, 2);
                if (sizeof($s) == 2) {
                    // acme.sh has already decoded the values.
                    $conf[$s[0]] = $s[1];
                }
            }
            $this->domainConf[$domain] = $conf;
        }
        return $this->domainConf[$domain];
    }

    /**
     * Decode a base64 encoded `acme.sh` config value.
     *
     * @param string $value Value as stored in the config file.
     * @return string Decoded value.
     */
    private static function decodeConfValue($value) {
        if (str_starts_with($value, self::B64CONF_START) && str_ends_with($value, self::B64CONF_END)) {
            $encoded = substr($value, strlen(self::B64CONF_START), -strlen(self::B64CONF_END));
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false) {
                return $decoded;
            }
        }
        return $value;
    }

    /**
     * Get the currently used dns provider.
     *
     * @return DNS provider id or an empty string if no certificate has yet been issued.
     */
    function getDnsApi($domain) {
        return $this->getDomainConf($domain)['Le_Webroot'] ?? "";
    }

    /**
     * Returns the command for invoking `acme.sh`, as an argument list.
     */
    function acmeshCommand() {
        return array(
            $this->acmeshHome . "/acme.sh",
            "--config-home", $this->acmeshConfigHome,
            "--home", $this->acmeshHome,
        );
    }

    /**
     * Write messages to the plugin's nchan channel.
     *
     * @param string ...$messages Messages to write.
     */
    function nchanWrite(...$messages){
        $com = curl_init();
        curl_setopt_array($com,[
            CURLOPT_URL => 'http://localhost/pub/acmesh?buffer_length=1',
            CURLOPT_UNIX_SOCKET_PATH => '/var/run/nginx.socket',
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => true
        ]);
        foreach ($messages as $message) {
            curl_setopt($com, CURLOPT_POSTFIELDS, $message);
            curl_exec($com);
        }
        curl_close($com);
    }

    /**
     * Build the environment for a child process.
     *
     * @param array $env Additional environment variables.
     * @return array Environment.
     */
    private static function childEnvironment($env) {
        $environment = array_merge(getenv(), $env);
        if (empty($environment['PATH'])) {
            $environment['PATH'] = self::DEFAULT_PATH;
        }
        return $environment;
    }

    /**
     * Execute a command without a shell and return its standard output.
     *
     * @param string[] $args Command and arguments.
     * @return string[] Output lines.
     */
    private function runQuiet($args) {
        $process = proc_open($args, array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, null, self::childEnvironment(array()));
        if (!is_resource($process)) {
            return array();
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        return explode("\n", rtrim($output, "\n"));
    }

    /**
     * Execute a command without a shell and send output to the plugin's
     * nchan channel.
     *
     * @param string[] $args Command and arguments.
     * @param bool $verbose Set to `true` to show the executed command. Values
     *                      in `$env` are never shown.
     * @param array $env Additional environment variables, e.g. secrets.
     * @param integer|null $skipCode Exit code meaning there was nothing to
     *                               do, reported as skipped rather than failed.
     * @return integer Exit code.
     */
    function run($args, $verbose = false, $env = array(), $skipCode = null) {
        $this->nchanWrite("\n");
        if ($verbose) {
            $shown = array();
            foreach (array_keys($env) as $name) {
                $shown[] = "$name=<hidden>";
            }
            foreach ($args as $arg) {
                $shown[] = escapeshellarg($arg);
            }
            $this->nchanWrite("--- Executing command: " . join(" ", $shown), "\n");
        } else {
            $this->nchanWrite("--- Executing command", "\n");
        }
        $process = proc_open($args, array(1 => array('pipe', 'w'), 2 => array('redirect', 1)), $pipes, null, self::childEnvironment($env));
        if (!is_resource($process)) {
            $this->nchanWrite("--- Failed to start command", "\n");
            return -1;
        }
        while (($line = fgets($pipes[1])) !== false) $this->nchanWrite($line);
        fclose($pipes[1]);
        $retval = proc_close($process);
        if ($retval == 0) {
            $this->nchanWrite("--- Successfully finished execution", "\n");
        } elseif ($retval === $skipCode) {
            $this->nchanWrite("--- Finished execution, nothing to do", "\n");
        } else {
            $this->nchanWrite("--- Failed execution with status code $retval", "\n");
        }
        return $retval;
    }
}
?>
