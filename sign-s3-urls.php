<?php
/*
Plugin Name: sign-s3-urls
Description: This plugin signs all S3 URLs
Author: Panu Ervamaa
*/

# Output buffer capture & replace is required for article inline images
add_action('admin_head', 'sign_s3_buf_start');
add_action('admin_footer', 'sign_s3_buf_end');
add_action('wp_head', 'sign_s3_buf_start');
add_action('wp_footer', 'sign_s3_buf_end');
function sign_s3_buf_start() { ob_start("sign_s3_buf_cb"); }
function sign_s3_buf_end() { ob_end_flush(); }
function sign_s3_buf_cb($buffer) { return sign_s3_replace($buffer); }

add_filter('wp_prepare_attachment_for_js','prepare_url_with_signature', 50);

function prepare_url_with_signature($response) {
    if ( isset( $response['url'] ) ) {
        $response['url'] = sign_s3_replace( $response['url'] );
    }
    if ( isset( $response['sizes'] ) && is_array( $response['sizes'] ) ) {
        foreach ( $response['sizes'] as $key => $value ) {
            $response['sizes'][ $key ]['url'] = sign_s3_replace( $value['url'] );
        }
    }
    return $response;
}

function sign_s3_replace($content) {
    $ret = preg_replace_callback(
        '(("|\'|^)(https?:)?//([\w-]+).s3(.*?).amazonaws.com(/.+?)(\?.*?)?("|\'|$))',
        function($m) { return $m[1].sign_s3_url($m[2],$m[3],$m[4],$m[5]).$m[7]; },
        $content
    );
    if ($content === $ret) {
        $ret = preg_replace_callback(
            '(("|\'|^)(https?:)?//s3(.*?).amazonaws.com/([\w-]+)/(.+?)(\?.*?)?("|\'|$))',
            function($m) { return $m[1].sign_s3_url_path($m[2],$m[3],$m[4],$m[5]).$m[7]; },
            $content
        );
    }
    return $ret;
}

function sign_s3_url($schema,$endpoint,$bucketName,$objectName) {
    $schema = 'https';
    $keyId = getenv('AWS_ACCESS_KEY_ID');
    $secretKey = getenv('AWS_SECRET_ACCESS_KEY');
    $S3_URL = "$schema://$bucketName.s3$endpoint.amazonaws.com";
    $expires = time() + getenv('S3_SIGNED_URL_EXPIRY');
    $objectName = url_normalize($objectName);

    $stringToSign = "GET\n\n\n$expires\n/$bucketName$objectName";
    $sig = urlencode(hex2b64(hash_hmac("sha1",$stringToSign,$secretKey)));

    return "$S3_URL$objectName?AWSAccessKeyId=$keyId&Expires=$expires&Signature=$sig";
}

function sign_s3_url_path($schema,$bucketName,$endpoint,$objectName) {
    $schema = 'https';
    $keyId = getenv('AWS_ACCESS_KEY_ID');
    $secretKey = getenv('AWS_SECRET_ACCESS_KEY');
    $S3_URL = "$schema://s3$endpoint.amazonaws.com/$bucketName/";
    $expires = time() + getenv('S3_SIGNED_URL_EXPIRY');
    $objectName = url_normalize($objectName);

    $stringToSign = "GET\n\n\n$expires\n/$bucketName$objectName";
    $sig = urlencode(hex2b64(hash_hmac("sha1",$stringToSign,$secretKey)));

    return "$S3_URL$objectName?AWSAccessKeyId=$keyId&Expires=$expires&Signature=$sig";
}

function url_normalize($url) {
    if (strpos($url, '%') !== false) return $url;
    $url = explode('/', $url);
    foreach ($url as $key => $val) $url[$key] = urlencode($val);
    return str_replace('%3A', ':', join('/', $url));
}

function hex2b64($str) {
    $raw = "";
    for ($i=0; $i < strlen($str); $i+=2) {
        $raw .= chr(hexdec(substr($str, $i, 2)));
    }
    return base64_encode($raw);
}
?>
