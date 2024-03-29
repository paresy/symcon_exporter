<?php

declare(strict_types=1);

include 'types.php';

//We reuse this information
$uc_id = IPS_GetInstanceListByModuleID('{B69010EA-96D5-46DF-B885-24821B8C8DBD}')[0];
$cc_id = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];

//This will give a nicer output in the browser
header('Content-Type: text/plain');

//Version information
$info = [
    'platform' => IPS_GetKernelPlatform(),
    'version'  => IPS_GetKernelVersion(),
    'revision' => IPS_GetKernelRevision(),
    'date'     => date('d.m.Y', IPS_GetKernelDate()),
    'started'  => IPS_GetKernelStartTime(),
    'value'    => 1
];
if (function_exists('IPS_GetKernelArchitecture')) {
    $info['architecture'] = IPS_GetKernelArchitecture();
}
if (function_exists('IPS_GetUpdateChannel')) {
    $channel = @IPS_GetUpdateChannel();
    // The function might fail and we do not want our metrics to fail in this situation
    if ($channel) {
        $info['channel'] = IPS_GetUpdateChannel();
    } else {
        $info['channel'] = 'Unknown';
    }
}
if (function_exists('IPS_GetSystemLanguage')) {
    $info['language'] = IPS_GetSystemLanguage();
}
addMetric('symcon_info', 'General version information', 'gauge', [$info]);

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

//Instance Message Queue metrics, IP-Symcon 6.1+
// + MessageQueueWatch must be enabled for this function to return any results
if (function_exists('IPS_GetInstanceMessageStatistics') && IPS_GetOption('MessageQueueWatch')) {
    $lst = IPS_GetInstanceMessageStatistics();

    // Remove any missing instances
    $lst = array_filter($lst, function ($item)
    {
        return IPS_InstanceExists($item['InstanceID']);
    });

    usort($lst, function ($a, $b)
    {
        return $b['Duration'] - $a['Duration'];
    });
    $topDuration = array_slice($lst, 0, 10);
    $result = [];
    foreach ($topDuration as $item) {
        // Instances without any processing time are of no value
        if ($item['Duration'] == 0) {
            continue;
        }

        $result[] = [
            'id'     => $item['InstanceID'],
            'value'  => $item['Duration'],
            'name'   => IPS_InstanceExists($item['InstanceID']) ? IPS_GetName($item['InstanceID']) : '#' . $item['InstanceID'],
        ];
    }
    if (count($result) > 0) {
        addMetric('symcon_messagequeue_instance_duration_total', 'Total duration spend in processing messages per instance (Top 10)', 'counter', $result);
    }

    usort($lst, function ($a, $b)
    {
        return $b['MaxDuration'] - $a['MaxDuration'];
    });
    $topMaxDuration = array_slice($lst, 0, 10);
    $result = [];
    foreach ($topMaxDuration as $item) {
        // Instances without any processing time are of no value
        if ($item['MaxDuration'] == 0) {
            continue;
        }

        $result[] = [
            'id'     => $item['InstanceID'],
            'value'  => $item['MaxDuration'],
            'name'   => IPS_InstanceExists($item['InstanceID']) ? IPS_GetName($item['InstanceID']) : '#' . $item['InstanceID'],
        ];
    }
    if (count($result) > 0) {
        addMetric('symcon_messagequeue_instance_duration_max', 'Maximum duration spend in processing one message per instance (Top 10)', 'gauge', $result);
    }

    // Make a new metric that will sum up stats according to splitters
    $parentLst = [];
    foreach ($lst as $item) {
        $connectionID = 0;
        if (IPS_InstanceExists($item['InstanceID'])) {
            IPS_GetInstance($item['InstanceID'])['ConnectionID'];
        }
        if ($connectionID != 0) {
            if (!isset($parentLst[$connectionID])) {
                $parentLst[$connectionID] = $item['Duration'];
            } else {
                $parentLst[$connectionID] += $item['Duration'];
            }
        }
    }
    $lst = [];
    foreach ($parentLst as $id => $duration) {
        $lst[] = [
            'InstanceID' => $id,
            'Duration'   => $duration
        ];
    }
    usort($lst, function ($a, $b)
    {
        return $b['Duration'] - $a['Duration'];
    });
    $topParentDurationSum = array_slice($lst, 0, 10);
    $result = [];
    foreach ($topParentDurationSum as $item) {
        // Instances without any processing time are of no value
        if ($item['Duration'] == 0) {
            continue;
        }

        $result[] = [
            'id'     => $item['InstanceID'],
            'value'  => $item['Duration'],
            'name'   => IPS_InstanceExists($item['InstanceID']) ? IPS_GetName($item['InstanceID']) : '#' . $item['InstanceID'],
        ];
    }
    if (count($result) > 0) {
        addMetric('symcon_messagequeue_parent_instance_duration_total', 'Total duration spend in processing sum if all messages per parent instance (Top 10)', 'counter', $result);
    }
}

