<?php

declare(strict_types=1);

include __DIR__ . '/../libs/WebHookModule.php';

class Prometheus extends WebHookModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, 'metrics');
    }

    // Source: https://stackoverflow.com/a/4356295/10288655
    private function generateRandomString($length) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
    
    public function Create()
    {

        //Never delete this line!
        parent::Create();
        
        // Hook
        $this->RegisterPropertyBoolean('HookEnabled', true);
        $this->RegisterPropertyString('Username', IPS_GetLicensee()); // Missing Hook prefix due to backwards compatibility
        $this->RegisterPropertyString('Password', $this->generateRandomString(16)); // Missing Hook prefix due to backwards compatibility
        
        // Push
        $this->RegisterPropertyBoolean('PushEnabled', false);
        $this->RegisterPropertyString('PushEndpointURL', 'https://prometheus-prod-01-eu-west-0.grafana.net/api/prom/push');
        $this->RegisterPropertyString('PushEndpointUsername', '467358');
        $this->RegisterPropertyString('PushEndpointPassword', '');
        $this->RegisterPropertyInteger('PushInterval', 10);
        
        $this->RegisterTimer("Push", 0, "PMM_Push(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        
        if ($this->ReadPropertyBoolean("Push")) {
            $this->SetTimerInterval("Push", $this->ReadPropertyInteger('PushInterval') * 1000);
        }
        else {
            $this->SetTimerInterval("Push", 0);
        }
    }

    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData()
    {

        //Never delete this line!
        parent::ProcessHookData();

        if (!$this->ReadPropertyBoolean('HookEnabled')) {
            header('HTTP/1.0 403 Forbidden');
            echo 'Forbidden';
            return;
        }
        
        if ($this->ReadPropertyString('Username') || $this->ReadPropertyString('Password')) {
            if (!isset($_SERVER['PHP_AUTH_USER'])) {
                $_SERVER['PHP_AUTH_USER'] = '';
            }
            if (!isset($_SERVER['PHP_AUTH_PW'])) {
                $_SERVER['PHP_AUTH_PW'] = '';
            }

            if (($_SERVER['PHP_AUTH_USER'] != $this->ReadPropertyString('Username')) || ($_SERVER['PHP_AUTH_PW'] != $this->ReadPropertyString('Password'))) {
                header('WWW-Authenticate: Basic Realm="Prometheus WebHook"');
                header('HTTP/1.0 401 Unauthorized');
                echo 'Authorization required';
                return;
            }
        }

        include __DIR__ . '/metrics.php';
    }
    
    public function Push() {
        
        ob_start();
        include __DIR__ . '/metrics.php';
        $data = ob_get_contents();
        ob_end_clean();
        
        $authentication = base64_encode($this->ReadPropertyString('PushEndpointUsername') . ":" . $this->ReadPropertyString('PushEndpointPassword'));
        
        // Wir müssen die Daten noch passend "verpacken"
        // https://prometheus.io/docs/prometheus/latest/storage/#remote-storage-integrations
        // The read and write protocols both use a snappy-compressed protocol buffer encoding over HTTP
        // https://github.com/prometheus/prometheus/blob/main/prompb/remote.proto
        
        $options = [
            'http' => [
                'header'        => "Authorization: Basic " . $authentication . "\r\nContent-Type: application/json\r\n",
                'method'        => 'POST',
                'content'       => $data,
                'ignore_errors' => true
            ]
        ];
        
        $context = stream_context_create($options);
        
        echo file_get_contents($this->ReadPropertyString('PushEndpointURL'), false, $context);
    }
}
