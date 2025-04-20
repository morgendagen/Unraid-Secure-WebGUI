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

$options = array();
foreach ($_POST as $key => $value) {
    if ($key != "ACME_DNSAPI" && $key != "ACME_DOMAIN") {
        $escaped_value = escapeshellarg($value);
        array_push($options, "$key=$escaped_value");
    }
}

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
