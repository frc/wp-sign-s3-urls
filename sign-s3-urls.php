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

add_filter('wp_get_attachment_url','sign_s3_replace');
add_filter('image_downsize','image_downsize_signed',0,3);

# adopted from wp-includes/media.php:image_downsize()
function image_downsize_signed($what, $id, $size = 'medium') {
    if ( !wp_attachment_is_image($id) ) return false;
    $img_url = wp_get_attachment_url($id);
    $meta = wp_get_attachment_metadata($id);
    $width = $height = 0;
    $is_intermediate = false;
    $img_url_basename = wp_basename($img_url);
    if ( $intermediate = image_get_intermediate_size($id, $size) ) {
        $img_url = str_replace($img_url_basename, $intermediate['file'], $img_url);
        $width = $intermediate['width'];
        $height = $intermediate['height'];
        $is_intermediate = true;
    }
    elseif ( $size == 'thumbnail' ) {
        if ( ($thumb_file = wp_get_attachment_thumb_file($id)) && $info = getimagesize($thumb_file) ) {
            $img_url = str_replace($img_url_basename, wp_basename($thumb_file), $img_url);
            $width = $info[0];
            $height = $info[1];
            $is_intermediate = true;
        }
    }
    if ( !$width && !$height && isset( $meta['width'], $meta['height'] ) ) {
        $width = $meta['width'];
        $height = $meta['height'];
    }
    if ( $img_url) {
        list( $width, $height ) = image_constrain_size_for_editor( $width, $height, $size );
        $img_url = sign_s3_replace($img_url);
        return array( $img_url, $width, $height, $is_intermediate );
    }
    return false;
}

function sign_s3_replace($content) {
    $ret = preg_replace_callback(
        '(("|\'|^)(https?:)?//([\w-]+).s3(.*?).amazonaws.com(/.+?)(\?.*?)?("|\'|$))',
        function($m) { return $m[1].sign_s3_url($m[2],$m[3],$m[4],$m[5]).$m[7]; },
        $content
    );
    return $ret;
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
