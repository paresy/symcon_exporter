<?php

declare(strict_types=1);

//Configuration
$export_cpu = false; //Enabling this will make this script run at least 1 second

//We reuse this information
$uc_id = IPS_GetInstanceListByModuleID('{B69010EA-96D5-46DF-B885-24821B8C8DBD}')[0];

//This will give a nicer output in the browser
header('Content-Type: text/plain');

//Version information
addMetric('symcon_info', 'General version information', 'gauge', [
    [
        'platform' => IPS_GetKernelPlatform(),
        'version'  => IPS_GetKernelVersion(),
        'revision' => IPS_GetKernelRevision(),
        'date'     => date('d.m.Y', IPS_GetKernelDate()),
        'started'  => IPS_GetKernelStartTime(),
        'value'    => 1
    ]
]);

//Simple metrics for objects
addMetric('symcon_instances', 'Instance count', 'gauge', count(IPS_GetInstanceList()));
addMetric('symcon_variables', 'Variable count', 'gauge', count(IPS_GetVariableList()));
addMetric('symcon_scripts', 'Script count', 'gauge', count(IPS_GetScriptList()));
addMetric('symcon_events', 'Event count', 'gauge', count(IPS_GetEventList()));
addMetric('symcon_medias', 'Media count', 'gauge', count(IPS_GetMediaList()));
addMetric('symcon_links', 'Link count', 'gauge', count(IPS_GetLinkList()));

//Kernel Message metrics
$logMessageStatistics = UC_GetLogMessageStatistics($uc_id);
addMetric('symcon_messages_default', 'Count of default messages', 'counter', $logMessageStatistics['MessageDefaultCount']);
addMetric('symcon_messages_success', 'Count of success messages', 'counter', $logMessageStatistics['MessageSuccessCount']);
addMetric('symcon_messages_notify', 'Count of notify messages', 'counter', $logMessageStatistics['MessageNotifyCount']);
addMetric('symcon_messages_warning', 'Count of warning messages', 'counter', $logMessageStatistics['MessageWarningCount']);
addMetric('symcon_messages_error', 'Count of error messages', 'counter', $logMessageStatistics['MessageErrorCount']);
addMetric('symcon_messages_custom', 'Count of custom messages', 'counter', $logMessageStatistics['MessageCustomCount']);

//Kernel Message Queue metrics, IP-Symcon 5.4+
if (function_exists('UC_GetKernelStatistics')) {
    $kernelStatistics = UC_GetKernelStatistics($uc_id);
    addMetric('symcon_messagequeue_total', 'Total count of kernel messages', 'counter', $kernelStatistics['MessageCounter']);
    addMetric('symcon_messagequeue_slow_total', 'Total count of kernel messages that took longer than 50ms', 'counter', $kernelStatistics['MessageSlowCounter']);
    addMetric('symcon_messagequeue_current_size', 'Count of messages currently queued', 'gauge', $kernelStatistics['MessageQueueSize']);
    addMetric('symcon_messagequeue_current_delay', 'Delay to the oldest queued message', 'gauge', $kernelStatistics['MessageQueueDelay']);
    addMetric('symcon_phpqueue_current_size', 'Count of PHP requests current queued', 'gauge', $kernelStatistics['RequestQueueSize']);
}

//Event metrics, IP-Symcon 5.4+
if (function_exists('UC_GetEventStatistics')) {
    $eventStatistics = UC_GetEventStatistics($uc_id);
    addMetric('symcon_cyclicupdate_total', 'Total updates of cyclic events', 'counter', $eventStatistics['CyclicUpdateCount']);
    addMetric('symcon_triggerupdate_total', 'Total updates of trigger events', 'counter', $eventStatistics['TriggerUpdateCount']);
}

//WebServer Connections
if (function_exists('IPS_GetActiveConnections')) {
    addMetric('symcon_server_connections_active', 'Active WebSocket or RTSP connections', 'gauge', IPS_GetActiveConnections());
    addMetric('symcon_server_connections_logged', 'Logged connections', 'gauge', count(IPS_GetConnectionList()));
} else {
    addMetric('symcon_server_websocket_active', 'Active WebSocket or RTSP connections', 'gauge', IPS_GetActiveWebSocketConnections());
    addMetric('symcon_server_proxy_active', 'Active WebSocket or RTSP connections', 'gauge', IPS_GetActiveProxyConnections());
    $loggedWebSocket = 0;
    $loggedProxy = 0;
    foreach (IPS_GetConnectionList() as $connection) {
        switch ($connection['ConnectionType']) {
            case 'WebSocket':
                $loggedWebSocket++;
                break;
            case 'Proxy':
                $loggedProxy++;
                break;
        }
    }
    addMetric('symcon_server_websocket_logged', 'Logged WebSocket connections', 'gauge', $loggedWebSocket);
    addMetric('symcon_server_proxy_logged', 'Logged Proxy connections', 'gauge', $loggedProxy);
}

