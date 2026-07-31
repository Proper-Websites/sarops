<?php
// SAR OPS — Authorize.net "Accept Hosted" checkout.
// Takes the donation amount from POST, asks Authorize.net for a hosted payment
// page token, and forwards the donor to their secure payment page. Card data
// never touches this server. Credentials live in authnet-credentials.php,
// which is gitignored and uploaded to the server by hand.

function fail($code, $msg) {
  http_response_code($code);
  echo '<!doctype html><title>Donation | SAR OPS</title><body style="background:#1E2D3D;color:#fff;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;text-align:center;padding:24px">'
     . '<div><p style="font-size:1.1rem;max-width:460px;line-height:1.6">' . htmlspecialchars($msg) . '</p>'
     . '<p style="margin-top:24px"><a href="/donate" style="color:#DA483A;text-decoration:underline">Back to the donate page</a></p></div></body>';
  exit;
}

// Prefer the credentials file one level ABOVE the web root (unreachable by any
// URL); fall back to alongside this script.
$credsFile = file_exists(dirname(__DIR__) . '/authnet-credentials.php')
  ? dirname(__DIR__) . '/authnet-credentials.php'
  : __DIR__ . '/authnet-credentials.php';
if (!file_exists($credsFile)) {
  fail(500, 'The payment system is not configured yet. Please try again later.');
}
require $credsFile;

$amount = isset($_POST['amount']) ? filter_var($_POST['amount'], FILTER_VALIDATE_FLOAT) : false;
if ($amount === false || $amount < 1 || $amount > 100000) {
  fail(400, 'That donation amount did not come through correctly. Please go back and try again.');
}
$amount = number_format($amount, 2, '.', '');

$sandbox = defined('AUTHNET_ENV') && AUTHNET_ENV === 'sandbox';
$apiUrl  = $sandbox ? 'https://apitest.authorize.net/xml/v1/request.api' : 'https://api.authorize.net/xml/v1/request.api';
$payUrl  = $sandbox ? 'https://test.authorize.net/payment/payment' : 'https://accept.authorize.net/payment/payment';
// Send the donor back to whichever domain they donated from (whitelist only —
// this value is embedded in the Authorize.net return links).
$allowedHosts = ['sarops.org', 'www.sarops.org', 'sarops.properwebsites.com'];
$reqHost = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
$site    = in_array($reqHost, $allowedHosts, true) ? 'https://' . $reqHost : 'https://sarops.org';

// Field order matters to Authorize.net's JSON API — keep it as-is.
$request = ['getHostedPaymentPageRequest' => [
  'merchantAuthentication' => [
    'name'           => AUTHNET_API_LOGIN_ID,
    'transactionKey' => AUTHNET_TRANSACTION_KEY,
  ],
  'transactionRequest' => [
    'transactionType' => 'authCaptureTransaction',
    'amount'          => $amount,
  ],
  'hostedPaymentSettings' => ['setting' => [
    ['settingName' => 'hostedPaymentButtonOptions',
     'settingValue' => json_encode(['text' => 'Donate'])],
    ['settingName' => 'hostedPaymentOrderOptions',
     'settingValue' => json_encode(['show' => true, 'merchantName' => 'SAR OPS'])],
    ['settingName' => 'hostedPaymentReturnOptions',
     'settingValue' => json_encode([
       'showReceipt' => true,
       'url' => $site . '/donate?thanks=1', 'urlText' => 'Back to SAR OPS',
       'cancelUrl' => $site . '/donate',    'cancelUrlText' => 'Cancel',
     ])],
    ['settingName' => 'hostedPaymentBillingAddressOptions',
     'settingValue' => json_encode(['show' => true, 'required' => false])],
  ]],
]];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => json_encode($request),
  CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT        => 20,
]);
$raw = curl_exec($ch);

$data  = $raw ? json_decode(ltrim($raw, "\xEF\xBB\xBF"), true) : null;
$token = isset($data['token']) ? $data['token'] : '';
$ok    = $token !== '' && isset($data['messages']['resultCode']) && $data['messages']['resultCode'] === 'Ok';
if (!$ok) {
  fail(502, 'We could not reach the payment gateway. Nothing was charged. Please try again in a moment.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Redirecting to secure payment | SAR OPS</title>
</head>
<body style="background:#1E2D3D;color:#fff;font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0">
<form id="payForm" method="post" action="<?php echo htmlspecialchars($payUrl); ?>">
  <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />
  <p style="letter-spacing:0.06em;text-transform:uppercase;font-size:0.85rem">Taking you to secure payment&hellip;</p>
  <noscript><button type="submit" style="font:inherit;padding:14px 28px;background:#C73A2B;color:#fff;border:none;cursor:pointer">Continue to payment</button></noscript>
</form>
<script>document.getElementById('payForm').submit();</script>
</body>
</html>
