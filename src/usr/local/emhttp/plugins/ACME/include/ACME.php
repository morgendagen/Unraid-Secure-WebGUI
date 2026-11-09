<?php

class ACME {
    /** The plugin's config directory. */
    public $plugin;
    /** The plugin's emhttp directory. */
    public $emhttp;
    /** The install location of `acme.sh`. */
    public $acmeshHome;
    /** The `acme.sh` config home directory. */
    public $acmeshConfigHome;
    /** Cached output of `acme.sh --info`. */
    private $accountConf = null;

    /** `acme.sh` syslog levels, see `acme.sh --help`. */
    const SYSLOG_LEVEL_ERROR = 3;
    const SYSLOG_LEVEL_DEBUG = 7;

    /** Fallback PATH if the web server runs PHP with an empty environment. */
    const DEFAULT_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

    function __construct() {
        $this->plugin = '/boot/config/plugins/ACME';
        $this->emhttp = '/usr/local/emhttp/plugins/ACME'; 
        $this->acmeshHome = '/usr/share/ACME/acme.sh';
        $this->acmeshConfigHome = $this->plugin;
    }

    /**
     * Issue a certificate.
     * 
     * @param string $dnsProvider Selected dns provider.
     * @param string $domain Domain to issue certificate for.
     * @param string $certFile Location of the resulting nginx certificate bundle file.
     * @param array $options Options for the dns provider, passed as environment variables.
     * @param bool $forceRenewal Set to `true` to force certificate renewal.
     * @param bool $useStaging Set to `true` to use staging/test acme server.
     * @param bool $verbose Set to `true` to enable verbose output. This
     *                     shows debug output for this run and enables debug
     *                     logging to syslog, which is persisted by `acme.sh`
     *                     and therefore also applies to the renewal cron job.
     *                     When `false`, only errors are logged to syslog.
     * @return integer Result code from `acme.sh`.
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
            "--reloadcmd", $reloadcmd,
            "--post-hook", $posthook,
        ));
        if ($forceRenewal) {
            $args[] = "--force";
        }
        if ($useStaging) {
            $args[] = "--staging";
        }
        if ($verbose) {
            // --debug only affects this run (output shown in the dialog).
            // --syslog is saved in account.conf, so it also applies to `acme.sh --cron`.
            array_push($args, "--debug", "2", "--syslog", strval(self::SYSLOG_LEVEL_DEBUG));
        } else {
            array_push($args, "--syslog", strval(self::SYSLOG_LEVEL_ERROR));
        }
        return $this->run($args, $verbose, $options);
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
        $json_string = file_get_contents($account_path);
        if ($json_string == false) {
            return "";
        } else {
            $json = json_decode($json_string, true);
            if ($json) {
                $contact = $json["contact"][0];
                return str_replace("mailto:", "", $contact);
            }
        }
        return "";
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
     * Get the saved dns provider options.
     * 
     * @return array The saved dns provider options.
     */
    function getSavedEnvironment() {
        $a = array();
        foreach ($this->getAccountConf() as $key => $value) {
            if (str_starts_with($key, "SAVED_")) {
                $a[substr($key, 6)] = $value;
            }
        }
        return $a;
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
                    $this->accountConf[$s[0]] = trim($s[1], "'");
                }
            }
        }
        return $this->accountConf;
    }

    /**
     * Get the currently used dns provider.
     * 
     * @return DNS provider id or an empty string if no certificate has yet been issued.
     */
    function getDnsApi($domain) {
        $args = array_merge($this->acmeshCommand(), array("--info", "--domain", $domain));
        foreach ($this->runQuiet($args) as $string) {
            if (str_starts_with($string, "Le_Webroot=")) {
                return substr($string, 11);
            }
        }
        return "";
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
     * @return integer Exit code.
     */
    function run($args, $verbose = false, $env = array()) {
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
        } else {
            $this->nchanWrite("--- Failed execution with status code $retval", "\n");
        }
        return $retval;
    }
}
?>
