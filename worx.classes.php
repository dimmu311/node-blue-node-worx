<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';


class WorxSettings
{
	public $email = '';
    public $password = '';
    public $clientId = '';
    public $nodeId = '';
}

class WorxRest
{ 
	private $settings = NULL;
    private $productItems = NULL;

    private $accessToken = '';
    private $refreshToken = '';
    private $tokenExpiresAt = '';
    
    //mqtt vars
    private $mqttClient = NULL;
    private $connectionSettings = NULL;

    public function __construct(WorxSettings $settings){
        HomegearNodeBase::log(4, '__construct');
        $this->settings = $settings;

        $this->connectionSettings = (new \PhpMqtt\Client\ConnectionSettings)
            ->setUseTls(true)
            ->setTlsSelfSignedAllowed(true)
            ->setTlsVerifyPeer(true)
            //->setUsername($token)
            ->setTlsAlpn('mqtt');
    }
    
    public function setValue($msg){
        HomegearNodeBase::log(4, 'setValue');
        if(!isset($msg['cmd']) || !isset($msg['objectId'])){
            HomegearNodeBase::log(2, 'setValue Struct must contain "cmd" and "objectId"');
            \Homegear\Homegear::nodeOutput($this->settings->nodeId, 1, array('payload' => 'setValue Struct must contain "cmd" and "objectId"'));
            return false;
        }
        if(!$this->productItems) $this->getProductItems();
        $url = $this->productItems[$msg['objectId']]['mqtt_endpoint'];
        $topic = $this->productItems[$msg['objectId']]['mqtt_topics']['command_in'];
        $cmd = '{"cmd":'.$msg['cmd'].'}';

        $clientID = 'WX/USER/'. $this->productItems[$msg['objectId']]['user_id']. '/'. 'Homegear/'. $this->settings->nodeId;

        HomegearNodeBase::log(4, 'set Value to '. $url. ' to topic '. $topic. ' => '. $cmd. ' with client id => '.$clientID );
        try {
            $this->mqttClient = new \PhpMqtt\Client\MqttClient($url, 443, $clientID, \PhpMqtt\Client\MqttClient::MQTT_3_1_1, null, null);
            $this->mqttClient->connect($this->connectionSettings, true);
            $this->mqttClient->publish($topic, $cmd, \PhpMqtt\Client\MqttClient::QOS_AT_MOST_ONCE);
            $this->mqttClient->disconnect();

            \Homegear\Homegear::nodeOutput($this->settings->nodeId, 1, array('payload' => true));
            return true;
        } catch (MqttClientException $e) {
            HomegearNodeBase::log(2,'Connecting with TLS or publishing with QoS 0 failed. An exception occurred. '. 'exception =>'. $e);
            return false;
        }
        return false;
    }   

    private function addTokenToConnectionSettings(){
        $token = $this->accessToken;
        $token = str_replace('_','/', $token);
        $token = str_replace('-','+', $token);
        $token = explode('.', $token);
        foreach ($token as $key => $value) $token[$key] = urlencode($value);
        $token = "da?jwt=$token[0].$token[1]&x-amz-customauthorizer-signature=$token[2]";
        $this->connectionSettings = $this->connectionSettings->setUsername($token);
    }

    private function curlRequest($url, $method, $contentType, $data, $token, &$responseCode){
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $headers = array();
        //if($contentType) $headers[] = 'Content-Type: '.$contentType;
        if($token) $headers[] = 'Authorization: Bearer '.urlencode($token);
        if(count($headers) > 0) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if($method == 'POST' || $method == 'PUT') curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        $result = curl_exec($ch);
        $returnValue = false;
        if($result !== false){
            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if($responseCode == 302) $returnValue = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            else if($responseCode >= 200 && $responseCode < 300) $returnValue = $result;
        }
        curl_close($ch);
	    return $returnValue;
    }
    
    private function calculateTokenExpireDate($expiresIn){
        $date = new DateTime();
        $date->add(new DateInterval('PT'.$expiresIn.'S'));
        HomegearNodeBase::log(4, 'token expires in: '. $expiresIn. ' so at: '. date_format($date, 'Y-m-d H:i:s'));
        return $date->getTimestamp();
    }

    public function logout(){
        HomegearNodeBase::log(4, 'logout');
        /*$this->accessToken = '';
        $this->refreshToken = '';
        $this->tokenExpiresAt = '';
        */return true;
    }

