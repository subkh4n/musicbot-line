<?php
require __DIR__ . '/vendor/autoload.php';
 
use \LINE\LINEBot;
use \LINE\LINEBot\HTTPClient\CurlHTTPClient;
use \LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use \LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use \LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use LINE\LINEBot\MessageBuilder\AudioMessageBuilder;
use \LINE\LINEBot\SignatureValidator as SignatureValidator;
 
// set false for production
$pass_signature = true;
 
// set LINE channel_access_token and channel_secret
$channel_access_token = "xH0QLcH40WbGg36W9xveYb+ENphHJogyIC9oeEMcXqLKOMplYh/+29th/9xDLfmQ5YsgioUkucZbzYf4BGo4L+K+e1UpkkB1Pki5LvZ4OS9co9SG7c3+MR3OuzlcycTVeL6RZxME1ipAfu20tkBNWgdB04t89/1O/w1cDnyilFU=";
$channel_secret = "4b334c11eaea8b87bc235ec360ae2796";
 
// inisiasi objek bot
$httpClient = new CurlHTTPClient($channel_access_token);
$bot = new LINEBot($httpClient, ['channelSecret' => $channel_secret]);
 
$configs =  [
    'settings' => ['displayErrorDetails' => true],
];
$app = new Slim\App($configs);
 
// buat route untuk url homepage
$app->get('/', function($req, $res)
{
  echo "Welcome to musicbot-line";
});
 
