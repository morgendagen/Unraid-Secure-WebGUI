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
     * @param array $options Options for the dns provider.
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
        $escapedOptions = array();
        $redactedOptions = array();
        foreach ($options as $key => $value) {
            $escaped_value = escapeshellarg($value);
            array_push($escapedOptions, "$key=$escaped_value");
            array_push($redactedOptions, "$key=<hidden>");
        }

        $optionsString = join(" ", $escapedOptions);
        $reloadcmd = "/usr/share/ACME/scripts/reloadcmd $certFile";
        $posthook = "/usr/share/ACME/scripts/post-hook";

        $acmeshArgs = $this->acmeshCommand() . " --issue --dns $dnsProvider --domain $domain --force-color --reloadcmd \"$reloadcmd\" --post-hook \"$posthook\"";
        if ($forceRenewal) {
            $acmeshArgs .= " --force";
        }
        if ($useStaging) {
            $acmeshArgs .= " --staging";
        }
        if ($verbose) {
            // --debug only affects this run (output shown in the dialog).
            // --syslog is saved in account.conf, so it also applies to `acme.sh --cron`.
            $acmeshArgs .= " --debug 2 --syslog " . self::SYSLOG_LEVEL_DEBUG;
        } else {
            $acmeshArgs .= " --syslog " . self::SYSLOG_LEVEL_ERROR;
        }
        $cmd = "$optionsString $acmeshArgs";
        $displayCmd = join(" ", $redactedOptions) . " $acmeshArgs";
        return $this->run($cmd, $verbose, $displayCmd);
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
        $retval = null;
        $email = escapeshellarg($email);
        $cmd = $this->acmeshCommand() . " --register-account -m $email";
        $retval = $this->run($cmd);
        if ($retval == 0) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Update the ZeroSSL account.
     * 
     * @param string $email New account email.
     */
    function zeroSSLUpdateAccount($email) {
        $retval = null;
        $email = escapeshellarg($email);
        $cmd = $this->acmeshCommand() . " --update-account -m $email";
        $retval = $this->run($cmd);
        if ($retval == 0) {
            return true;
        } else {
            return false;
        }
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
            $cmd = escapeshellcmd($this->acmeshCommand() . " --info");
            exec($cmd, $output);
            $this->accountConf = array();
            foreach ($output as $string) {
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
        $domain = escapeshellarg($domain);
        $cmd = escapeshellcmd($this->acmeshCommand() . " --info --domain " . $domain);
        exec($cmd, $output);
        foreach($output as $index => $string) {
            if (str_starts_with($string, "Le_Webroot=")) {
                return substr($string, 11);
            }
        }
        return "";
    }

    /**
     * Returns command string for invoking the `acme.sh`.
     */
    function acmeshCommand() {
        return $this->acmeshHome . "/acme.sh --config-home " . $this->acmeshConfigHome . " --home " . $this->acmeshHome;
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
     * Execute a command and send output to the plugin's nchan channel.
     * 
     * @param string $command Command to execute.
     * @param bool $verbose Set to `true` to show the executed command.
     * @param string|null $displayCommand Command to show instead of `$command`,
     *                                    e.g. with secrets redacted.
     */
    function run($command, $verbose = false, $displayCommand = null) {
        $this->nchanWrite("\n");
        $command = escapeshellcmd($command) . " 2>&1";
        if ($verbose) {
            $shown = $displayCommand ?? $command;
            $this->nchanWrite("--- Executing command: $shown", "\n");
        } else {
            $this->nchanWrite("--- Executing command", "\n");
        }
        $run = popen($command,'r');
        while (($line = fgets($run)) !== false) $this->nchanWrite($line);
        $retval = pclose($run);
        if ($retval == 0) {
            $this->nchanWrite("--- Successfully finished execution", "\n");
        } else {
            $this->nchanWrite("--- Failed execution with status code $retval", "\n");
        }
        return $retval;
    }
}
?>
