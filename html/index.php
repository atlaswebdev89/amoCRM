<?php
session_start();
define('TOKEN_FILE', 'tmp' . DIRECTORY_SEPARATOR . 'token_info.json');
define('CODE', 'def5020091dc33188fc5715f8fffafc06ad9b63176e7014dacb862092db9c17637a37d95e95d11a8f47ffd7695680be9a5d44ef76b398a4abe7cf8226c0187dfcb06ee52570ec75829453f40c57a792874616a65bc641fbf89a25cc45e3fc883c4047bcaea60b8b1a913193b0953750ad70ae1aa1fa937a489c141d95294e8c87def705a77948ef5f270cf28ad500fac13960167d5975335fe471c499c2414a983de673a9de0912b971a228e3a04e2a53b542ac447e08b54097f4297211245faff1be331951413b3d7c1dd2b4e826e237f56136c954edf061f0a392e5172ddfd88ca1c4dce1b80c1b7942a8192671f594c2af24a30d69c20835c0c686103decedc65ff23ee8c13db8f90155440e9c04350466b290d3a2d7c4b643f98f71be44a2c80225ff0dc1f83b39c93809ab9b98e991d53df87d3d11a47f4dfaff828b04d601c777ffb2b89e347d62b2add13eb95ead318d4494bffc1c082f56b2e6faf08650cb2f72778751cef6d4e5808fd3d09d691a66d212c638e045d1b273aa893be0ad679ea2f20b61e80e127affd6b040bd860f29a51b987db584e2cc948dc5877df77c99071ae35ce8584db82d247d75358042001d09d94d2e5db0733');
define('SUBDOMEN', 'webdev89');
//Composer Autoload
require_once 'vendor/autoload.php';
/**
 * Создаем провайдера
 */
$provider = new AmoCRM\OAuth2\Client\Provider\AmoCRM([
    'clientId' => '1bc989dc-2f4b-4bd8-b38d-56d339f33dc1',
    'clientSecret' => 'dbZF7ybEKJwYcUA9S3XYDMusR56NC0xZVZaQO5JveA7n3OTupNJXM6AUAGx7jjet',
    'redirectUri' => 'https://sv-kupe.by',
]);
$link = SUBDOMEN.'.amocrm.ru';
$provider->setBaseDomain($link);

try{
    $accessToken = getToken();
    //Проверяем срок действия токена, если просрочен обновляем с помощью refresh token
    if($accessToken->hasExpired()) {
        $accessToken = $provider->getAccessToken(new League\OAuth2\Client\Grant\RefreshToken(), [
                'refresh_token' => $accessToken->getRefreshToken(),
            ]);
            
            saveToken([
                'accessToken' => $accessToken->getToken(),
                'refreshToken' => $accessToken->getRefreshToken(),
                'expires' => $accessToken->getExpires(),
                'baseDomain' => $provider->getBaseDomain(),
            ]);
    }
    //Обновляем токен
    $token = $accessToken->getToken();
    //Получаем список контактов
    $data_contacts = get_contacts ($provider, $token);
    //Создаем задачи у необходимых контактов
    add_create_task_contacts ($provider,$token,$data_contacts);
} 
catch (\CustomException\TokenCustomException $e) {
    if($e->getCode() == 30) {
         getTokenCodeAutorization ($provider);
    }else {
        echo $e->getMessage(), "\n";
    }
 }
catch (\CustomException\ContactsException $e) {
     echo $e->getMessage(), "\n";
     
 }
catch (\Exception $e){
   echo $e->getMessage(), "\n";
}
  
//Функция сохранения полученных токенов в файл json   
function saveToken($accessToken)
{
    if (
            isset($accessToken)
            && isset($accessToken['accessToken'])
            && isset($accessToken['refreshToken'])
            && isset($accessToken['expires'])
            && isset($accessToken['baseDomain'])
    ) {
        $data = [
            'accessToken' => $accessToken['accessToken'],
            'expires' => $accessToken['expires'],
            'refreshToken' => $accessToken['refreshToken'],
            'baseDomain' => $accessToken['baseDomain'],
        ];
        file_put_contents(TOKEN_FILE, json_encode($data));
    } else {
        throw new \CustomException\TokenCustomException("Ошибка записи токена в файл ".TOKEN_FILE, 20);
    }
}

