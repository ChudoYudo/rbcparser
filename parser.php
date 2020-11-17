<?php

libxml_use_internal_errors(true);

function get_final_url($url, $timeout = 5, $count = 0)
{
    $count++;
    $ua = 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:18.0) Gecko/20100101 Firefox/18.0';

    $cookie = tempnam("/tmp", "CURLCOOKIE");
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_USERAGENT, $ua);
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_COOKIEJAR, $cookie);
    curl_setopt($curl, CURLOPT_ENCODING, "");
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_AUTOREFERER, true);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

    $content = curl_exec($curl);
    $response = curl_getinfo($curl);
    curl_close($curl);
    unlink($cookie);


    //Normal re-direct
    if ($response['http_code'] == 301 || $response['http_code'] == 302 || $response['http_code'] == 303) {
        ini_set("user_agent", $ua);
        $headers = get_headers($response['url']);

        $location = "";
        foreach ($headers as $value) {
            if (substr(strtolower($value), 0, 9) == "location:") {
                return get_final_url(trim(substr($value, 9, strlen($value))), 8, $count);
            }
        }
    }

    //Meta-refresh redirect
    if (preg_match("/meta.*refresh.*URL=.*(http[^'\"]*)/i", $content, $value)) {
        if (strpos($value[1], "http") !== FALSE) {
            return get_final_url($value[1]);
        }
    }

    //Javascript re-direct
    if (preg_match("/window\.location\.replace\('(.*)'\)/i", $content, $value) || preg_match("/window\.location\=\"(.*)\"/i", $content, $value)) {

        if (strpos($value[1], "http") !== FALSE) {
            return get_final_url($value[1]);
        } else {
            return $response['url'];
        }
    } else {

        return $response['url'];
    }
}

function parseLinks($count = 15)
{
    $currentTime = time();
    $url = "https://www.rbc.ru/v10/ajax/get-news-feed/project/rbcnews/lastDate/$currentTime/limit/$count";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "google");
    $response = json_decode(curl_exec($ch));
    curl_close($ch);

    $linksArray = [];
    foreach ($response->items as $item) {
        $dom = new DOMDocument;
        $dom->loadHTML($item->html);
        $xpath = new DomXPath($dom);
        $element = $xpath->document->getElementsByTagName('a');
        $linksArray[] = $element->item(0)->getAttribute('href');
    }
    return $linksArray;
}


function parseMeta($url)
{
    $url = get_final_url($url);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, "google");
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $content = curl_exec($ch);

    $dom = new DOMDocument;
    $dom->loadHTML($content);
    $xpath = new DomXPath($dom);

    $title = $xpath->query("//title")->item(0)->nodeValue;
    $title = strtok($title, '::');

    $description = $xpath->query("//meta[@name='description' or @name='Description']")->item(0)->getAttribute('content');
    $description = mb_strimwidth($description, 0, 200, " подробнее...");

    if ($xpath->query("//meta[@property='yandex_recommendations_image']")->item(0)) {
        $image_link = $xpath->query("//meta[@property='yandex_recommendations_image']")->item(0)->getAttribute('content');
    } else {
        $image_link = null;
    }

    return [$title, $description, $url, $image_link];
}

$urls = parseLinks(15);

$data_list = [['header', 'description', 'full_link', 'image_link']];

foreach ($urls as $url) {
    $data_list[] = parseMeta($url);
}

$file = fopen('parsed.csv', 'w');
foreach ($data_list as $fields) {
    fputcsv($file, $fields);
}

fclose($file);