// buat route untuk webhook
$app->post('/webhook', function ($req, $res) use ($bot, $httpClient, $pass_signature)
{
    // get request body and line signature header
    $body = file_get_contents('php://input');
    $signature = isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? $_SERVER['HTTP_X_LINE_SIGNATURE'] : '';
 
    // log body and signature
    file_put_contents('php://stderr', 'Body: '.$body);
 
    if($pass_signature === false)
    {
        // is LINE_SIGNATURE exists in request header?
        if (empty($signature))
        {
            return $res->withStatus(400, 'Signature not set');
        }
 
        // is this request comes from LINE?
        if (! SignatureValidator::validateSignature($body, $channel_secret, $signature))
        {
            return $res->withStatus(400, 'Invalid signature');
        }
    }
 
    $data = json_decode($body, true);
    if(is_array($data['events']))
    {
        foreach ($data['events'] as $event)
        {
            if ($event['type'] == 'follow')
            {
                if($event['source']['userId'])
                {
                    $userId     = $event['source']['userId'];
                    $getprofile = $bot->getProfile($userId);
                    $profile    = $getprofile->getJSONDecodedBody();
                    $greetings  = new TextMessageBuilder("Hi ".$profile['displayName']."aku adalah bot yang akan membantu kamu untuk menemukan, mendengarkan music apa yang kamu mau dengarkan");
                    $onboarding = new TextMessageBuilder('Ketik LAGU atau ARTIS ya jika kalian ingin mendengarkan sebuah lagu');

                    $multiMessageBuilder = new MultiMessageBuilder();
                    $multiMessageBuilder->add($greetings);
                    $multiMessageBuilder->add($onboarding);

                    $result = $bot->replyMessage($event['replyToken'], $multiMessageBuilder);
                    return $res->withJson($result->getJSONDecodedBody(), $result->getHTTPStatus());
                }
            } elseif ($event['type'] == 'message')
            {
                if($event['message']['type'] == 'text')
                {
                    $message = strtolower($event['message']['text']);
                    if ($message == 'lagu')
                    {
                        $textMessageBuilder = new TextMessageBuilder('Lagu apa yang kamu cari?');
                        $result = $bot->replyMessage($event['replyToken'], $textMessageBuilder);

                        return $res->withJson($result->getJSONDecodedBody(), $result->getHTTPStatus());
                    } elseif ($message == 'artis')
                    {
                        $textMessageBuilder = new TextMessageBuilder('Siapa Artis yang kamu cari?');
                        $result = $bot->replyMessage($event['replyToken'], $textMessageBuilder);

                        return $res->withJson($result->getJSONDecodedBody(), $result->getHTTPStatus());
                    } else
                    {
                        if ($message != 'download')
                        {
                            $client = new \GuzzleHttp\Client();
                            $request = $client->request('GET', 'https://botline-dicoding.herokuapp.com/music.php/search/'.$message);
                            $response = json_decode($request->getBody());

                            if ($response->status == 'false')
                            {
                                $textMessageBuilder = new TextMessageBuilder('Not Found, Coba kata kunci lain');
                                $result = $bot->replyMessage($event['replyToken'], $textMessageBuilder);
                            } else
                            {
                                $result = $httpClient->post(LINEBot::DEFAULT_ENDPOINT_BASE . '/v2/bot/message/reply', [
                                    'replyToken' => $event['replyToken'],
                                    'messages'   => [
                                        [
                                            'type'     => 'flex',
                                            'altText'  => 'this is a flex message',
                                            'contents' => [
                                                'type' => 'carousel',
                                                'contents' => $response->videos
                                            ]
                                        ]
                                    ],
                                ]);
                            }

                            // MANUAL
                            // $flexTemplate = file_get_contents("flex_music.json");
                            // $result = $httpClient->post(LINEBot::DEFAULT_ENDPOINT_BASE . '/v2/bot/message/reply', [
                            //     'replyToken' => $event['replyToken'],
                            //     'messages'   => [
                            //         [
                            //             'type'     => 'flex',
                            //             'altText'  => 'this is a flex message',
                            //             'contents' => [
                            //                 'type' => 'carousel',
                            //                 'contents' => json_decode($flexTemplate)
                            //             ]
                            //         ]
                            //     ],
                            // ]);

                            return $res->withJson($result->getJSONDecodedBody(), $result->getHTTPStatus());
                        }
                    }
                }
            } elseif ($event['type'] == 'postback')
            {
                $explodeData    = explode('&', $event['postback']['data']);
                $action         = explode('=', $explodeData[0]);

                if ($action[1] == 'download')
                {
                    $title     = explode('=', $explodeData[1]);
                    $videoId   = explode('=', $explodeData[2]);
                    $videoUrl  = explode('=', $explodeData[3]);
                    $duration  = explode('=', $explodeData[4]);

                    $links = "https://api.download-lagu-mp3.com/@download/$videoUrl[1]/mp3/$videoId[1]/$title[1].mp3";
                    
                    $audioMessageBuilder = new AudioMessageBuilder($links, $duration[1]);
                    $result = $bot->replyMessage($event['replyToken'], $audioMessageBuilder);

                    // $textMessageBuilder = new TextMessageBuilder($links);
                    // $result = $bot->replyMessage($event['replyToken'], $textMessageBuilder);

                    return $res->withJson($result->getJSONDecodedBody(), $result->getHTTPStatus());
                }
            }
        } 
    }

    return $res->withStatus(400, 'No event sent!');
});

$app->get('/profile/{userId}', function($req, $res) use ($bot)
{
    $route  = $req->getAttribute('route');
    $userId = $route->getArgument('userId');
    $result = $bot->getProfile($userId);
             
    return $res->withJson($result->getJSONDecodedBody(), $result->getHTTPStatus());
});

function jsonOnBoarding(){
    $string = '{"type":"bubble","body":{"type":"box","layout":"vertical","spacing":"sm","contents":[{"type":"text","text":"Apa yang ingin kamu cari ?","wrap":true,"weight":"bold","size":"md"}]},"footer":{"type":"box","layout":"horizontal","spacing":"sm","contents":[{"type":"button","flex":2,"style":"primary","action":{"type":"message","label":"Lagu","text":"lagu"}},{"type":"button","flex":2,"style":"primary","action":{"type":"message","label":"Artis","text":"artis"}},{"type":"spacer","size":"sm"}],"flex":0}}';
    $data = json_decode($string, true);

    return $data;
}

$app->run();