//Функция получения полученных токенов из файла
function getToken()
{
    $accessToken = json_decode(file_get_contents(TOKEN_FILE), true);
    if (
            isset($accessToken)
            && isset($accessToken['accessToken'])
            && isset($accessToken['refreshToken'])
            && isset($accessToken['expires'])
            && isset($accessToken['baseDomain'])
    ) {
        
        return new \League\OAuth2\Client\Token\AccessToken([
            'access_token' => $accessToken['accessToken'],
            'refresh_token' => $accessToken['refreshToken'],
            'expires' => $accessToken['expires'],
            'baseDomain' => $accessToken['baseDomain'],
        ]);
    } 
        else {
            throw new \CustomException\TokenCustomException("Ошибка получения токена из файла ".TOKEN_FILE, 30);
    }
}

//Функция получение токена по полученном коду авторизации
function getTokenCodeAutorization ($provider) {
    //Получение токена по имеющемуся коду авторизации
    $accessToken = $provider->getAccessToken(new League\OAuth2\Client\Grant\AuthorizationCode(), [
        'code' => CODE
    ]);  

    if (!$accessToken->hasExpired()) {
        saveToken([
                'accessToken' => $accessToken->getToken(),
                'refreshToken' => $accessToken->getRefreshToken(),
                'expires' => $accessToken->getExpires(),
                'baseDomain' => $provider->getBaseDomain(),
            ]);
    }
    
    $ownerDetails = $provider->getResourceOwner($accessToken);
    echo '<div><p>Получение токена по коду авторизации</p></div>';
    printf('Добро пожаловать, %s!', $ownerDetails->getName());
}
//Функция получения контактов без сделок и без созданной задачи "Контакт без сделок"
function get_contacts ($provider, $token) {
        //Получаем список контактов
        $data = $provider->getHttpClient()->request('GET', $provider->urlAccount() . '/api/v4/contacts',
                [
                    'headers' => $provider->getHeaders($token),
                    'query' => ['with'=>'leads'],
                ]);
        $parsedBody = json_decode($data->getBody()->getContents(), true);
        if($parsedBody) {
            foreach ($parsedBody['_embedded']['contacts'] as $contacts) {
                if (!$contacts['_embedded']['leads']) {
                    $arr [] = $contacts;
                }
            } 
            if (empty($arr)) {
               throw new \CustomException\ContactsException("Нет контактов без сделок"); 
            }
            //Проверка у польвователя наличие текущей не выполненой задачи
            foreach ($arr as $key => $contact) {
                $data = $provider->getHttpClient()->request('GET', $provider->urlAccount() . '/api/v4/tasks',
                    [
                        'headers' => $provider->getHeaders($token),
                        'query' => 
                                    [
                                        'filter[entity_id][]'=>$contact['id'],
                                        'filter[entity_type][]'=>'contacts',
                                        'order' => 'desc',
                                    ],
                    ]);
                
                        $data = json_decode($data->getBody()->getContents(), true);
                        
                        foreach ($data['_embedded']['tasks'] as $task) {
                            if($task['text'] == 'Контакт без сделок' && $task['is_completed'] == false) {
                                unset($arr[$key]);  
                            }
                        }
                }
            if (!empty($arr)) {
                    return $arr;
            }else {
                  throw new \CustomException\ContactsException("У всех контактов без сделок уже создана задача 'Контакт без сделок'");
            }
        } else {
             throw new \CustomException\ContactsException("Нет полученных контактов");
        }
}

//Создание задачи для каждого полученного контакта 'Контакт без сделок'
function add_create_task_contacts ($provider,$token, $data) {
    //Метка времени Задача надо выполнить в течении двух дней
    $time = time()+ (60*60*48);
        foreach($data as $contacts) {
            $json_Data = json_encode([
                                                [
                                                    "task_type_id" =>2,
                                                    "text" => "Контакт без сделок",
                                                    "complete_till" => $time,
                                                    "entity_id" => $contacts['id'],
                                                    "entity_type" => "contacts",
                                                ]
                                     ]);
        
        //Добавляем задачу для контактов без сделок
        $data = $provider->getHttpClient()
                            ->request('POST', $provider->urlAccount() . '/api/v4/tasks',[
                                'headers' => $provider->getHeaders($token), 
                                'body' => ($json_Data)
                            ]);
        $code = $data->getStatusCode();
            if($code == 200) {
                echo "<p>Задача для Контакта {$contacts['name']} создана успешно<p>";
            }else {
                echo "<p>Задача для Контакта {$contacts['name']} не создана<p>";
            }
        }
      
}
