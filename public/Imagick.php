<?php

require __DIR__ ."/../autoload.php";

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;


if (!defined("ROOT")) {
   defined("ROOT", "/var/www/ephemeral/orig");
}
$root = ROOT;

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

        $img  = new \Imagick();
        $size = getimagesize($file);

        // additional hint for JPEG decoder
        if ($size[2] === IMAGETYPE_JPEG) {
            $widthHint  = $width * 2 > 5000 ? 5000 : $width * 2;
            $heightHint = $height * 2 > 5000 ? 5000 : $height * 2;
            if ($widthHint > $size[0]) {
                $widthHint = $size[0];
            }
            if ($heightHint > $size[1]) {
                $heightHint = $size[1];
            }
            $img->setOption("jpeg:size", "{$widthHint}x{$heightHint}");
        }

        $img->readImage($file);

        if($size[0] < $size[1]) {
            $img->resizeImage($width, 0, imagick::FILTER_LANCZOS, 1);
        } else {
            $img->resizeImage(0, $height, imagick::FILTER_LANCZOS, 1);
        }


        // crop only if both params are positive
        if ($width > 0 && $height > 0) {
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();

            $img->cropImage($width, $height, $w/2 - $width/2, $h/2 - $height/2);
        }

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
