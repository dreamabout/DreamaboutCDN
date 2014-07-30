<?php


class ImagickResizeTest extends \PHPUnit_Framework_TestCase
{

    public function testResizeScale1x4()
    {
        phpinfo();

        define("ROOT", __DIR__ . "/cache");

        $_GET["src"] = __DIR__ . "/fixtures/scale/image1.jpg";
        $_GET["w"]   = 275;
        $_GET["h"]   = 385;
        $_GET["q"]   = 100;

        ob_start();
        include(__DIR__ . "/../public/Imagick.php");
        $content = ob_get_clean();
        file_put_contents(__DIR__ . "/cache/ce/64/result.jpg", $content);

        $imagick  = new Imagick(__DIR__ . "/cache/ce/64/result.jpg");
        $geometry = $imagick->getimagegeometry();
        $imagick->destroy();

        $this->assertEquals(array("width" => 275, "height" => 385), $geometry);

        $imagick  = new Imagick(__DIR__ . "/cache/ce/64/served.jpg");
        $geometry = $imagick->getimagegeometry();
        $this->assertEquals(array("width" => 275, "height" => 385), $geometry);

    }
} 
