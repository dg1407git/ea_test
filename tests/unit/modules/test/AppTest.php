<?php
if($_SERVER["DOCUMENT_ROOT"]) die();
$_SERVER["DOCUMENT_ROOT"]=__DIR__ . "/../../../../";
define("NOT_CHECK_PERMISSIONS", true);

require_once(__DIR__ . "/../../../../bitrix/modules/main/include/prolog_before.php");
require_once(__DIR__ . "/../../../../vendor/autoload.php");

use PHPUnit\Framework\TestCase;
use Bitrix\Main\Loader;
use test\App;

Loader::includeModule('test');
class AppTest extends TestCase
{
    public function testCourierQuoteMock()
    {
        $app=App::getInstance();
        $data=$app::courierQuoteMock(33);
        $this->assertIsArray($data);
    }
}

