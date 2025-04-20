<?PHP
require("/usr/local/emhttp/plugins/ACME/include/ACME.php");

$acme = new ACME();

$response_code = 200;
$response = array();
$function = $_POST["function"];
$email = $_POST['email'];

if (!isset($function)) {
    $response_code = 400;
    $response['status'] = 'Missing function';
} elseif (!isset($email)) {
    $response_code = 400;
    $response['status'] = 'Missing email address';
} else {
    // to keep nchan happy and not miss messages
    usleep(250*1000);
    $success = false;
    switch ($function) {
        case "register":
            $success = $acme->zeroSSLRegisterAccount($email);
            break;
        case "update":
            $success = $acme->zeroSSLUpdateAccount($email);
            break;
    }
    // to keep nchan happy and not miss messages
    usleep(250*1000);
    if ($success) {
        $response['status'] = 'OK';
    } else {
        $response_code = 500;
        $response['status'] = "Failed";
    }
}

http_response_code($response_code);
echo json_encode($response);
?>
