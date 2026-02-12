<?php
// salesforce_api.php - Salesforce integration
class SalesforceClient {
    private $clientId;
    private $clientSecret;
    private $username;
    private $password;
    private $securityToken;
    private $accessToken;
    
    public function __construct($config) {
        $this->clientId = $config['client_id'];
        $this->clientSecret = $config['client_secret'];
        $this->username = $config['username'];
        $this->password = $config['password'] . $config['security_token'];
    }
    
    public function authenticate() {
        $ch = curl_init('https://login.salesforce.com/services/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'password',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username' => $this->username,
                'password' => $this->password
            ])
        ]);
        
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            return true;
        }
        
        return false;
    }
    
    public function searchAccount($name) {
        $query = urlencode("SELECT Id, Name, AccountNumber FROM Account WHERE Name LIKE '%{$name}%' LIMIT 10");
        $ch = curl_init("https://yourinstance.salesforce.com/services/data/v55.0/query?q={$query}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->accessToken}",
                "Content-Type: application/json"
            ]
        ]);
        
        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        return $response['records'] ?? [];
    }
}
?>