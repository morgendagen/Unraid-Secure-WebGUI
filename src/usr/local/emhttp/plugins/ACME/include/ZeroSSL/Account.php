<?PHP
require("/usr/local/emhttp/plugins/ACME/include/ACME.php");

$acme = new ACME();

$response_code = 200;
$response = array();
$function = $_POST["function"] ?? null;
$email = $_POST['email'] ?? null;

if (!in_array($function, array("register", "update"), true)) {
    $response_code = 400;
    $response['status'] = 'Invalid function';
} elseif (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    $response_code = 400;
    $response['status'] = 'Invalid email address';
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
