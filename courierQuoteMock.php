<?php
$data = [
    'RU-MOW-77'=>['city_code'=>'RU-MOW-77', 'zone'=>'Z1', 'base_eta_days'=>2],
    'RU-SPE-78'=>['city_code'=>'RU-SPE-78', 'zone'=>'Z2', 'base_eta_days'=>3],
    'KZ-ALA-01'=>['city_code'=>'KZ-ALA-01', 'zone'=>'Z3', 'base_eta_days'=>6],
    'GE-TBS-10'=>['city_code'=>'GE-TBS-10', 'zone'=>'Z4', 'base_eta_days'=>5],
    'AM-EVN-02'=>['city_code'=>'AM-EVN-02', 'zone'=>'Z5', 'base_eta_days'=>7],
    'TR-IST-34'=>['city_code'=>'TR-IST-34', 'zone'=>'Z6', 'base_eta_days'=>4],
    'AE-DXB-03'=>['city_code'=>'AE-DXB-03', 'zone'=>'Z7', 'base_eta_days'=>8],
];
echo json_encode($data);
die();