//Instance Message Queue size, IP-Symcon 6.2+
if (function_exists('IPS_GetInstanceMessageQueueSize')) {
    addMetric('symcon_messagequeue_instance_current_size', 'Count of messages currently queued for instances', 'gauge', IPS_GetInstanceMessageQueueSize());
}

//Instance Data Flow metrics, IP-Symcon 6.1+
// + DataFlowWatch must be enabled for this function to return any results
if (function_exists('IPS_GetInstanceDataFlowStatistics') && IPS_GetOption('DataFlowWatch')) {
    $lst = IPS_GetInstanceDataFlowStatistics();

    // Remove any missing instances
    $lst = array_filter($lst, function ($item)
    {
        return IPS_InstanceExists($item['InstanceID']);
    });

    $analyzeFlow = function ($lst, $type, $ident, $direction)
    {
        $lst = array_filter($lst, function ($item) use ($type)
        {
            return IPS_GetInstance($item['InstanceID'])['ModuleInfo']['ModuleType'] == $type;
        });

        usort($lst, function ($a, $b) use ($direction)
        {
            return $b[$direction . 'Duration'] - $a[$direction . 'Duration'];
        });
        $topDuration = array_slice($lst, 0, 10);
        $result = [];
        foreach ($topDuration as $item) {
            // Instances without any processing time are of no value
            if ($item[$direction . 'Duration'] == 0) {
                continue;
            }

            $result[] = [
                'id'     => $item['InstanceID'],
                'value'  => $item[$direction . 'Duration'],
                'name'   => IPS_InstanceExists($item['InstanceID']) ? IPS_GetName($item['InstanceID']) : '#' . $item['InstanceID'],
            ];
        }
        if (count($result) > 0) {
            addMetric('symcon_dataflow_' . strtolower($direction) . '_instance_' . $ident . '_duration_total', 'Total duration spend in processing packets per ' . $ident . ' instance (Top 10)', 'counter', $result);
        }

        usort($lst, function ($a, $b) use ($direction)
        {
            return $b[$direction . 'MaxDuration'] - $a[$direction . 'MaxDuration'];
        });
        $topMaxDuration = array_slice($lst, 0, 10);
        $result = [];
        foreach ($topMaxDuration as $item) {
            // Instances without any processing time are of no value
            if ($item[$direction . 'MaxDuration'] == 0) {
                continue;
            }

            $result[] = [
                'id'     => $item['InstanceID'],
                'value'  => $item[$direction . 'MaxDuration'],
                'name'   => IPS_InstanceExists($item['InstanceID']) ? IPS_GetName($item['InstanceID']) : '#' . $item['InstanceID'],
            ];
        }
        if (count($result) > 0) {
            addMetric('symcon_dataflow_' . strtolower($direction) . '_instance_' . $ident . '_duration_max', 'Maximum duration spend in processing one packet per ' . $ident . ' instance (Top 10)', 'gauge', $result);
        }
    };

    // We want to split instances into io/splitter/device
    $analyzeFlow($lst, MODULETYPE_DEVICE, 'device', 'Tx');
    $analyzeFlow($lst, MODULETYPE_DEVICE, 'device', 'Rx');
    $analyzeFlow($lst, MODULETYPE_SPLITTER, 'splitter', 'Tx');
    $analyzeFlow($lst, MODULETYPE_SPLITTER, 'splitter', 'Rx');
    $analyzeFlow($lst, MODULETYPE_IO, 'io', 'Tx');
    $analyzeFlow($lst, MODULETYPE_IO, 'io', 'Rx');
}

