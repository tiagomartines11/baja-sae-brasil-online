<?php
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE);

if (!isset($input) || !isset($input['key']) || $input['key'] != "DesativadoAté2018" || $_SERVER["REDIRECT_YEAR"] != 2018) {
    header('HTTP/1.0 403 Forbidden');
    exit();
}

if (array_key_exists("truncate", $input) && $input["truncate"] == "all") {
    DB::query("TRUNCATE TABLE end");
    die("OK");
}

if (array_key_exists("raceTime", $input)) {
    $p = Prova::getProvaById("end");
    $p->setTempo($input["raceTime"]);
    Prova::insertUpdate($p);
}

if (array_key_exists("cars", $input)) {
    foreach ((array)$input["cars"] as $carObj) {
        $e = new Enduro();
        $e->initWithRemoteData($carObj["number"], $carObj["laps"], $carObj["bestLapTime"]);
        $e->setUser("system");
        Enduro::insertUpdate($e);
    }
    die("OK");
}

die("NO ACTION");