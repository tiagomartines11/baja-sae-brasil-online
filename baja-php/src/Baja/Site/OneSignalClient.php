<?php

namespace Baja\Site;

use Baja\Session;

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
            'app_id' => "2d1b50b2-362f-49c1-a854-3c8c7e0db587",
            'contents' => $content,
            'headings' => $headings
        );

        if ($carFilter) {
            $fields['include_external_user_ids'] = array();
            // Filter by tag using the new API format
            $fields['filters'] = array(
                array("field" => "tag", "key" => (string)$carFilter, "relation" => "=", "value" => "1"),
                array("operator" => "OR"),
                array("field" => "tag", "key" => "{$_SERVER['REDIRECT_EVENT']}_$carFilter", "relation" => "=", "value" => "1")
            );
        } else {
            $fields['filters'] = array(
                array("field" => "tag", "key" => "{$_SERVER['REDIRECT_EVENT']}_psa", "relation" => "=", "value" => "1")
            );
        }

        if ($url) {
            $fields['url'] = "/" . $_SERVER['REDIRECT_EVENT'] . "/" . $url;
        }

        $jsonFields = json_encode($fields);

        // Debug logging
        error_log("OneSignal Request: " . $jsonFields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.onesignal.com/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Basic ' . $_oneSignalAuth
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonFields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Debug logging
        error_log("OneSignal Response: HTTP $httpCode - $response");

        if ($httpCode != 200) {
            error_log("OneSignal API Error: HTTP $httpCode - Response: $response - Curl error: $curlError");
        }

        return $response;
    }
}