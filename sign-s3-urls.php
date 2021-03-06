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
add_action('wp_footer', 'sign_s3_buf_end', 1001); // after admin bar
function sign_s3_buf_start() { ob_start("sign_s3_buf_cb"); }
function sign_s3_buf_end() { if (ob_get_contents()) { ob_end_flush(); } }
function sign_s3_buf_cb($buffer) { return sign_s3_replace($buffer); }

add_filter('wp_prepare_attachment_for_js','prepare_url_with_signature', 100);
add_filter('media_send_to_editor','prepare_image_with_signature', 50);
add_filter('admin_post_thumbnail_html','prepare_image_with_signature', 50);
add_filter('wp_get_attachment_image_src','prepare_attachment_src_with_signature', 100, 4);

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

function prepare_image_with_signature($response) {
    $response = sign_s3_replace( $response );

    return $response;
}

function prepare_attachment_src_with_signature($image, $attachment_id, $size, $icon) {
    // thumbnail preview after async upload
    if (isset($_REQUEST['fetch'])) {
        $image = sign_s3_replace($image);
    }

    return $image;
}

function sign_s3_replace($content) {
    $urls = getenv('S3_SIGNED_URL_LIST');
    global $as3cf;

    if ($urls && $as3cf instanceof Amazon_S3_And_CloudFront) {

        $urls = explode(',', $urls);
        $bucket = $as3cf->get_setting('bucket');
        foreach ($urls as $url) {
            $url = trim($url);
            $parse = parse_url($url);
            $domain = $parse['host'];

            $content = preg_replace_callback(
                '(("|\'|^)(https?:)?//'.$domain.'(/.+?)(\?.*?)?("|\'|$))',
                function ($m) use ($url, $bucket) {
                    return $m[1] . sign_custom_url($url, $bucket, $m[3]) . $m[5];
                },
                $content
            );
        }
    }

    $dRet = preg_replace_callback(
        '(("|\'|^)(https?:)?//([\w-]+).s3(.*?).amazonaws.com(/.+?)(\?.*?)?("|\'|$))',
        function($m) { return $m[1].sign_s3_url($m[2],$m[3],$m[4],$m[5]).$m[7]; },
        $content
    );
    $pRet = preg_replace_callback(
        '(("|\'|^)(https?:)?//s3(.*?).amazonaws.com/([\w-]+)/(.+?)(\?.*?)?("|\'|$))',
        function($m) { return $m[1].sign_s3_url_path($m[2],$m[3],$m[4],$m[5]).$m[7]; },
        $dRet
    );
    return $pRet;
}

function sign_custom_url($url,$bucketName,$objectName) {
    $keyId = getenv('AWS_ACCESS_KEY_ID');
    $secretKey = getenv('AWS_SECRET_ACCESS_KEY');
    $expires = time() + getenv('S3_SIGNED_URL_EXPIRY');
    $objectName = url_normalize($objectName);

    $stringToSign = "GET\n\n\n$expires\n/$bucketName$objectName";
    $sig = urlencode(hex2b64(hash_hmac("sha1",$stringToSign,$secretKey)));

    return "$url$objectName?AWSAccessKeyId=$keyId&Expires=$expires&Signature=$sig";
}

function sign_s3_url($schema,$bucketName,$endpoint,$objectName) {
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

function sign_s3_url_path($schema,$endpoint,$bucketName,$objectName) {
    $schema = 'https';
    $keyId = getenv('AWS_ACCESS_KEY_ID');
    $secretKey = getenv('AWS_SECRET_ACCESS_KEY');
    $S3_URL = "$schema://s3$endpoint.amazonaws.com/$bucketName/";
    $expires = time() + getenv('S3_SIGNED_URL_EXPIRY');
    $objectName = url_normalize($objectName);

    $stringToSign = "GET\n\n\n$expires\n/$bucketName/$objectName";
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
