<?php
    if(!defined("CORE_FOLDER")) die();

    $lang       = $module->lang;
    $config     = $module->config;

    $username   = Filter::init("POST/username","hclear");
    $password   = Filter::init("POST/password","hclear");
    if($password && $password != "*****") $password = Crypt::encode($password,Config::get("crypt/system"));
    $test_mode      = (int) Filter::init("POST/test-mode","numbers");

    $sets       = [];

    if($username != $config["settings"]["username"])
        $sets["settings"]["username"] = $username;

    if($password != "*****" && $password != $config["settings"]["password"])
        $sets["settings"]["password"] = $password;

    if($test_mode != $config["settings"]["test-mode"])
        $sets["settings"]["test-mode"] = $test_mode;


        $_config    = array_replace_recursive($config,$sets);

        $username   = $_config["settings"]["username"];
        $password   = $_config["settings"]["password"];

        if($password)   $password = Crypt::decode($password,Config::get("crypt/system"));


        if(!$username || !$password){
            die(Utility::jencode([
                'status' => "error",
                'message' => $lang["error2"],
            ]));
        }

        $tmode      = $test_mode ? true : false;

        $module->setConfig($username,$password,$tmode);


    if(!$module->testConnection())
        die(Utility::jencode([
            'status' => "error",
            'message' => $module->error,
        ]));

    echo Utility::jencode(['status' => "successful",'message' => $lang["success2"]]);