<?php
    if(!defined("CORE_FOLDER")) die();

    $lang       = $module->lang;
    $data       = Filter::POST("data");

    if(!$data || !is_array($data)) return false;

    if(!$module->import($data))
        die(Utility::jencode([
            'status' => "error",
            'message' => $lang["error9"],
        ]));


    echo Utility::jencode([
        'status' => "successful",
        'message' => $lang["success3"],
    ]);