//Detailed count of bytes per connection queue
$queueBytesWebSocket = [];
$queueBytesProxy = [];
foreach (IPS_GetConnectionList() as $connection) {
    switch ($connection['ConnectionType']) {
        case 'WebSocket':
            $queueBytesWebSocket[] = [
                'remote' => $connection['Remote'],
                'value'  => $connection['QueueBytes']
            ];
            break;
        case 'Proxy':
            $queueBytesProxy[] = [
                'remote' => $connection['Remote'],
                'value'  => $connection['QueueBytes']
            ];
            break;
    }
}
if (count($queueBytesWebSocket) > 0) {
    addMetric('symcon_server_websocket_queue_bytes', 'Bytes in WebSocket connections queue', 'gauge', $queueBytesWebSocket);
}
if (count($queueBytesProxy) > 0) {
    addMetric('symcon_server_proxy_queue_bytes', 'Bytes in Proxy connections queue', 'gauge', $queueBytesProxy);
}

//Script Thread metrics
$scriptThreadList = IPS_GetScriptThreadList();
$scriptThreads = [];
$scriptThreadsInUse = 0;
$scriptRequests = [];
$scriptDurationMin = [];
$scriptDurationAvg = [];
$scriptDurationMax = [];
foreach ($scriptThreadList as $scriptThread) {
    $scriptThread = IPS_GetScriptThread($scriptThread);
    $scriptThreads[] = $scriptThread;
    if ($scriptThread['StartTime'] > 0) {
        $scriptThreadsInUse++;
    }
    $scriptRequests[] = [
        'id'    => $scriptThread['ThreadID'],
        'value' => $scriptThread['ExecuteCount']
    ];
    //Avilable with IP-Symcon 5.4+
    if (isset($scriptThread['ExecutionMin'])) {
        $scriptDurationMin[] = [
            'id'    => $scriptThread['ThreadID'],
            'value' => $scriptThread['ExecutionMin']
        ];
    }
    //Avilable with IP-Symcon 5.4+
    if (isset($scriptThread['ExecutionAvg'])) {
        $scriptDurationAvg[] = [
            'id'    => $scriptThread['ThreadID'],
            'value' => $scriptThread['ExecutionAvg']
        ];
    }
    //Avilable with IP-Symcon 5.4+
    if (isset($scriptThread['ExecutionMax'])) {
        $scriptDurationMax[] = [
            'id'    => $scriptThread['ThreadID'],
            'value' => $scriptThread['ExecutionMax']
        ];
    }
}
addMetric('symcon_php_threads_maximum', 'Count of available PHP threads', 'gauge', count($scriptThreads));
addMetric('symcon_php_threads_inuse', 'Count of PHP threads currently in use', 'gauge', $scriptThreadsInUse);
addMetric('symcon_php_requests', 'Request count per PHP thread', 'counter', $scriptRequests);

//Requires IP-Symcon 5.4+
if (count($scriptDurationMin) > 0) {
    addMetric('symcon_php_duration_min', 'Request duration (min) per PHP thread', 'gauge', $scriptDurationMin);
}
if (count($scriptDurationAvg) > 0) {
    addMetric('symcon_php_duration_avg', 'Request duration (avg) per PHP thread', 'gauge', $scriptDurationAvg);
}
if (count($scriptDurationMax) > 0) {
    addMetric('symcon_php_duration_max', 'Request duration (max) per PHP thread', 'gauge', $scriptDurationMax);
}

//Script Sender metrics, IP-Symcon 5.4+
if (function_exists('UC_GetScriptSenderList')) {
    $scriptSenderList = UC_GetScriptSenderList($uc_id);
    $senderList = [];
    foreach ($scriptSenderList as $scriptSender) {
        $senderList[] = [
            'sender' => $scriptSender['Sender'],
            'value'  => $scriptSender['Count']
        ];
    }
    addMetric('symcon_php_sender', 'Request count per PHP sender', 'counter', $senderList);
}

//Process usage metrics
$pi = Sys_GetProcessInfo();
addMetric('symcon_process_handles', 'Count of used handles', 'gauge', $pi['IPS_HANDLECOUNT']);
addMetric('symcon_process_threads', 'Count of used threads', 'gauge', $pi['IPS_NUMTHREADS']);
addMetric('symcon_process_memory_virtualsize', 'Virtual size of symcon process', 'gauge', $pi['IPS_VIRTUALSIZE']);
addMetric('symcon_process_memory_workingsetsize', 'Virtual size of symcon process', 'gauge', $pi['IPS_WORKINGSETSIZE']);
addMetric('symcon_process_memory_pagefile', 'Virtual size of symcon process', 'gauge', $pi['IPS_PAGEFILE']);
addMetric('symcon_total_processes', 'Total count of processes on this system', 'gauge', $pi['PROCESSCOUNT']);

