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

$src    = $_GET["src"];
$width  = (int) $_GET["w"];
$height = (int) $_GET["h"];

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

    $img = new Imagick($file);
    $img->resizeImage($width, 0, imagick::FILTER_LANCZOS, 1);

    $w = $img->getImageWidth();
    $h = $img->getImageHeight();

    $img->cropImage($width, $height, $w/2 - $width/2, $h/2 - $height/2);

    $response = new StreamedResponse(
        function() use ($img) {
            echo $img;
        },
        200,
        array("Content-Type" => "image/jpeg")
    );
    $response->setLastModified(DateTime::createFromFormat("U", time()));
    $response->setEtag($sha1);

} catch (Exception $e) {
    $response = new Response("", 404);
}

$response->send();