    private function login(){
        HomegearNodeBase::log(4, 'login');
        $this->accessToken = '';
        if($this->settings->clientId && $this->settings->password && $this->settings->email){
            $url = 'https://id.worx.com/oauth/token';
            $post = [
                'scope' => '*',
                'grant_type' => 'password',
                'client_id' => $this->settings->clientId,
                'username' => $this->settings->email,
                'password' => $this->settings->password
            ];
            $responseCode = 0;
            $result = $this->curlRequest($url, 'POST', 'application/json', $post, '', $responseCode);
            if($result === false || $responseCode == 0){
                HomegearNodeBase::log(2, 'Unknown error during login. (response code '.$responseCode.'): '.$result);
                return false;
            }
            else if($responseCode == 200){
                if(is_string($result)){
                    $token = json_decode($result, true);
                    $this->accessToken = $token['access_token'];
                    $this->refreshToken = $token['refresh_token'];
                    $this->tokenExpiresAt = $this->calculateTokenExpireDate($token['expires_in']);
                    $this->addTokenToConnectionSettings();
                    HomegearNodeBase::log(4, 'Successfully login. (response code '.$responseCode.'): '.$result);
                    return true;
                }
            }
            else{
                HomegearNodeBase::log(2, 'Error during login. (response code '.$responseCode.'): '.$result);
                return false;
            }
        }
        else{
            HomegearNodeBase::log(2, 'Node is not fully configured.');
        }
        return false;
    }

    private function refreshToken(){
        HomegearNodeBase::log(4, 'refreshToken');
        if(!$this->accessToken || !$this->refreshToken){
            login();
            return;
        }

        $url = 'https://id.worx.com/oauth/token';
        $post = [
            'scope' => '*',
            'grant_type' => 'refresh_token',
            'client_id' => $this->settings->clientId,
            'refresh_token' => $this->refreshToken,
        ]; 
        $responseCode = 0;
        $result = $this->curlRequest($url, 'POST', 'application/json', $post, '', $responseCode);
        if($result === false || $responseCode == 0){
            HomegearNodeBase::log(2, 'Unknown error refreshing token. (response code '.$responseCode.'): '.$result);
            return false;
        }
        else if($responseCode == 200){
            if(is_string($result)){
                $token = json_decode($result, true);
                $this->accessToken = $token['access_token'];
                $this->refreshToken = $token['refresh_token'];
                $this->tokenExpiresAt = $this->calculateTokenExpireDate($token['expires_in']);
                $this->addTokenToConnectionSettings();
                HomegearNodeBase::log(4, 'Successfully refreshed Token. (response code '.$responseCode.'): '.$result);
                return true;
            }
        }
        else{
            HomegearNodeBase::log(2, 'Error refreshing access token (response code '.$responseCode.'): '.$result);
            return false;
        }
    }

    public function checkToken(){
        HomegearNodeBase::log(4, 'checkToken');
        if(!$this->tokenExpiresAt) {
            return $this->login();
        }
        $date = new DateTime();
        $timeRemaining = $this->tokenExpiresAt - ($date->getTimestamp());

        HomegearNodeBase::log(4, 'The token can be used for '. $timeRemaining. 's until it need\'s to be refreshed');

        if($timeRemaining < 60){ // less than 1 min.
            HomegearNodeBase::log(4, 'The token can be used for less than 1 min., so we try to refresh');
            return $this->refreshToken();
        }
        return true;
    }

    public function getProductItems(){
        HomegearNodeBase::log(4, 'getProductItems');
        if(!$this->checkToken()) return false;
        
        $url = 'https://api.worxlandroid.com/api/v2/product-items';
        $responseCode = 0;
        $result = $this->curlRequest($url, 'GET', 'application/json', '', $this->accessToken, $responseCode);

        if($result === false || $responseCode == 0){
            HomegearNodeBase::log(2, 'Unknown error get product items. (response code '.$responseCode.'): '.$result);
            return false;
        }
        else if($responseCode == 200){
            if(is_string($result)){
                $this->productItems=json_decode($result, true);
                \Homegear\Homegear::nodeOutput($this->settings->nodeId, 0, array('payload' => $this->productItems));
                HomegearNodeBase::log(4, 'Successfully get product items. (response code '.$responseCode.'): '.$result);
                return true;
            }
        }
        else{
            HomegearNodeBase::log(2, 'Error get product items (response code '.$responseCode.'): '.$result);
            return false;
        }
    }
    /*
    'https://api.worxlandroid.com/api/v2/users/me'
    'https://api.worxlandroid.com/api/v2/products'
    'https://api.worxlandroid.com/api/v2/product-items'
    'https://api.worxlandroid.com/api/v2/product-items/{serialno}?status=1'
    'https://api.worxlandroid.com/api/v2/product-items/{serialno}/activity-log'
    */
}
