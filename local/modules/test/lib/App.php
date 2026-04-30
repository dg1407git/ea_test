<?php
namespace test;
//1) Прочитать сделку и связанный контакт (телефон/email).
//2) Получить товарные позиции и посчитать сумму SUM_PRODUCTS (по ценам/количествам).
//3) Вызвать внешний сервис расчёта доставки (в задании — mock) и получить eta_days.
//4) Посчитать RiskScore по уникальной формуле (ниже).
//5) Записать результаты в пользовательские поля сделки.
//6) Добавить комментарий в таймлайн с краткой расшифровкой расчёта и trace‑id.
//7) Если RiskScore ≥ 60 — создать задачу ответственному по сделке (и не создавать дубли при повторных событиях/ретраях).
//8) Опционально: запустить бизнес‑процесс согласования через REST, если он доступен (если недоступен — корректно отключить функцию и описать это в README).
use Bitrix\Main\Loader;
use CCrmDeal;
use CIBlockElement;
use CCrmContact;
use CCrmFieldMulti;
use CCrmProductRow;
use Bitrix\Main\Web\HttpClient;
use CTasks;
use CCrmOwnerType;
use Bitrix\Crm\Timeline\CommentEntry;
use CBPDocument;
use Bitrix\Crm\Tracking\Internals\TraceTable;
use Bitrix\Crm\Timeline\Entity\TimelineBindingTable;

class App{
    public static $TRACK_STAGE='UC_N2BO2L';
    public static $REGIONS_IBLOCK_ID=16;
    public static $UF_PRICE_DELIVERY = 'UF_CRM_1777467240'; //Цена на момент доставки
    public static $UF_TOTAL_WEIGHT = 'UF_CRM_1777469129'; //Общий вес товаров в кг.
    public static $UF_REGION = 'UF_CRM_1777470762'; //Регион доставки
    public static $UF_SLA_DUE_AT='UF_CRM_1777410006'; //Дата и время доставки
    public static $UF_DELIVERY_ZONE='UF_CRM_1777466263'; //Зона доставки
    public static $UF_ETA_DAYS='UF_CRM_1777466480'; //EtaDays
    public static $UF_RISK_SCORE='UF_CRM_1777466408';//RiskScore
    public static $UF_TRACE_ID='UF_CRM_1777490692';//TraceId

