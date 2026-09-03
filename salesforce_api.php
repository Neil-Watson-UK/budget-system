<?php
// salesforce_api.php - Salesforce integration
class SalesforceClient {
    private $clientId;
    private $clientSecret;
    private $username;
    private $password;
    private $securityToken;
    private $accessToken;
    private $instanceUrl;
    
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
            $this->instanceUrl = $response['instance_url'] ?? 'https://login.salesforce.com';
            return true;
        }
        
        return false;
    }

    public function query($soql) {
        if (!$this->accessToken && !$this->authenticate()) {
            return [];
        }
        $query = urlencode($soql);
        $base = rtrim($this->instanceUrl ?: 'https://login.salesforce.com', '/');
        $url = $base . "/services/data/v55.0/query?q={$query}";
        $ch = curl_init($url);
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

    public function searchAccount($name) {
        $soql = "SELECT Id, Name, AccountNumber FROM Account WHERE Name LIKE '%" . addslashes($name) . "%' LIMIT 10";
        return $this->query($soql);
    }
}
?>