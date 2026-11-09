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
        $prepared = $acme->prepareOptions($domain, $submitted);
    } catch (InvalidArgumentException $e) {
        $prepared = null;
        acme_fail($e->getMessage());
    }
    if ($prepared !== null) {
        $cleared = true;
        foreach ($prepared['clear'] as $name) {
            if ($acme->clearSavedOption($name, $domain)) {
                $acme->nchanWrite("--- Cleared saved value of $name", "\n");
            } else {
                $cleared = false;
                acme_fail("Failed to clear saved value of $name");
            }
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
            $response['status'] = $retval == 0 ? 'OK' : 'Failed';
        }
    }
}

// to keep nchan happy and not miss messages
usleep(250*1000);

http_response_code($response_code);
echo json_encode($response);

?>
