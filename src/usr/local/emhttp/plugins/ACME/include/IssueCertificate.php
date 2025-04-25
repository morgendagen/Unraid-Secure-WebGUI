<?PHP
require("/usr/local/emhttp/plugins/ACME/include/ACME.php");

$acme = new ACME();

// to keep nchan happy and not miss messages
usleep(250*1000);

$response_code = 200;
$response = array();

$dnsProvider = $_POST["ACME_DNSAPI"];
$domain = $_POST["ACME_DOMAIN"];
$certFile = $_POST["ACME_CERT_FILE"];
$forceRenewal = $_POST["ACME_FORCE_RENEWAL"];
$useStaging = $_POST["ACME_USE_STAGING"];

function acme_options_filter($key) {
    return !str_starts_with($key, 'ACME_');
}
$options = array_filter($_POST, "acme_options_filter", ARRAY_FILTER_USE_KEY);

$retval = $acme->issueCertificate(
    dnsProvider: $dnsProvider,
    domain: $domain,
    certFile: $certFile,
    options: $options,
    forceRenewal: $forceRenewal,
    useStaging: $useStaging
);
if ($retval == 0) {
    $response['status'] = 'OK';
} else {
    $response['status'] = "Failed";
}

// to keep nchan happy and not miss messages
usleep(250*1000);

http_response_code($response_code);
echo json_encode($response);

?>