//System usage metrics (we try to use similar names as the "prometheus node exporter")
if ($export_cpu) {
    $ci = Sys_GetCPUInfo();
    $cpuUsage = [];
    for ($i = 0; $i < count($ci) - 1; $i++) {
        $cpuUsage[] = [
            'cpu'   => $i,
            'value' => $ci['CPU_' . $i]
        ];
    }
    addMetric('symcon_cpu_usage_total', 'CPU usage per core in percent', 'gauge', $cpuUsage);
}

if (PHP_OS == 'Linux') {
    $load = sys_getloadavg();
    addMetric('symcon_cpu_load1', 'CPU load avergage (1 minute)', 'gauge', $load[0]);
    addMetric('symcon_cpu_load5', 'CPU load avergage (5 minutes)', 'gauge', $load[1]);
    addMetric('symcon_cpu_load15', 'CPU load avergage (15 minutes)', 'gauge', $load[2]);
}

$ni = Sys_GetNetworkInfo();
$networkRx = [];
$networkTx = [];
$networkSpeed = [];
foreach ($ni as $x) {
    $networkRx[] = [
        'device' => $x['Description'],
        'value'  => $x['InTotal']
    ];
    $networkTx[] = [
        'device' => $x['Description'],
        'value'  => $x['OutTotal']
    ];
    $networkSpeed[] = [
        'device' => $x['Description'],
        'value'  => $x['Speed']
    ];
}
addMetric('symcon_network_receive_bytes_total', 'Network device statistic receive_bytes', 'counter', $networkRx);
addMetric('symcon_network_transmit_bytes_total', 'Network device statistic transmit_bytes', 'counter', $networkTx);
addMetric('symcon_network_speed', 'Network device link speed in MBit', 'gauge', $networkSpeed);

$mi = Sys_GetMemoryInfo();
addMetric('symcon_memory_physical_total_bytes', 'Total physical memory', 'gauge', $mi['TOTALPHYSICAL']);
addMetric('symcon_memory_physical_available_bytes', 'Available physical memory', 'gauge', $mi['AVAILPHYSICAL']);
addMetric('symcon_memory_swap_total_bytes', 'Total swap memory', 'gauge', $mi['TOTALPAGEFILE']);
addMetric('symcon_memory_swap_available_bytes', 'Available swap memory', 'gauge', $mi['AVAILPAGEFILE']);
addMetric('symcon_memory_virtual_total_bytes', 'Total virtual memory', 'gauge', $mi['TOTALVIRTUAL']);
addMetric('symcon_memory_virtual_available_bytes', 'Available virtual memory', 'gauge', $mi['AVAILVIRTUAL']);

$hi = Sys_GetHardDiskInfo();
$filesystemTotal = [];
$filesystemAvailable = [];
foreach ($hi as $x) {
    $filesystemTotal[] = [
        'device' => str_replace(':\\', '', $x['LETTER']), //Prometheus does not like the :\ on Windows
        'value'  => $x['TOTAL']
    ];
    $filesystemAvailable[] = [
        'device' => str_replace(':\\', '', $x['LETTER']), //Prometheus does not like the :\ on Windows
        'value'  => $x['FREE']
    ];
}
addMetric('symcon_filesystem_total_bytes', 'Total diskspace', 'gauge', $filesystemTotal);
addMetric('symcon_filesystem_available_bytes', 'Available diskspace', 'gauge', $filesystemAvailable);

//Helper functions
function addMetric(string $name, string $help, string $type, $values)
{
    echo '# HELP ' . $name . ' ' . $help . "\n";
    echo '# TYPE ' . $name . ' ' . $type . "\n";
    if (is_array($values)) {
        if (count($values) == 0) {
            die('Error ' . $name . ' has no values');
        }
        foreach ($values as $value) {
            if (!is_array($value) || count($value) == 0 || !isset($value['value'])) {
                die('Error ' . $name . ' is missing value field in array value');
            }
            //we only have some extra fields if the array has more than 1 element
            if (count($value) > 1) {
                $fields = [];
                foreach ($value as $key => $val) {
                    if ($key != 'value') {
                        $fields[] = $key . '="' . $val . '"';
                    }
                }
                $fields = '{' . implode(',', $fields) . '}';
            }
            echo $name . $fields . ' ' . number_format($value['value'], is_float($value['value']) ? 6 : 0, '.', '') . "\n";
        }
    } else {
        echo $name . ' ' . number_format($values, is_float($values) ? 6 : 0, '.', '') . "\n";
    }
}
