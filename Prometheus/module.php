<?php

declare(strict_types=1);

include __DIR__ . '/../libs/WebHookModule.php';

class Prometheus extends WebHookModule
{
    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, 'metrics');
    }

    public function Create()
    {

        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');
    }

    /**
     * This function will be called by the hook control. Visibility should be protected!
     */
    protected function ProcessHookData()
    {

        //Never delete this line!
        parent::ProcessHookData();

        if ((IPS_GetProperty($this->InstanceID, 'Username') != '') || (IPS_GetProperty($this->InstanceID, 'Password') != '')) {
            if (!isset($_SERVER['PHP_AUTH_USER'])) {
                $_SERVER['PHP_AUTH_USER'] = '';
            }
            if (!isset($_SERVER['PHP_AUTH_PW'])) {
                $_SERVER['PHP_AUTH_PW'] = '';
            }

            if (($_SERVER['PHP_AUTH_USER'] != IPS_GetProperty($this->InstanceID, 'Username')) || ($_SERVER['PHP_AUTH_PW'] != IPS_GetProperty($this->InstanceID, 'Password'))) {
                header('WWW-Authenticate: Basic Realm="Prometheus WebHook"');
                header('HTTP/1.0 401 Unauthorized');
                echo 'Authorization required';
                return;
            }
        }

        ob_start();
        include __DIR__ . '/metrics.php';
        $data = ob_get_contents();
        ob_end_clean();

        //Add gzip compression
        if (strstr($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip')) {
            $compressed = gzencode($data);
            header('Content-Encoding: gzip');
            header('Content-Length: ' . strlen($compressed));
            echo $compressed;
        } else {
            header('Content-Length: ' . strlen($data));
            echo $data;
        }
    }
}
