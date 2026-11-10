<?PHP
/**
 * The DNS providers described in `dnsapi_info.txt`, as JSON, see
 * `DnsApiInfo::parse()`.
 *
 * The browser revalidates on each page load, and gets "304 Not Modified"
 * until the plugin is updated.
 */
require("/usr/local/emhttp/plugins/ACME/include/ACME.php");

$acme = new ACME();

header('Content-Type: application/json');
header('Cache-Control: private, no-cache');

$mtime = @filemtime($acme->getDnsProvidersFile());
if ($mtime !== false) {
    $etag = '"' . $mtime . '"';
    header("ETag: $etag");
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
}

$providers = $acme->getDnsProviders();
if ($providers === null) {
    http_response_code(500);
    echo json_encode(array('status' => 'Failed to read DNS provider info'));
} else {
    echo json_encode($providers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
?>
