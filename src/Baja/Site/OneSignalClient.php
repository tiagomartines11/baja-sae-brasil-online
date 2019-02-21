<?php

namespace Baja\Site;

use Baja\Juiz\Session;

class OneSignalClient
{
    public static function sendMessage($title, $message, $url = "", $carFilter = 0){
        global $_oneSignalAuth;
        
        $content = array(
            "en" => $message
        );
        $headings = array(
            "en" => $title
        );

        $fields = array(
            'app_id' => "f4b8f501-88bb-49fe-b209-712ae98da3e2",
            'contents' => $content,
            'headings' => $headings
        );

        if ($carFilter)
            $fields['filters'] = array(
                array("field" => "tag", "key" => "$carFilter", "relation" => "=", "value" => "1"),
                array("operator" => "OR"),
                array("field" => "tag", "key" => "{$_SERVER['REDIRECT_EVENT']}_$carFilter", "relation" => "=", "value" => "1"),
                array("operator" => "OR"),
                array("field" => "tag", "key" => "sender", "relation" => "=", "value" => Session::getCurrentUser()->getUserId())
            );
        else
            $fields['filters'] = array(
                array("field" => "tag", "key" => "{$_SERVER['REDIRECT_EVENT']}_psa", "relation" => "=", "value" => "1"),
                array("operator" => "OR"),
                array("field" => "tag", "key" => "sender", "relation" => "=", "value" => Session::getCurrentUser()->getUserId())
            );

        if ($url) $fields['url'] = "/".$_SERVER['REDIRECT_EVENT']."/".$url;

        $fields = json_encode($fields);

        var_dump($fields);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $_oneSignalAuth));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }
}