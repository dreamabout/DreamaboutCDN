<?php

require "../autoload.php";

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

$root = "/var/www/ephemeral";

// force exceptions
set_error_handler(
    function ($severity, $message, $filename, $lineno) {
        throw new ErrorException($message, 0, $severity, $filename, $lineno);
    }
);

$src     = $_GET["src"];
$width   = (int) $_GET["w"];
$height  = (int) $_GET["h"];
$quality = (int) $_GET["q"];

$sha1 = sha1($src);
$dir1 = substr($sha1, 0, 2);
$dir2 = substr($sha1, 2, 2);
$file = "{$root}/{$dir1}/{$dir2}/{$sha1}";

try {

    // cope remote file to local filesystem, if needed
    if (!file_exists($file)) {
        if (!is_dir("{$root}/{$dir1}/{$dir2}")) {
            mkdir("{$root}/{$dir1}/{$dir2}", 0777, true);
        }

        //  copy and check for race conditions
        copy($src, $file);
        if (!file_exists($file)) {
            throw new Exception("Copy failed");
        }
    }

    $request = Request::createFromGlobals();

    // no resizing, just point nginx to proper file
    if ($width === 0 && $height === 0) {

        $response = new BinaryFileResponse($file);
        $response::trustXSendfileTypeHeader();

    } else {

        $img = new Imagick($file);
        if ($width < $height) {
            $img->resizeImage($width, 0, imagick::FILTER_LANCZOS, 1);
        } else {
            $img->resizeImage(0, $height, imagick::FILTER_LANCZOS, 1);
        }

        $w = $img->getImageWidth();
        $h = $img->getImageHeight();

        $img->cropImage($width, $height, $w/2 - $width/2, $h/2 - $height/2);

        // Remove meta data
        $img->stripImage();

        $format = strtolower($img->getImageFormat());

        // convert unsupported format to jpeg
        if (!in_array($format, array("jpeg", "png", "gif"))) {
            $img->setImageFormat("jpeg");
            $format = "jpeg";
        }

        if ($format === "jpeg") {
            $img->setImageCompression(Imagick::COMPRESSION_JPEG);
            $img->setImageCompressionQuality($quality);
        } else if ($format === "png") {
            $img->setImageCompression(Imagick::COMPRESSION_ZIP);
            $img->setImageCompressionQuality(0);
        }

        $response = new StreamedResponse(
            function() use ($img) {
                echo $img;
            },
            200,
            array("Content-Type" => "image/{$format}")
        );
        $response->setLastModified(DateTime::createFromFormat("U", time()));
        $response->setEtag($sha1);
    }

    $response->prepare($request);

} catch (Exception $e) {
    error_log($e->getMessage());
    $response = new Response("", 404);
}

$response->send();
