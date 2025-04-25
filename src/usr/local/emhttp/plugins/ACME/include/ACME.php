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
     * @return integer Result code from `acme.sh`.
     */
    function issueCertificate($dnsProvider, $domain, $certFile, $options, $forceRenewal, $useStaging) {
        $escapedOptions = array();
        foreach ($options as $key => $value) {
            $escaped_value = escapeshellarg($value);
            array_push($escapedOptions, "$key=$escaped_value");
        }

        $optionsString = join(" ", $escapedOptions);
        $reloadcmd = "/usr/share/ACME/scripts/reloadcmd $certFile";
        $posthook = "/usr/share/ACME/scripts/post-hook";

        $cmd = "$optionsString " . $this->acmeshCommand() . " --issue --dns $dnsProvider --domain $domain --force-color --reloadcmd \"$reloadcmd\" --post-hook \"$posthook\"";
        if ($forceRenewal) {
            $cmd = $cmd . " --force";
        }
        if ($useStaging) {
            $cmd = $cmd . " --staging";
        }
        return $this->run($cmd);
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
        $cmd = escapeshellcmd($this->acmeshCommand() . " --info");
        exec($cmd, $output);
        $a = array();
        foreach($output as $index => $string) {
            if (str_starts_with($string, "SAVED_")) {
                $string = substr($string, 6);
                $s = explode("=", $string, 2);
                if (sizeof($s) == 2) {
                    $key = $s[0];
                    $value = trim($s[1], "'");
                    $a[$key] = $value;
                }
            }
        }
        return $a;
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
     */
    function run($command, $verbose = false) {
        $command = escapeshellcmd($command);
        if ($verbose) {
            $this->nchanWrite("--- Executing $command", "");
        } else {
            $this->nchanWrite("--- Executing command", "");
        }
        $run = popen($command,'r');
        while (!feof($run)) $this->nchanWrite(fgets($run));
        $retval = pclose($run);
        if ($retval == 0) {
            $this->nchanWrite("--- Successfully finished execution");
        } else {
            $this->nchanWrite("--- Failed execution with status code $retval");
        }
        return $retval;
    }
}
?>
