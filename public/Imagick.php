<?php

require "../autoload.php";

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

$root = "/var/www/ephemeral/orig";

// force exceptions
set_error_handler(
    function ($severity, $message, $filename, $lineno) {
        throw new ErrorException($message, 0, $severity, $filename, $lineno);
    }
);

$src = $_GET["src"];
$width = (int) $_GET["w"];
$height = (int) $_GET["h"];
$quality = (int) $_GET["q"];
$version = 1;
if (isset($_GET['v'])) {
    $version = (int) $_GET['v'];
}
if (isset($_GET["debug"])) {
    $debug = $_GET["debug"];
} else {
    $debug = false;
}
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

        $img = new Imagick();
        $size = getimagesize($file);

        // additional hint for JPEG decoder
        if ($size[2] === IMAGETYPE_JPEG) {
            $widthHint = min($width * 2, 5000);
            $heightHint = min($height * 2, 5000);
            if ($widthHint > $size[0]) {
                $widthHint = $size[0];
            }
            if ($heightHint > $size[1]) {
                $heightHint = $size[1];
            }
            $img->setOption("jpeg:size", "{$widthHint}x{$heightHint}");
        }

        $img->readImage($file);
        /*
         *  Resizing an image:
         *  Original Size: 830x900
         *
         *  Ratio orig: .922222222
         *
         *  Resized to: 700x1050
         *  Resized Ratio: .666666667
         *
         *
         */

        // crop /extend only if both params are positive

        if ($width > 0 && $height > 0) {
            list($srcWidth, $srcHeight) = $size;
            $ratioOriginal = $srcWidth / $srcHeight;

            switch ($version) {
                default:
                case 1:
                    $desiredWidth = $width;
                    $desiredHeight = $height;

                    if (!($desiredHeight === 0 || $desiredWidth === 0)) {
                        $ratioDesired = $width / $height;
                        if ($debug) {
                            error_log(
                                "Imagick resizing: RatioOriginal: {$ratioOriginal}, RatioDesired: {$ratioDesired}, dW: {$desiredWidth}, dH: {$desiredHeight}, oW: {$size[0]}, oH: {$size[1]}"
                            );
                        }
                        if ($ratioDesired > $ratioOriginal) {
                            $desiredHeight = 0;
                        } else {
                            $desiredWidth = 0;
                        }
                    }

                    $img->resizeImage($desiredWidth, $desiredHeight, imagick::FILTER_LANCZOS, 1);
                    if ($debug) {
                        error_log("Imagick resizing End: dW: {$desiredWidth}, dH: {$desiredHeight}");
                    }
                    $w = $img->getImageWidth();
                    $h = $img->getImageHeight();
                    if ($debug) {
                        error_log("Imagick Cropping: W: {$w}, H: {$h}");
                    }

                    $img->cropImage($width, $height, $w / 2 - $width / 2, $h / 2 - $height / 2);
                    break;
                case 2:

                    $img->resizeImage($width, $height, imagick::FILTER_LANCZOS, 1, true);

                    $canvas = new Imagick();

                    if ($img->getImageFormat() !== 'PNG') {
                        $canvas->newImage($width, $height, 'white', 'jpg');
                    } else {
                        $canvas->newImage($width, $height, 'none', 'png');
                    }

                    $geometry = $img->getImageGeometry();

                    /* The overlay x and y coordinates */
                    $x = ($width - $geometry['width']) / 2;
                    $y = ($height - $geometry['height']) / 2;
                    if ($debug) {
                        var_dump(
                            [
                                'x' => $x,
                                'y' => $y,
                                'geometry' => $geometry,
                                'w' => $width,
                                'h' => $height,
                                'srcW' => $srcWidth,
                                'srcH' => $srcHeight,
                            ]
                        );
                        die();
                    }
                    $canvas->compositeImage($img, imagick::COMPOSITE_OVER, $x, $y);
                    $img = $canvas;
            }
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
        } else {
            if ($format === "png") {
                $img->setImageCompression(Imagick::COMPRESSION_ZIP);
                $img->setImageCompressionQuality(0);
            }
        }

        $response = new StreamedResponse(
            function () use ($img) {
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
