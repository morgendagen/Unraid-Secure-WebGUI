<?PHP
require("/usr/local/emhttp/plugins/ACME/include/ACME.php");

$acme = new ACME();

// to keep nchan happy and not miss messages
usleep(250*1000);

$response_code = 200;
$response = array();

/**
 * Report an error in the dialog and as the response status.
 */
function acme_fail($message) {
    global $acme, $response, $response_code;
    $acme->nchanWrite("\n", "--- $message", "\n");
    $response_code = 400;
    $response['status'] = $message;
}

$dnsProvider = $_POST["ACME_DNSAPI"] ?? "";
// Derived server side, as they end up on the acme.sh command line.
$domain = $acme->getCertificateDomain();
$certFile = $acme->getCertificateFile();
$forceRenewal = !empty($_POST["ACME_FORCE_RENEWAL"]);
$verbose = !empty($_POST["ACME_VERBOSE"]);
$useStaging = !empty($_POST["ACME_USE_STAGING"]);

function acme_options_filter($key) {
    return !str_starts_with($key, 'ACME_');
}
$submitted = array_filter($_POST, "acme_options_filter", ARRAY_FILTER_USE_KEY);

if (!$acme->isValidDnsProvider($dnsProvider)) {
    acme_fail("Invalid DNS provider");
} elseif ($domain === null || $certFile === null) {
    acme_fail("Invalid server name or local TLD");
} else {
    try {
        $prepared = $acme->prepareOptions($domain, $submitted, $acme->getDnsProviderOptionNames($dnsProvider));
    } catch (InvalidArgumentException $e) {
        $prepared = null;
        acme_fail($e->getMessage());
    }
    if ($prepared !== null) {
        $clear = $prepared['clear'];
        $cleared = !$clear || $acme->clearSavedOptions($clear, $domain);
        if (!$cleared) {
            acme_fail("Failed to clear saved values of " . implode(", ", $clear));
        } elseif ($clear) {
            $acme->nchanWrite("--- Cleared saved values of " . implode(", ", $clear), "\n");
        }
        if ($cleared) {
            $retval = $acme->issueCertificate(
                dnsProvider: $dnsProvider,
                domain: $domain,
                certFile: $certFile,
                options: $prepared['options'],
                forceRenewal: $forceRenewal,
                useStaging: $useStaging,
                verbose: $verbose
            );
            if ($retval == 0) {
                $response['status'] = 'OK';
            } elseif ($retval == ACME::RENEW_SKIP) {
                $response['status'] = 'Skipped';
                $acme->nchanWrite("\n", "--- The certificate is still valid and was not renewed.", "\n");
                if ($prepared['changed'] || $dnsProvider !== $acme->getDnsApi($domain)) {
                    $acme->nchanWrite("--- The DNS provider settings were changed, but are only checked and saved when a certificate is issued. Check \"Force certificate renewal\" to issue one now.", "\n");
                }
            } else {
                $response['status'] = 'Failed';
            }
            if ($useStaging && ($retval == 0 || $retval == ACME::RENEW_SKIP)) {
                $acme->nchanWrite("\n",
                    "--- The staging certificate is for testing only, and has not been installed.", "\n",
                    "--- Automatic renewals now only renew the staging certificate, so the installed certificate is no longer renewed.", "\n",
                    "--- Issue a certificate without \"Use staging server\" to install a certificate and keep it renewed.", "\n");
            }
        }
    }
}

// to keep nchan happy and not miss messages
usleep(250*1000);

http_response_code($response_code);
echo json_encode($response);

?>
