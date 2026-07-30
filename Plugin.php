<?php
/**
 * 在文章内插入 [music]文件夹名[/music] 标签，即可嵌入音乐播放器。
 * 音乐文件将放入 usr/uploads/music/, 每个文件夹对应一首音乐。
 *
 * @package MusicPlayer
 * @author FmCoral
 * @version 1.2
 * @link https://github.com/FmCoral/MusicPlayer-Typecho
 */

namespace TypechoPlugin\MusicPlayer;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;

class Plugin implements PluginInterface
{
    const CACHE_FILE = 'music_player.json';
    const AUDIO_EXT = ['flac', 'mp3', 'm4a', 'ogg', 'wav', 'aac', 'wma'];
    const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    private static bool $hasMusic = false;
    private static array $playerConfigs = [];
    private static ?array $cache = null;

    // ================================================================
    //  Lifecycle
    // ================================================================

    public static function activate(): string
    {
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->contentEx = __CLASS__ . '::contentEx';
        \Typecho\Plugin::factory('index.php')->end = __CLASS__ . '::renderAssets';

        // Create music dir automatically
        $dir = __TYPECHO_ROOT_DIR__ . '/usr/uploads/music/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Remove old cache file from plugin dir (legacy location)
        $oldCache = __DIR__ . '/MusicPlayer.json';
        if (file_exists($oldCache)) {
            @unlink($oldCache);
        }

        // Register admin panel: 管理 → 音乐
        \Utils\Helper::addPanel(3, 'MusicPlayer/pages/manage-music.php', '音乐', '管理音乐', 'administrator');

        return _t('MusicPlayer 已激活，将音乐放入 usr/uploads/music/ 后到管理 → 音乐 刷新缓存');
    }

    public static function deactivate(): string
    {
        // Remove admin panel: 管理 → 音乐
        \Utils\Helper::removePanel(3, 'MusicPlayer/pages/manage-music.php');

        // Clean up legacy standalone menu entries (from very old versions)
        $panelTable = \Utils\Helper::options()->panelTable;
        if (!empty($panelTable['parent'])) {
            foreach ($panelTable['parent'] as $key => $name) {
                if ($name === '音乐') {
                    \Utils\Helper::removePanel(10 + $key, 'MusicPlayer/pages/manage.php');
                    \Utils\Helper::removeMenu('音乐');
                    break;
                }
            }
        }
        return _t('MusicPlayer 已禁用');
    }

    /**
     * Plugin settings page: player configuration only.
     * Song management moved to 管理 → 音乐.
     */
    public static function config(Form $form): void
    {
        // 提示用户前往管理页
        echo '<div style="background:#f5f5f5;padding:12px 16px;border-radius:4px;margin-bottom:16px">';
        echo '🎵 添加/编辑/删除歌曲已移至 <a href="'
           . \Utils\Helper::options()->adminUrl . 'extending.php?panel=' . urlencode('MusicPlayer/pages/manage-music.php')
           . '" style="font-weight:600;text-decoration:underline">管理 → 音乐</a>';
        echo '</div>';

        $defaultCover = new Text(
            'defaultCover', null, '',
            _t('默认封面图'),
            _t('歌曲无封面时显示的默认图片 URL，留空不显示')
        );
        $form->addInput($defaultCover);

        $theme = new Radio(
            'theme',
            ['#b7daff' => '天蓝', '#ffb7b7' => '淡红', '#b7ffb7' => '草绿', '#e6e6e6' => '灰色', '#a0d2db' => '墨蓝'],
            '#b7daff',
            _t('播放器主题色'),
            _t('选择 APlayer 默认主题颜色')
        );
        $form->addInput($theme);

        $autoplay = new Radio(
            'autoplay',
            ['0' => '关闭', '1' => '开启'],
            '0',
            _t('自动播放'),
            _t('注：多数浏览器会限制自动播放')
        );
        $form->addInput($autoplay);
    }

    public static function personalConfig(Form $form): void {}

    // ================================================================
    //  Frontend Rendering
    // ================================================================

    public static function contentEx($content, $widget, $last): string
    {
        $text = empty($last) ? (string) $content : (string) $last;

        $text = preg_replace_callback(
            '/\[music\]([^\[\]]+)\[\/music\]/is',
            function ($m) {
                $folder = trim($m[1]);
                if ($folder === '' || str_contains($folder, '..') || str_contains($folder, '/')) {
                    return '';
                }

                $songs = self::getCache();
                if (!isset($songs[$folder])) return '';

                self::$hasMusic = true;
                $idx = count(self::$playerConfigs);
                $id = 'mp-' . $idx;
                $song = $songs[$folder];
                $base = rtrim(\Widget\Options::alloc()->siteUrl, '/') . '/usr/uploads/music/' . rawurlencode($folder) . '/';

                self::$playerConfigs[] = [
                    'id'     => $id,
                    'name'   => $folder,
                    'artist' => $song['artist'] ?? '',
                    'url'    => !empty($song['audioUrl']) ? $song['audioUrl'] : $base . rawurlencode($song['audio'][0]),
                    'lyric'  => !empty($song['lyricUrl']) ? $song['lyricUrl'] : (!empty($song['lyric']) ? $base . rawurlencode($song['lyric']) : null),
                    'cover'  => !empty($song['coverUrl']) ? $song['coverUrl'] : (!empty($song['cover']) ? $base . rawurlencode($song['cover']) : null),
                ];

                return '<div class="music-player" id="' . $id . '"></div>';
            },
            $text
        );

        return $text;
    }