//Event metrics, IP-Symcon 5.4+
if (function_exists('UC_GetEventStatistics')) {
    $eventStatistics = UC_GetEventStatistics($uc_id);
    addMetric('symcon_cyclicupdate_total', 'Total updates of cyclic events', 'counter', $eventStatistics['CyclicUpdateCount']);
    addMetric('symcon_triggerupdate_total', 'Total updates of trigger events', 'counter', $eventStatistics['TriggerUpdateCount']);
}

//Message SenderID metrics, IP-Symcon 6.1+
if (function_exists('UC_GetMessageSenderIDList')) {
    $lst = UC_GetMessageSenderIDList($uc_id);
    usort($lst, function ($a, $b)
    {
        return $b['Count'] - $a['Count'];
    });
    $lst = array_slice($lst, 0, 10);
    $result = [];
    foreach ($lst as $item) {
        $result[] = [
            'id'     => $item['SenderID'],
            'value'  => $item['Count'],
            'name'   => IPS_ObjectExists($item['SenderID']) ? IPS_GetName($item['SenderID']) : '#' . $item['SenderID'],
        ];
    }
    addMetric('symcon_message_sender_id_total', 'Total messages by sender id (Top 10)', 'counter', $result);
}

//Message SenderID by size metrics, IP-Symcon 6.1+
if (function_exists('UC_GetMessageSenderIDSizeList')) {
    $lst = UC_GetMessageSenderIDSizeList($uc_id);
    usort($lst, function ($a, $b)
    {
        return $b['Size'] - $a['Size'];
    });
    $lst = array_slice($lst, 0, 10);
    $result = [];
    foreach ($lst as $item) {
        $result[] = [
            'id'     => $item['SenderID'],
            'value'  => $item['Size'],
            'name'   => IPS_ObjectExists($item['SenderID']) ? IPS_GetName($item['SenderID']) : '#' . $item['SenderID'],
        ];
    }
    addMetric('symcon_message_sender_id_size', 'Total size of messages by sender id (Top 10)', 'counter', $result);
}

//Message Type metrics, IP-Symcon 6.1+
if (function_exists('UC_GetMessageTypeList')) {
    $lst = UC_GetMessageTypeList($uc_id);
    usort($lst, function ($a, $b)
    {
        return $b['Count'] - $a['Count'];
    });
    $lst = array_slice($lst, 0, 10);
    $result = [];
    foreach ($lst as $item) {
        $result[] = [
            'type'   => $item['Message'],
            'value'  => $item['Count'],
            'name'   => messageToString($item['Message']),
        ];
    }
    addMetric('symcon_message_type_total', 'Total messages grouped by type (Top 10)', 'counter', $result);
}

