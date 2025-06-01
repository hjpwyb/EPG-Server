<?php
/**
 * @file index.php
 * @brief 主页处理脚本
 *
 * 该脚本处理来自客户端的请求，根据查询参数获取指定日期和频道的节目信息，
 * 并从 SQLite 数据库中提取或返回默认数据。
 *
 * 作者: Tak
 * GitHub: https://github.com/taksssss/EPG-Server
 */

// 引入公共脚本
require_once 'public.php';

// 解析参数，替换多余的 '?' 为 '&'，并特殊处理 5+ 频道
$query = $_SERVER['QUERY_STRING'] ?? '';
$query = str_replace(['?', '5+'], ['&', '5%2B'], $query);
parse_str($query, $query_params);

// 判断是否允许访问
function isAllowed($value, array $allowedList, int $range, bool $isLive): bool
{
    foreach ($allowedList as $allowed) {
        if (strpos($allowed, 'regex:') === 0) {
            if (@preg_match(substr($allowed, 6), $value)) return true;
        } elseif ($value === $allowed) {
            return true;
        }
    }
    return ($range === 2 && $isLive) || ($range === 1 && !$isLive);
}

// 获取参数和配置
$tokenRange = $Config['token_range'] ?? 1;
$userAgentRange = $Config['user_agent_range'] ?? 0;
$live = in_array($query_params['type'] ?? '', ['m3u', 'txt']);
$accessDenied = false;

// 验证token
if ($tokenRange !== 0) {
    $allowedTokens = array_map('trim', explode(PHP_EOL, $Config['token'] ?? ''));
    $token = $query_params['token'] ?? '';
    if (!isAllowed($token, $allowedTokens, $tokenRange, (bool)$live)) {
        $accessDenied = true;
        $denyMessage = '访问被拒绝：无效Token。';
    }
}

// 验证 User-Agent
if (!$accessDenied && $userAgentRange !== 0) {
    $allowedUserAgents = array_map('trim', explode(PHP_EOL, $Config['user_agent'] ?? ''));
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!isAllowed($userAgent, $allowedUserAgents, $userAgentRange, (bool)$live)) {
        $accessDenied = true;
        $denyMessage = '访问被拒绝：无效UA。';
    }
}

// 获取真实 IP 地址（防止反代影响）
function getClientIp() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

$clientIp = getClientIp();

// 验证 IP 黑白名单
if (!$accessDenied && !empty($Config['ip_list_mode'])) {
    $mode = (int)$Config['ip_list_mode']; // 1白名单，2黑名单
    $file = __DIR__ . '/data/' . ($mode === 1 ? 'ipWhiteList.txt' : 'ipBlackList.txt');
    if (file_exists($file)) {
        $ipList = array_filter(array_map('trim', file($file)));
        $hit = in_array($clientIp, $ipList);
        if (($mode === 1 && !$hit) || ($mode === 2 && $hit)) {
            $accessDenied = true;
            $denyMessage = "访问被拒绝：IP不允许。";
        }
    }
}

// 记录访问日志
if ($Config['debug_mode'] ?? 0) {
    $logFile = __DIR__ . '/data/access.log';
    $time = date('Y-m-d H:i:s');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $url = rawurldecode($_SERVER['REQUEST_URI'] ?? 'unknown');

    $log = "[$time] [$clientIp] ";
    if ($accessDenied) $log .= $denyMessage;
    $log .= "[$method] $url | UA: $userAgent\n";

    file_put_contents($logFile, $log, FILE_APPEND);
}

// 拒绝访问并结束脚本
if ($accessDenied) {
    http_response_code(403);
    exit($denyMessage);
}

// 禁止输出错误提示
error_reporting(0);

// 初始化响应头信息
$init = [
    'status' => 200,
    'headers' => [
        'content-type' => 'application/json'
    ]
];

// 生成响应
function makeRes($body, $status = 200, $headers = []) {
    $headers['Access-Control-Allow-Origin'] = '*';
    http_response_code($status);
    foreach ($headers as $key => $value) {
        header("$key: $value");
    }
    echo $body;
}

// 获取当前日期
function getNowDate() {
    return date('Y-m-d');
}

// 格式化时间
function getFormatTime($time) {
    if (strlen($time) < 8) {
        return ['date' => getNowDate(), 'time' => ''];
    }

    $date = substr($time, 0, 4) . '-' . substr($time, 4, 2) . '-' . substr($time, 6, 2);
    $time = strlen($time) >= 12 ? substr($time, 8, 2) . ':' . substr($time, 10, 2) : '';

    return ['date' => $date, 'time' => $time];
}

