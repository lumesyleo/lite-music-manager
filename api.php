<?php

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

$settings_file = __DIR__ . '/settings.json';
$settings = file_exists($settings_file) ? json_decode(file_get_contents($settings_file), true) : [];
$playlist_dir = realpath($settings['playlist_dir'] ?? __DIR__ . '/playlists') ?: __DIR__ . '/playlists';
$configured_server = strtolower(trim($settings['api_server_name'] ?? 'mymusic'));

function getBaseUrl() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    return $protocol . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
}

function normalizePaths(&$item, $base_url) {
    foreach (['url', 'pic', 'lrc'] as $field) {
        if (isset($item[$field]) && !preg_match('/^https?:\/\//', $item[$field])) {
            $item[$field] = $base_url . '/' . ltrim($item[$field], './');
        }
    }
    $item = array_merge(['name'=>'', 'artist'=>'', 'url'=>'', 'pic'=>'', 'lrc'=>''], $item);
}

if (isset($_GET['server'], $_GET['type'], $_GET['id'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $server = strtolower(trim($_GET['server']));
    $type   = strtolower(trim($_GET['type']));
    $id     = trim($_GET['id']);
    
    if ($server !== $configured_server) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid server. Use "' . $configured_server . '"'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $base_url = getBaseUrl();
    
    switch ($type) {
        case 'playlist':
            $name = preg_replace('/[^\w\x{4e00}-\x{9fa5}\-\s]/u', '', $id);
            $file = $playlist_dir . '/' . $name . '.json';
            if (!file_exists($file) || !is_file($file)) {
                http_response_code(404); echo json_encode(['error'=>'Playlist not found'], JSON_UNESCAPED_UNICODE); exit;
            }
            $playlist = json_decode(file_get_contents($file), true);
            if (!is_array($playlist)) $playlist = [];
            foreach ($playlist as &$item) normalizePaths($item, $base_url);
            echo json_encode($playlist, JSON_UNESCAPED_UNICODE);
            break;
            
        case 'song':
            $identifier = urldecode($id);
            $all_songs = [];
            foreach (glob($playlist_dir . '/*.json') as $f) {
                $pl = json_decode(file_get_contents($f), true);
                if (is_array($pl)) $all_songs = array_merge($all_songs, $pl);
            }
            
            // 重名处理逻辑
            $dedup_show_all = $settings['song_dedup_show_all'] ?? false;
            if (!$dedup_show_all) {
                $unique = []; $groups = [];
                foreach ($all_songs as $song) {
                    $key = trim($song['name'] ?: basename($song['url'] ?? 'unknown'));
                    $groups[$key][] = $song;
                }
                foreach ($groups as $songs) {
                    if (count($songs) === 1) {
                        $unique[] = $songs[0];
                    } else {
                        // 规则：URL层级短者优先，相同则按字母 a-z
                        usort($songs, function($a, $b) {
                            $uA = $a['url'] ?? ''; $uB = $b['url'] ?? '';
                            $dA = substr_count($uA, '/'); $dB = substr_count($uB, '/');
                            if ($dA !== $dB) return $dA - $dB;
                            return strcmp($uA, $uB);
                        });
                        $unique[] = $songs[0];
                    }
                }
                $all_songs = $unique;
            }
            
            $matched = null;
            foreach ($all_songs as $song) {
                if (isset($song['url']) && basename($song['url']) === $identifier) { $matched = $song; break; }
            }
            if (!$matched) {
                foreach ($all_songs as $song) {
                    if (isset($song['name']) && $song['name'] === $identifier) { $matched = $song; break; }
                }
            }
            
            if (!$matched) {
                http_response_code(404); echo json_encode(['error'=>'Song not found'], JSON_UNESCAPED_UNICODE); exit;
            }
            
            normalizePaths($matched, $base_url);
            // 始终返回数组格式
            echo json_encode([$matched], JSON_UNESCAPED_UNICODE);
            break;
            
        case 'search':
            echo json_encode([], JSON_UNESCAPED_UNICODE);
            break;
            
        default:
            http_response_code(400); echo json_encode(['error'=>'Unsupported type'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (isset($_GET['url'])) {
    header('Content-Type: application/json; charset=utf-8');
    $requested = preg_replace('#\.\.\/|\.\.\\\|^\./#', '', $_GET['url']);
    if (empty($requested) || pathinfo($requested, PATHINFO_EXTENSION) !== 'json') {
        http_response_code(400); echo json_encode(['error'=>'Invalid JSON path'], JSON_UNESCAPED_UNICODE); exit;
    }
    $file = __DIR__ . '/' . $requested;
    if (!file_exists($file) || !is_file($file)) {
        http_response_code(404); echo json_encode(['error'=>'File not found'], JSON_UNESCAPED_UNICODE); exit;
    }
    $playlist = json_decode(file_get_contents($file), true);
    if (!is_array($playlist)) $playlist = [];
    $base_url = getBaseUrl();
    foreach ($playlist as &$item) normalizePaths($item, $base_url);
    echo json_encode($playlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
echo "API is functioning properly.\n\nIf you wish to deploy it by yourself, please visit Repository 【https://github.com/lumesyleo/lite-music-manager】";
/*
echo "API Server: {$configured_server}\nMeting Format: ?server={$configured_server}&type=playlist\song&id=xxx\n\n";
foreach (glob($playlist_dir . '/*.json') as $f) {
    echo getBaseUrl() . "?server={$configured_server}&type=playlist&id=" . urlencode(pathinfo($f, PATHINFO_FILENAME)) . "\n";
}
    */
?>