//WebServer Connections
if (function_exists('IPS_GetActiveConnections')) {
    addMetric('symcon_server_connections_active', 'Active WebSocket or RTSP connections', 'gauge', IPS_GetActiveConnections());
    addMetric('symcon_server_connections_logged', 'Logged connections', 'gauge', count(IPS_GetConnectionList()));
} else {
    addMetric('symcon_server_webserver_active', 'Active HTTP/MJPEG/RTSP connections', 'gauge', IPS_GetActiveWebServerConnections());
    addMetric('symcon_server_websocket_active', 'Active WebSocket connections', 'gauge', IPS_GetActiveWebSocketConnections());
    addMetric('symcon_server_proxy_active', 'Active MJPEG/RTSP connections', 'gauge', IPS_GetActiveProxyConnections());
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

//Connect Traffic usage, IP-Symcon 6.3+
if (function_exists('CC_GetTrafficCounter')) {
    $tc = CC_GetTrafficCounter($cc_id);
    addMetric('symcon_connect_limit_bytes', 'Used Bytes for Connect Service (Limit)', 'gauge', $tc['LimitCounter']);
    addMetric('symcon_connect_local_bytes', 'Used Bytes for Connect Service (Local)', 'gauge', $tc['LocalCounter']);
    addMetric('symcon_connect_remote_bytes', 'Used Bytes for Connect Service (Remote)', 'gauge', $tc['RemoteCounter']);

    $ts = CC_GetTrafficStatistics($cc_id);

    $urlToArea = function ($url)
    {
        if (strstr($url, '/api/')) {
            return 'JSON-RPC';
        }
        if (strstr($url, '/proxy/')) {
            $id = intval(substr($url, strrpos($url, '/') + 1));
            if (IPS_ObjectExists($id)) {
                return 'Stream (' . IPS_GetName($id) . ')';
            } else {
                return 'Stream (#' . $id . ')';
            }
        }
        if (strstr($url, '/hook/')) {
            $last = substr($url, strrpos($url, '/') + 1);
            $id = intval($last);
            if ($id > 10000) {
                if (IPS_ObjectExists($id)) {
                    return 'Hook (' . IPS_GetName($id) . ')';
                } else {
                    return 'Hook (#' . $id . ')';
                }
            }
            return 'Hook (' . $last . ')';
        }
        if (strstr($url, '/user/')) {
            return 'User-Folder';
        }
        return 'Other';
    };

    // Map Traffic into Categories
    $traffic = [];
    foreach ($ts as $file) {
        $area = $urlToArea($file['Url']);
        if (isset($traffic[$area])) {
            $traffic[$area] += $file['LimitCounter'];
        } else {
            $traffic[$area] = $file['LimitCounter'];
        }
    }

    $result = [];
    foreach ($traffic as $key => $value) {
        $result[] = [
            'area'   => $key,
            'value'  => $value,
        ];
    }
    if (count($result) > 0) {
        addMetric('symcon_connect_usage_area_bytes', 'Used Bytes for Connect Service by area (Only Top contributors)', 'gauge', $result);
    }
}

//Script Thread metrics
$scriptThreadList = IPS_GetScriptThreadList();
$scriptThreads = [];
$scriptThreadsInUse = 0;
$scriptRequests = [];
$scriptDurationMin = [];
$scriptDurationAvg = [];
$scriptDurationMax = [];
$scriptPeakMemoryUsage = [];
$scriptMemoryCleanups = [];
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
    //Available with IP-Symcon 5.4+
    if (isset($scriptThread['ExecutionMin'])) {
        $scriptDurationMin[] = [
            'id'    => $scriptThread['ThreadID'],
            'value' => $scriptThread['ExecutionMin']
        ];
    }
    //Available with IP-Symcon 5.4+
    if (isset($scriptThread['ExecutionAvg'])) {
        $scriptDurationAvg[] = [
            'id'    => $scriptThread['ThreadID'],
            'value' => $scriptThread['ExecutionAvg']
        ];
    }
    //Available with IP-Symcon 5.4+
    if (isset($scriptThread['ExecutionMax'])) {
        $scriptDurationMax[] = [
            'id'    => $scriptThread['ThreadID'],
            'value' => $scriptThread['ExecutionMax']
        ];
    }
    //Available with IP-Symcon 6.3+
    if (isset($scriptThread['PeakMemoryUsage'])) {
        $scriptPeakMemoryUsage[] = [
            'id'    => $scriptThread['ThreadID'],
            'value' => $scriptThread['PeakMemoryUsage']
        ];
    }
    //Available with IP-Symcon 6.3+
    if (isset($scriptThread['MemoryCleanups'])) {
        $scriptMemoryCleanups[] = [
            'id'    => $scriptThread['ThreadID'],
            'value' => $scriptThread['MemoryCleanups']
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
if (count($scriptPeakMemoryUsage) > 0) {
    addMetric('symcon_php_memory_usage_peak', 'Request peak memory usage per PHP thread', 'gauge', $scriptPeakMemoryUsage);
}
if (count($scriptMemoryCleanups) > 0) {
    addMetric('symcon_php_memory_cleanups', 'Request memory cleanups per PHP thread', 'counter', $scriptMemoryCleanups);
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

//Enabling this on non Window systems will make this script run at least 1 second
//Therefore we just enable it on Windows and use the Load average indicator on the remaining platforms
if (PHP_OS_FAMILY == 'Windows') {
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

//This is only available on non Windows systems
if (PHP_OS_FAMILY != 'Windows') {
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
                        $val = strval($val);
                        $val = str_replace('\\', '\\\\', $val);
                        $val = str_replace('"', '\\"', $val);
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