// 从数据库读取 diyp、lovetv 数据，兼容未安装 memcached 的情况
function readEPGData($date, $oriChannelName, $cleanChannelName, $db, $type) {
    global $serverUrl;

    // 默认缓存 24 小时，更新数据时清空
    $cache_time = 24 * 3600;

    // 检查 Memcached 状态
    $memcached_enabled = class_exists('Memcached') && ($memcached = new Memcached())->addServer('localhost', 11211);
    $cache_key = base64_encode("{$date}_{$cleanChannelName}_{$type}");

    if ($memcached_enabled) {
        // 从缓存中读取数据
        $cached_data = $memcached->get($cache_key);
        if ($cached_data) {
            $cached_data = preg_replace('#.*(/data/icon/.*)#', $serverUrl . '$1', $cached_data);
        }
    }

    // 获取数据库类型（mysql 或 sqlite）
    $concat = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
        ? "CONCAT('%', channel, '%')"
        : "'%' || channel || '%'";

    // 优先精准匹配，其次正向模糊匹配，最后反向模糊匹配
    $stmt = $db->prepare("
        SELECT epg_diyp
        FROM epg_data
        WHERE (
            (channel = :channel
            OR channel LIKE :like_channel
            OR :channel LIKE $concat)
            AND date = :date
        )
        ORDER BY
            CASE
                WHEN channel = :channel THEN 1
                WHEN channel LIKE :like_channel THEN 2
                ELSE 3
            END,
            CASE
                WHEN channel = :channel THEN NULL
                WHEN channel LIKE :like_channel THEN LENGTH(channel)
                ELSE -LENGTH(channel)
            END
        LIMIT 1
    ");
    $stmt->execute([
        ':date' => $date,
        ':channel' => $cleanChannelName,
        ':like_channel' => $cleanChannelName . '%'
    ]);
    $row = $stmt->fetchColumn();

    if (!$row) {
        return false;
    }

    // 在解码和添加 icon 后再编码为 JSON
    $rowArray = json_decode($row, true);
    unset($rowArray['source']); // 移除 source 字段
    $iconUrl = iconUrlMatch($cleanChannelName) ?? iconUrlMatch($oriChannelName);
    $rowArray = array_merge(
        array_slice($rowArray, 0, array_search('url', array_keys($rowArray)) + 1),
        ['icon' => $iconUrl],
        array_slice($rowArray, array_search('url', array_keys($rowArray)) + 1)
    );
    $row = json_encode($rowArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    if ($type === 'diyp') {
        // 如果 Memcached 可用，将结果存储到缓存中
        if ($memcached_enabled) {
            $memcached->set($cache_key, $row, $cache_time);
        }
        return $row;
    }

    if ($type === 'lovetv') {
        $diyp_data = $rowArray;
        $date = $diyp_data['date'];
        $program = array_map(function($epg) use ($date) {
            $start_time = strtotime($date . ' ' . $epg['start']);
            $end_time = strtotime($date . ' ' . $epg['end']);
            $duration = $end_time - $start_time;
            return [
                'st' => $start_time,
                'et' => $end_time,
                'eventType' => '',
                'eventId' => '',
                't' => $epg['title'],
                'showTime' => gmdate('H:i', $duration),
                'duration' => $duration
            ];
        }, $diyp_data['epg_data']);

        // 查找当前节目
        $current_programme = $date === date('Y-m-d') ? findCurrentProgramme($program) : null;

        // 生成 lovetv 数据
        $lovetv_data = [
            $oriChannelName => [
                'isLive' => $current_programme ? $current_programme['t'] : '',
                'liveSt' => $current_programme ? $current_programme['st'] : 0,
                'channelName' => $diyp_data['channel_name'],
                'lvUrl' => $diyp_data['url'],
                'icon' => $diyp_data['icon'],
                'program' => $program
            ]
        ];

        $response = json_encode($lovetv_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // 如果 Memcached 可用，将结果存储到缓存中
        if ($memcached_enabled) {
            $memcached->set($cache_key, $response, $cache_time);
        }

        return $response;
    }

    return false;
}

// 查找当前节目
function findCurrentProgramme($programmes) {
    $now = time();
    foreach ($programmes as $programme) {
        if ($programme['st'] <= $now && $programme['et'] >= $now) {
            return $programme;
        }
    }
    return null;
}

// 处理直播源请求
function liveFetchHandler($query_params) {
    global $Config, $liveDir, $serverUrl, $liveFileDir;

    header('Content-Type: text/plain');

    // 计算文件路径
    $isValidFile = false;
    if (!empty($query_params['url'])) {
        $url = $query_params['url'];
        $filePath = sprintf('%s/%s.%s', $liveFileDir, md5(urlencode($url)), $query_params['type']);
        if (($query_params['latest'] === '1' && doParseSourceInfo($url)) === true || 
            file_exists($filePath) || doParseSourceInfo($url) === true) { // 判断是否需要获取最新文件
            $isValidFile = true;
        }
    } else {
        $filePath = $liveDir . ($query_params['type'] === 'txt' ? 'tv.txt' : ($query_params['type'] === 'm3u' ? 'tv.m3u' : ''));
        $isValidFile = file_exists($filePath);
    }

    // 如果文件存在或成功解析了源数据
    if ($isValidFile) {
        $content = file_get_contents($filePath);
    } else {
        echo "文件不存在";
        exit;
    }

    // 处理 TVG URL 替换
    $tvgUrl = $serverUrl . ($query_params['type'] === 'm3u' ? '/t.xml.gz' : '/');
    if ($query_params['type'] === 'm3u') {
        $content = preg_replace('/(#EXTM3U x-tvg-url=")(.*?)(")/', '$1' . $tvgUrl . '$3', $content, 1);
        $content = str_replace("tvg-logo=\"/data/icon/", "tvg-logo=\"$serverUrl/data/icon/", $content);
    }

    echo $content;
    exit;
}

// 处理请求
function fetchHandler($query_params) {
    global $init, $db, $Config;

    // 处理直播源请求
    if (in_array($query_params['type'] ?? '', ['m3u', 'txt'])) {
        liveFetchHandler($query_params);
    }

    // 获取并清理频道名称，繁体转换成简体
    $oriChannelName = $query_params['ch'] ?? $query_params['channel'] ?? '';
    $cleanChannelName = cleanChannelName($oriChannelName, $t2s = ($Config['cht_to_chs'] ?? false));
    $date = getFormatTime(preg_replace('/\D+/', '', $query_params['date'] ?? ''))['date'] ?? getNowDate();

    // 频道为空时，返回 XML 文件
    if ($cleanChannelName === '') {
        if ($Config['gen_xml'] ?? 0) {
            $type = $query_params['type'] ?? 'xml';
            $file = $type === 'gz' ? 't.xml.gz' : 't.xml';
            $contentType = $type === 'gz' ? 'application/gzip' : 'application/xml';
            header("Content-Type: $contentType");
            header("Content-Disposition: attachment; filename=\"$file\"");
            readfile(__DIR__ . "/data/$file");
        } else {
            http_response_code(404);
            echo "404 Not Found. <br>未生成 xmltv 文件";
        }
        exit;
    }

    // 返回 diyp、lovetv 数据
    if (isset($query_params['ch']) || isset($query_params['channel'])) {
        $type = isset($query_params['ch']) ? 'diyp' : 'lovetv';
        $response = readEPGData($date, $oriChannelName, $cleanChannelName, $db, $type);
        if ($response) {
            makeRes($response, $init['status'], $init['headers']);
            exit;
        }

        // 无法获取到数据时返回默认数据
        $ret_default = $Config['ret_default'] ?? true;
        $iconUrl = iconUrlMatch($cleanChannelName) ?? iconUrlMatch($oriChannelName);
        if ($type === 'diyp') {
            // 返回默认 diyp 数据
            $default_diyp_program_info = [
                'channel_name' => $cleanChannelName,
                'date' => $date,
                'url' => "https://github.com/taksssss/EPG-Server",
                'icon' => $iconUrl,
                'epg_data' => !$ret_default ? '' : array_map(function($hour) {
                    return [
                        'start' => sprintf('%02d:00', $hour),
                        'end' => sprintf('%02d:00', ($hour + 1) % 24),
                        'title' => '精彩节目',
                        'desc' => ''
                    ];
                }, range(0, 23, 1))
            ];
            $response = json_encode($default_diyp_program_info, JSON_UNESCAPED_UNICODE);
        } else {
            // 返回默认 lovetv 数据
            $default_lovetv_program_info = [
                $cleanChannelName => [
                    'isLive' => '',
                    'liveSt' => 0,
                    'channelName' => $cleanChannelName,
                    'lvUrl' => 'https://github.com/taksssss/EPG-Server',
                    'icon' => $iconUrl,
                    'program' => !$ret_default ? '' : array_map(function($hour) {
                        return [
                            'st' => strtotime(sprintf('%02d:00', $hour)),
                            'et' => strtotime(sprintf('%02d:00', ($hour + 1) % 24)),
                            't' => '精彩节目',
                            'd' => ''
                        ];
                    }, range(0, 23, 1))
                ]
            ];
            $response = json_encode($default_lovetv_program_info, JSON_UNESCAPED_UNICODE);
        }
        makeRes($response, $init['status'], $init['headers']);
    }
}

// 执行请求处理
fetchHandler($query_params);

?>