    public static function renderAssets(): void
    {
        if (!self::$hasMusic || empty(self::$playerConfigs)) return;

        $opt = \Widget\Options::alloc();
        $pluginUrl = rtrim($opt->pluginUrl, '/') . '/MusicPlayer';
        $cfg = $opt->plugin('MusicPlayer');
        $defaultCover = $cfg->defaultCover ?? '';
        $theme = $cfg->theme ?? '#b7daff';
        $autoplay = ($cfg->autoplay ?? '0') === '1';

        echo '<link rel="stylesheet" href="' . $pluginUrl . '/APlayer.min.css">';
        echo '<script src="' . $pluginUrl . '/APlayer.min.js"></script>';
        echo '<script>';
        foreach (self::$playerConfigs as $p) {
            $artist = !empty($p['artist']) ? $p['artist'] : 'FmCoral';
            $audio = ['name' => $p['name'], 'artist' => $artist, 'url' => $p['url']];
            if ($p['cover']) $audio['cover'] = $p['cover'];
            elseif ($defaultCover) $audio['cover'] = $defaultCover;
            if ($p['lyric']) $audio['lrc'] = $p['lyric'];

            echo '(function(){'
                . 'var _c=document.getElementById("' . $p['id'] . '");'
                . 'new APlayer({container:_c,'
                . 'audio:' . json_encode($audio, JSON_UNESCAPED_UNICODE) . ','
                . 'theme:"' . $theme . '",'
                . 'lrcType:' . ($p['lyric'] ? 3 : 0) . ','
                . 'autoplay:' . ($autoplay ? 'true' : 'false')
                . '});'
                . '})();';
        }
        echo '</script>';
    }

    // ================================================================
    //  Cache
    // ================================================================

    public static function getCacheFilePath(): string
    {
        return self::getMusicDir() . self::CACHE_FILE;
    }

    public static function getCache(): array
    {
        if (self::$cache !== null) return self::$cache;

        $file = self::getCacheFilePath();
        if (!file_exists($file)) {
            self::$cache = [];
            return self::$cache;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            self::$cache = [];
            return self::$cache;
        }

        $data = json_decode($raw, true);
        self::$cache = (is_array($data) && isset($data['songs'])) ? $data['songs'] : [];
        return self::$cache;
    }

