<?php


class ImagickResizeTest extends \PHPUnit_Framework_TestCase
{

    public function testResizeScale1x4()
    {
        $_GET["src"] = "http://static.getdreamshop.dk.s3.amazonaws.com/catalog/products/images/vioca-black-9192272.jpg";
        $_GET["w"]   = 275;
        $_GET["h"]   = 385;
        $_GET["q"]   = 100;

        ob_start();
        include(__DIR__ . "/../public/Imagick.php");
        $content = ob_get_clean();
        file_put_contents(__DIR__ . "/cache/7d/14/result.jpg", $content);

        $imagick  = new Imagick(__DIR__ . "/cache/7d/14/result.jpg");
        $geometry = $imagick->getimagegeometry();
        $imagick->destroy();

        $this->assertEquals(array("width" => 275, "height" => 385), $geometry);

        $imagick  = new Imagick(__DIR__ . "/cache/7d/14/served.jpg");
        $geometry = $imagick->getimagegeometry();
        $this->assertEquals(array("width" => 274, "height" => 385), $geometry);

    }
} 