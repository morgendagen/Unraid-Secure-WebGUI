<?php

class ACME {
    public $plugin;
    public $emhttp;
    public $acmeshHome;
    public $acmeshConfigHome;

    function __construct() {
        $this->plugin = '/boot/config/plugins/ACME';
        $this->emhttp = '/usr/local/emhttp/plugins/ACME'; 
        $this->acmeshHome = '/usr/share/ACME/acme.sh';
        $this->acmeshConfigHome = $this->plugin;
    }

    function issueCertificate($dnsProvider, $domain, $certFile, $options, $forceRenewal, $useStaging) {
        $optionsString = join(" ", $options);
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

    function mustRegister() {
        return $this->getZeroSSLAccountEmail() == "";
    }

    function zeroSSLRegisterAccount($email) {
        $output = null;
        $retval = null;
        $cmd = $this->acmeshCommand() . " --register-account -m $email";
        $retval = $this->run($cmd);
        if ($retval == 0) {
            return true;
        } else {
            return false;
        }
    }

    function zeroSSLUpdateAccount($email) {
        $output = null;
        $retval = null;
        $cmd = $this->acmeshCommand() . " --update-account -m $email";
        $retval = $this->run($cmd);
        if ($retval == 0) {
            return true;
        } else {
            return false;
        }
    }

    function getSavedEnvironment() {
        $cmd = $this->acmeshCommand() . " --info";
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

    function getDomains() {
        $cmd = $this->acmeshCommand() . " --list --listraw";
        exec($cmd, $output);
    }

    function getDnsApi($domain) {
        $cmd = $this->acmeshCommand() . " --info --domain " . $domain;
        exec($cmd, $output);
        foreach($output as $index => $string) {
            if (str_starts_with($string, "Le_Webroot=")) {
                return substr($string, 11);
            }
        }
        return "";
    }

    function acmeshCommand() {
        return $this->acmeshHome . "/acme.sh --config-home " . $this->acmeshConfigHome . " --home " . $this->acmeshHome;
    }

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
    
    function run($command, $verbose = false) {
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