    /**
     * Write cache to disk.
     *
     * @param array $songs Song data array
     * @param bool $preserveExternal When true, re-add pure external-link songs that exist
     *                               in the old cache but are missing from $songs.
     *                               Pass false only when intentionally deleting a song.
     */
    public static function writeCache(array $songs, bool $preserveExternal = true): void
    {
        $existing = self::getCache();
        $preserveKeys = ['audioUrl', 'lyricUrl', 'coverUrl', 'artist'];

        foreach ($songs as $folder => &$data) {
            if (isset($existing[$folder])) {
                foreach ($preserveKeys as $key) {
                    // If user explicitly cleared this field, don't restore old value
                    $clearKey = '__' . $key . '_clear';
                    if (!empty($data[$clearKey])) {
                        unset($data[$clearKey]);
                        continue;
                    }
                    if (!empty($existing[$folder][$key])) {
                        $data[$key] = $existing[$folder][$key];
                    }
                }
                // Preserve existing order value
                if (isset($existing[$folder]['order'])) {
                    $data['order'] = $existing[$folder]['order'];
                }
            }
        }
        unset($data);

        // Preserve pure external-link songs not found by scan
        if ($preserveExternal) {
            foreach ($existing as $folder => $data) {
                if (!isset($songs[$folder]) && !empty($data['audioUrl'])) {
                    $songs[$folder] = $data;
                }
            }
        }

        if (!is_dir(self::getMusicDir())) {
            @mkdir(self::getMusicDir(), 0755, true);
        }
        file_put_contents(
            self::getCacheFilePath(),
            json_encode(
                ['version' => 1, 'updated' => date('Y-m-d H:i:s'), 'songs' => $songs],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
        self::$cache = null;
    }

    public static function scanMusicDir(): array
    {
        $dir = self::getMusicDir();
        if (!is_dir($dir)) return [];

        $songs = [];
        $folders = scandir($dir);
        if ($folders === false) return [];

        foreach ($folders as $folder) {
            if ($folder === '.' || $folder === '..') continue;
            $path = $dir . '/' . $folder;
            if (!is_dir($path)) continue;

            $audio = [];
            $lyric = null;
            $cover = null;

            $files = scandir($path);
            if ($files === false) continue;

            foreach ($files as $f) {
                if ($f === '.' || $f === '..') continue;
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (in_array($ext, self::AUDIO_EXT, true)) {
                    $audio[] = $f;
                } elseif ($ext === 'lrc') {
                    $lyric = $f;
                } elseif (in_array($ext, self::IMAGE_EXT, true) && $cover === null) {
                    $cover = $f;
                }
            }

            if (count($audio) > 1) {
                $pri = array_flip(self::AUDIO_EXT);
                usort($audio, fn($a, $b) => ($pri[strtolower(pathinfo($a, PATHINFO_EXTENSION))] ?? 99) - ($pri[strtolower(pathinfo($b, PATHINFO_EXTENSION))] ?? 99));
            }

            if (!empty($audio) || $lyric !== null || $cover !== null) {
                $songs[$folder] = ['audio' => $audio, 'lyric' => $lyric, 'cover' => $cover];
            }
        }

        return $songs;
    }

    public static function getMusicDir(): string
    {
        return __TYPECHO_ROOT_DIR__ . '/usr/uploads/music/';
    }

    // ================================================================
    //  Create / Delete
    // ================================================================

    public static function createOrUpdateSong(string $folder, array $data, array $files): array
    {
        $targetDir = self::getMusicDir() . '/' . $folder;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('无法创建目录');
        }

        $targets = ['audio' => 'audio', 'lyric' => 'lyric', 'cover' => 'cover'];
        foreach ($targets as $key => $field) {
            $mode = $data[$key . 'Mode'] ?? 'skip';
            if ($mode !== 'upload') continue;
            if (empty($files[$field]['tmp_name']) || !is_uploaded_file($files[$field]['tmp_name'])) continue;
            if ($files[$field]['error'] !== UPLOAD_ERR_OK) continue;

            $ext = strtolower(pathinfo($files[$field]['name'], PATHINFO_EXTENSION));
            $safeName = self::sanitizeFilename($files[$field]['name']);
            if ($safeName === '') $safeName = $key . '.' . $ext;

            $allowed = match ($key) {
                'audio' => in_array($ext, self::AUDIO_EXT, true),
                'lyric' => $ext === 'lrc',
                'cover' => in_array($ext, self::IMAGE_EXT, true),
            };

            if ($allowed) {
                $dest = $targetDir . '/' . $safeName;
                if (!move_uploaded_file($files[$field]['tmp_name'], $dest)) {
                    throw new \RuntimeException('文件保存失败：' . $files[$field]['name']);
                }
            }
        }

        $songs = self::scanMusicDir();
        if (!isset($songs[$folder])) {
            $songs[$folder] = ['audio' => [], 'lyric' => null, 'cover' => null];
        }

        $audioMode = $data['audioMode'] ?? '';
        $lyricMode = $data['lyricMode'] ?? '';
        $coverMode = $data['coverMode'] ?? '';

        $songs[$folder]['audioUrl'] = $audioMode === 'url' ? trim($data['audioUrl'] ?? '') : '';
        $songs[$folder]['lyricUrl'] = $lyricMode === 'url' ? trim($data['lyricUrl'] ?? '') : '';
        $songs[$folder]['coverUrl'] = $coverMode === 'url' ? trim($data['coverUrl'] ?? '') : '';
        $songs[$folder]['artist'] = trim($data['artist'] ?? '');

        // Mark explicitly cleared fields so writeCache() won't restore old values
        if ($lyricMode === 'none') $songs[$folder]['__lyricUrl_clear'] = true;
        if ($coverMode === 'none') $songs[$folder]['__coverUrl_clear'] = true;

        self::writeCache($songs);
        return $songs[$folder];
    }

    public static function deleteSongFolder(string $folder): bool
    {
        $path = self::getMusicDir() . '/' . $folder;
        if (is_dir($path)) {
            $it = new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS);
            $fi = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($fi as $f) {
                $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
            }
            rmdir($path);
        }

        $songs = self::getCache();
        unset($songs[$folder]);
        self::writeCache($songs, false);   // false = don't resurrect external-link songs
        return !is_dir($path);
    }

    private static function sanitizeFilename(string $name): string
    {
        $name = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9._\-]/u', '', $name);
        return trim($name);
    }

    /**
     * Validate upload error code, throws RuntimeException on failure.
     */
    private static function checkUploadError(array $file, string $label): void
    {
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \RuntimeException('请选择' . $label . '文件');
        }
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_OK) return;
        $msg = match ($err) {
            UPLOAD_ERR_INI_SIZE => $label . '文件超过服务器允许的最大值（upload_max_filesize）',
            UPLOAD_ERR_FORM_SIZE => $label . '文件超过表单限制的大小',
            UPLOAD_ERR_PARTIAL => $label . '文件只上传了一部分，请重试',
            UPLOAD_ERR_NO_FILE => '请选择' . $label . '文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不可用，请联系管理员',
            UPLOAD_ERR_CANT_WRITE => '服务器无法写入文件，请联系管理员',
            default => $label . '文件上传失败（错误码：' . $err . '）',
        };
        throw new \RuntimeException($msg);
    }

    public static function sanitizeFolderName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^\x{4e00}-\x{9fa5}a-zA-Z0-9_\-]/u', '', $name);
        return trim($name, '.');
    }
}
