<?php
require_once 'functions.php';
function deliveryHandler($deal)
{

    static $void=0;
    $void++;
    if ($void > 1) return null; //защита от рекурсии

    if (Bitrix\Main\Loader::includeModule('test')) {
        $app=test\App::getInstance();

        if($deal['STAGE_ID']==$app::$TRACK_STAGE AND empty($deal[$app::$UF_TRACE_ID])){
            $app::process($deal['ID']);
        }

    }
    //file_put_contents(__DIR__.'/test.txt', $deal['ID']."\n", FILE_APPEND);

}
AddEventHandler("crm", "OnAfterCrmDealUpdate", "deliveryHandler");