    public static $log_dir=__DIR__.'/../logs/';
    public static $Instance;
    public static $HttpClient;
    public static $regions;
    public function __construct()
    {
        loadEnv(__DIR__ . '/../.env');
        Loader::includeModule('crm');
        Loader::includeModule('iblock');
        self::$HttpClient=new HttpClient();
        $res = CIBlockElement::GetList ([],["IBLOCK_ID" => self::$REGIONS_IBLOCK_ID]);
        while ($region=$res->GetNext()){
           self::$regions[$region['ID']]=["CODE"=>$region["CODE"], "NAME"=>$region["NAME"]];
        }
    }
    public static function getInstance(){
        if(!isset(self::$Instance)){
            self::$Instance = new self();
        }
        return self::$Instance;
    }
    //1
    public static function getDeal($id) : array
    {
        $deal  = CCrmDeal::GetList([], [
                "ID" => $id,
            ]
        )->fetch();
        $phone=CCrmFieldMulti::GetList([],['ELEMENT_ID' => $deal['CONTACT_ID'] ,'ENTITY_ID' => 'CONTACT', 'TYPE_ID'=>'PHONE'])->fetch()['VALUE'];
        $email=CCrmFieldMulti::GetList([],['ELEMENT_ID' => $deal['CONTACT_ID'] ,'ENTITY_ID' => 'CONTACT', 'TYPE_ID'=>'EMAIL'])->fetch()['VALUE'];;

        $deal['CALC_PHONE']=$phone;
        $deal['CALC_EMAIL']=$email;

        return $deal;
    }
    //2
    public static function calculateSumProducts(&$deal) : void
    {
        $total_price=0;
        $total_weight=0;
        $arFilter = [
            "OWNER_TYPE" => "D", // "L" - тип
            "OWNER_ID"=> $deal["ID"], //ID сделки, лида, предложения
            "CHECK_PERMISSIONS"=>"N" //не проверять права доступа текущего пользователя
        ];
        $arSelect = [
            "*"
        ];
        $res = CCrmProductRow::GetList(['ID'=>'DESC'], $arFilter,false,false,$arSelect);
        while($arProduct = $res->Fetch()){
            $properties=CIBlockElement::GetByID($arProduct["PRODUCT_ID"])->GetNextElement()->GetProperties();
            $total_price+=(float)$arProduct["PRICE"]*(int)$arProduct["QUANTITY"];
            $total_weight+=(float)str_replace(',', '.', $properties["WEIGHT"]["VALUE"]);

        }
        $deal[self::$UF_PRICE_DELIVERY]=$total_price;
        $deal[self::$UF_TOTAL_WEIGHT]=$total_weight;
    }
    //3
    public static function setEtaDays(&$deal) : void
    {
        $data=self::courierQuoteMock($deal[self::$UF_REGION]);
        if(empty($data)) throw new \Exception('service courierQuote return null');
        $base_eta_days=$data['base_eta_days'];
        if($deal[self::$UF_TOTAL_WEIGHT]>12) $base_eta_days+=1;
        $deal[self::$UF_ETA_DAYS]=$base_eta_days;
        $deal[self::$UF_DELIVERY_ZONE]=$data['zone'];
    }
    //4
    /**
        RiskScore = clamp(0..100,
            25
            + 15 * Missing(ContactPhone)
            + 10 * Missing(ContactEmail)
            + min(20, 20 * OverdueHours/24)
            + 12 * (SUM_PRODUCTS > 150000 ? 1 : 0)
            + 8  * (DeliveryZone in ["Z3","Z7"] ? 1 : 0)
            + 5  * (ExternalQuote.eta_days >= 7 ? 1 : 0)
            )

        OverdueHours = max(0, Now - UF_SLA_DUE_AT) в часах
        Missing(x)=1 если пусто, иначе 0
     */
    public static function getRiskScore($deal) : int
    {
        $result[]=25;
        $result[]=15 * (int)(boolean)$deal['CALC_PHONE'];
        $result[]=15 * (int)(boolean)$deal['CALC_EMAIL'];
        $result[]= min(20, 20 * max(0, (time() - strtotime($deal[self::$UF_SLA_DUE_AT]))/3600/24));
        $result[]=12 * ($deal[self::$UF_PRICE_DELIVERY] > 150000 ? 1 : 0);
        $result[]= 8  * ((in_array($deal[self::$UF_DELIVERY_ZONE], ["Z3","Z7"])) ? 1 : 0);
        $result[]= 5  * (($deal[self::$UF_ETA_DAYS]>=7) ? 1 : 0);
        $result=array_sum($result);
        if($result>100) return 100;
        return $result;

    }
    //5
    public static function updateDeal($deal)
    {
        $Helper = new CCrmDeal(false);
        $result=$Helper->Update($deal['ID'], $deal);
        if(!$result) throw new \Exception('deal not update');
        return $result;
    }
    //6
    public static function writeTimeline($deal, $text)
    {
        $cid = CommentEntry::create([
            'TEXT' => $text,
            //'AUTHOR_ID' => $deal['ASSIGNED_BY_ID'],
            'BINDINGS' => [['ENTITY_TYPE_ID' => CCrmOwnerType::Deal, 'ENTITY_ID' => $deal['ID']]]
        ]);

        if ($cid > 0) {
            TimelineBindingTable::update(
                [
                    'OWNER_ID' => $cid,
                    'ENTITY_ID' => $deal['ID'],
                    'ENTITY_TYPE_ID' => CCrmOwnerType::Deal
                ],
                ['IS_FIXED' => 'Y']
            );
        }
        return $cid;
    }
    //7
    public static function createTask($deal){

        if (Loader::includeModule("tasks"))
        {
            $title = "Проверка сделки ". $deal["ID"];

            $res=CTasks::GetList([], ['TITLE'=>$title, "RESPONSIBLE_ID" => $deal['ASSIGNED_BY_ID']]);
            if($res->fetch()["ID"]) $res->fetch()["ID"];
            $arFields = Array(
                "TITLE" => $title,
                "DESCRIPTION" => "RiskScore ≥ 60 в сделке: " . $deal["ID"],
                "RESPONSIBLE_ID" => $deal['ASSIGNED_BY_ID'],
                //'CREATED_BY'
                //"GROUP_ID" => 3
            );
            $obTask = new CTasks;
            $ID = $obTask->Add($arFields);
            if($ID>0) return $ID;
            else throw new \Exception('failed to create task');
        }
    }
    //8
    public static function runBP($deal_id){

        $errors=[];
        $result=CBPDocument::StartWorkflow(
            17,
            array("crm", "CCrmDocumentDeal", "DEAL_".$deal_id),
            [],
            $errors
        );
        return $result;
    }

    public static function process($deal_id){
        try{
            $deal=self::getDeal($deal_id);
            self::calculateSumProducts($deal);
            self::setEtaDays($deal);
            $deal[self::$UF_RISK_SCORE]=self::getRiskScore($deal);
            $deal[self::$UF_TRACE_ID]=$deal_id;
            self::updateDeal($deal);
            self::writeTimeline($deal, self::getTimelineMessage($deal));
            if($deal[self::$UF_RISK_SCORE]>60) self::createTask($deal);
            self::runBP($deal['ID']);
        }catch (\Throwable $e){
            file_put_contents(self::$log_dir."/errors/$deal_id.txt", date("Y-m-d H:i:s").": {$e->getMessage()}, {$e->getLine()}\n", FILE_APPEND);
        }


    }

    public static function courierQuoteMock($city_id){
        $url=getenv('COURIER_MOCK');
        $answer=self::$HttpClient->get($url);
        file_put_contents(self::$log_dir."/courierQuotes/answer.txt", $answer);
        $city_code=self::$regions[$city_id]['CODE'];
        $data = json_decode($answer, true);
        return $data[$city_code];

    }

    public static function getTimelineMessage($deal) : string
    {
        $message[]='TraceId: '.$deal[self::$UF_TRACE_ID];
        $message[]='Цена на момент доставки: '.$deal[self::$UF_PRICE_DELIVERY].' руб.';
        $message[]='Общий вес товаров: '.$deal[self::$UF_TOTAL_WEIGHT].' кг.';
        $message[]='Зона доставки: '.$deal[self::$UF_DELIVERY_ZONE];
        $message[]='EtaDays: '.$deal[self::$UF_ETA_DAYS];
        $message[]='RiskScore: '.$deal[self::$UF_RISK_SCORE];

        return implode("\n", $message);
    }


}
