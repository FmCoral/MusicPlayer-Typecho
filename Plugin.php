<?php
/**
 * MusicPlayer — APlayer-based music player for Typecho.
 *
 * Insert [music]foldername[/music] in articles to embed a player.
 * Music files go into usr/uploads/music/, one folder per song.
 *
 * @package MusicPlayer
 * @author FmCoral
 * @version 1.0
 * @link https://github.com/FmCoral
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

        return _t('MusicPlayer 已激活，将音乐放入 usr/uploads/music/ 后到插件设置页刷新缓存');
    }

    public static function deactivate(): string
    {
        // Clean up legacy standalone menu entries
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
     * Plugin settings page: song management on top, player config below.
     */
    public static function config(Form $form): void
    {
        // Use alloc() instead of global to work when Edit widget calls config() on activation
        $options = \Widget\Options::alloc();
        $security = \Typecho\Widget::widget('Widget\Security');

        // Current page URL used as form action
        $pageUrl = $options->adminUrl . 'options-plugin.php?config=MusicPlayer';

        // ── Handle POST actions ──
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mp-action'])) {
            try {
                $security->protect();

                switch ($_POST['mp-action']) {
                    case 'refresh':
                        $songs = self::scanMusicDir();
                        self::writeCache($songs);
                        $msg = '缓存已更新，共 ' . count($songs) . ' 首歌曲';
                        break;

                    case 'create':
                        $folder = self::sanitizeFolderName($_POST['folderName'] ?? '');
                        if ($folder === '') throw new \RuntimeException('请输入有效的文件夹名');

                        $audioMode = $_POST['audioMode'] ?? 'upload';
                        if ($audioMode === 'upload') {
                            self::checkUploadError($_FILES['audio'] ?? [], '音频');
                        }

                        self::createOrUpdateSong($folder, $_POST, $_FILES);
                        $msg = '歌曲「' . $folder . '」已创建';
                        break;

                    case 'delete':
                        $folder = trim($_POST['folder'] ?? '');
                        if ($folder === '') throw new \RuntimeException('未指定歌曲');
                        self::deleteSongFolder($folder);
                        $msg = '歌曲「' . $folder . '」已删除';
                        break;

                    case 'edit_save':
                        $originalFolder = trim($_POST['originalFolder'] ?? '');
                        $newFolder = self::sanitizeFolderName($_POST['folderName'] ?? '');
                        if ($newFolder === '' || $originalFolder === '') {
                            throw new \RuntimeException('无效的歌曲名称');
                        }
                        $allSongs = self::getCache();
                        if (!isset($allSongs[$originalFolder])) {
                            throw new \RuntimeException('歌曲不存在');
                        }

                        // Rename folder
                        if ($newFolder !== $originalFolder) {
                            if (isset($allSongs[$newFolder])) {
                                throw new \RuntimeException('歌曲「' . $newFolder . '」已存在');
                            }
                            $oldPath = self::getMusicDir() . '/' . $originalFolder;
                            $newPath = self::getMusicDir() . '/' . $newFolder;
                            if (is_dir($oldPath)) {
                                if (!rename($oldPath, $newPath)) {
                                    throw new \RuntimeException('无法重命名文件夹');
                                }
                            } elseif (!is_dir($newPath)) {
                                @mkdir($newPath, 0755, true);
                            }
                            // Migrate cache entry
                            $allSongs[$newFolder] = $allSongs[$originalFolder];
                            unset($allSongs[$originalFolder]);
                            self::writeCache($allSongs);
                        }

                        // Remap prefixed form fields (edit_) to standard names
                        $_POST['audioMode'] = $_POST['edit_audioMode'] ?? 'upload';
                        $_POST['lyricMode'] = $_POST['edit_lyricMode'] ?? 'skip';
                        $_POST['coverMode'] = $_POST['edit_coverMode'] ?? 'skip';
                        $_POST['audioUrl']  = $_POST['edit_audioUrl'] ?? '';
                        $_POST['lyricUrl']  = $_POST['edit_lyricUrl'] ?? '';
                        $_POST['coverUrl']  = $_POST['edit_coverUrl'] ?? '';
                        self::createOrUpdateSong($newFolder, $_POST, $_FILES);
                        $msg = '歌曲「' . $newFolder . '」已更新';
                        break;
                }

                header('Location: ' . $pageUrl . '&mp_msg=' . urlencode($msg ?? '') . '&mp_status=success');
                exit;

            } catch (\Throwable $e) {
                header('Location: ' . $pageUrl . '&mp_msg=' . urlencode($e->getMessage()) . '&mp_status=error');
                exit;
            }
        }

        // ── Show redirect flash messages ──
        $mpStatus = $_GET['mp_status'] ?? '';
        $mpMsg = $_GET['mp_msg'] ?? '';
        if ($mpMsg) {
            $cls = $mpStatus === 'success' ? 'success' : 'error';
            echo '<div class="message ' . $cls . '">' . htmlspecialchars(urldecode($mpMsg)) . '</div>';
        }

        // ── Read cache ──
        $cache = self::getCache();
        $musicDir = self::getMusicDir();
        $hasDir = is_dir($musicDir);
        $cacheFile = self::getCacheFilePath();
        $cacheTime = file_exists($cacheFile) ? date('Y-m-d H:i:s', filemtime($cacheFile)) : '尚未生成';

        // ── Admin UI ──
        echo '<style>.typecho-page-main>.col-tb-8{flex:0 0 100%;max-width:100%;margin-left:0}</style>';
        echo '<div class="mp-admin" style="margin-bottom:24px">';

        // Status bar
        echo '<div style="background:#f5f5f5;padding:10px 14px;border-radius:4px;margin-bottom:16px">';
        echo '<strong>缓存状态：</strong>共 <strong>' . count($cache) . '</strong> 首 &nbsp;|&nbsp; '
           . '更新：' . $cacheTime . ' &nbsp;|&nbsp; '
           . '目录：<code>' . $musicDir . '</code>'
           . ($hasDir ? '' : ' <span style="color:#c33">（不存在）</span>');
        echo '</div>';

        // Refresh cache button
        echo '<form method="post" action="' . $pageUrl . '" style="margin-bottom:16px">';
        echo '<input type="hidden" name="_" value="' . $security->getToken($options->request->getRequestUrl()) . '">';
        echo '<input type="hidden" name="mp-action" value="refresh">';
        echo '<button type="submit" class="btn primary">🔄 刷新缓存</button>';
        echo ' <span class="description">扫描目录，保留已有外链</span>';
        echo '</form>';

        // ── New song form ──
        echo '<details style="margin-bottom:16px;border:1px solid #e9e9e9;border-radius:4px;padding:12px 14px" open>';
        echo '<summary style="cursor:pointer;font-weight:bold;font-size:14px">➕ 新增歌曲</summary>';
        echo '<form method="post" action="' . $pageUrl . '" enctype="multipart/form-data" style="margin-top:10px">';
        echo '<input type="hidden" name="_" value="' . $security->getToken($options->request->getRequestUrl()) . '">';
        echo '<input type="hidden" name="mp-action" value="create">';

        echo '<table class="typecho-list-table">';
        echo '<tr><td style="width:100px"><label>文件夹名 *</label></td>'
           . '<td><input type="text" name="folderName" required placeholder="如：晴天" style="width:60%"></td></tr>';

        // Audio (no skip option)
        echo '<tr><td>🎵 音频</td><td>';
        echo '<label style="margin-right:12px;cursor:pointer"><input type="radio" name="audioMode" value="upload" checked onclick="mpToggle(\'c-audio\',this.value)"> 上传文件</label>';
        echo '<label style="cursor:pointer"><input type="radio" name="audioMode" value="url" onclick="mpToggle(\'c-audio\',this.value)"> 外链</label>';
        echo '<div id="c-audio-upload" style="margin-top:6px"><input type="file" name="audio" accept=".mp3,.flac,.ogg,.wav,.aac,.m4a,.wma"></div>';
        echo '<div id="c-audio-url" style="margin-top:6px;display:none"><input type="url" name="audioUrl" placeholder="https://example.com/song.mp3" style="width:90%"></div>';
        echo '</td></tr>';

        // Lyric
        echo '<tr><td>📝 歌词</td><td>';
        echo '<label style="margin-right:12px;cursor:pointer"><input type="radio" name="lyricMode" value="upload" onclick="mpToggle(\'c-lyric\',this.value)"> 上传文件</label>';
        echo '<label style="margin-right:12px;cursor:pointer"><input type="radio" name="lyricMode" value="url" onclick="mpToggle(\'c-lyric\',this.value)"> 外链</label>';
        echo '<label style="cursor:pointer"><input type="radio" name="lyricMode" value="skip" checked onclick="mpToggle(\'c-lyric\',this.value)"> 跳过</label>';
        echo '<div id="c-lyric-upload" style="margin-top:6px;display:none"><input type="file" name="lyric" accept=".lrc"></div>';
        echo '<div id="c-lyric-url" style="margin-top:6px;display:none"><input type="url" name="lyricUrl" placeholder="https://example.com/lyric.lrc" style="width:90%"></div>';
        echo '</td></tr>';

        // Cover
        echo '<tr><td>🖼 封面</td><td>';
        echo '<label style="margin-right:12px;cursor:pointer"><input type="radio" name="coverMode" value="upload" onclick="mpToggle(\'c-cover\',this.value)"> 上传文件</label>';
        echo '<label style="margin-right:12px;cursor:pointer"><input type="radio" name="coverMode" value="url" onclick="mpToggle(\'c-cover\',this.value)"> 外链</label>';
        echo '<label style="cursor:pointer"><input type="radio" name="coverMode" value="skip" checked onclick="mpToggle(\'c-cover\',this.value)"> 跳过</label>';
        echo '<div id="c-cover-upload" style="margin-top:6px;display:none"><input type="file" name="cover" accept=".jpg,.jpeg,.png,.gif,.webp"></div>';
        echo '<div id="c-cover-url" style="margin-top:6px;display:none"><input type="url" name="coverUrl" placeholder="https://example.com/cover.jpg" style="width:90%"></div>';
        echo '</td></tr>';

        echo '</table>';
        echo '<button type="submit" class="btn primary" style="margin-top:8px">⬆ 创建</button>';
        echo '</form></details>';

        // ── Song list + edit modal ──
        $songs = [];
        if (count($cache) > 0) {
            foreach ($cache as $folder => $info) {
                $songs[] = $info + ['folder' => $folder];
            }
            usort($songs, fn($a, $b) => strcmp($a['folder'], $b['folder']));
        }

        $editFolder = $_GET['edit'] ?? '';

        echo '<h4 style="margin:0 0 8px">📋 歌曲列表</h4>';

        if (empty($songs)) {
            echo '<p style="color:#999">暂无歌曲</p>';
        } else {
            echo '<div class="typecho-table-wrap">';
            echo '<table class="typecho-list-table">';
            echo '<thead><tr><th style="text-align:left">歌曲名称</th><th>音频来源</th><th>歌词来源</th><th>封面来源</th><th style="text-align:center">操作</th></tr></thead><tbody>';

            foreach ($songs as $s) {
                $folder = $s['folder'];

                // Source badges
                $audioBadge = !empty($s['audioUrl']) ? '<span style="color:#467fcf">🌐 外链</span>' : (!empty($s['audio']) ? '📁 本地' : '<span style="color:#c33">✕ 无</span>');
                $lyricBadge = !empty($s['lyricUrl']) ? '<span style="color:#467fcf">🌐 外链</span>' : (!empty($s['lyric']) ? '📁 本地' : '<span style="color:#999">— 无</span>');
                $coverBadge = !empty($s['coverUrl']) ? '<span style="color:#467fcf">🌐 外链</span>' : (!empty($s['cover']) ? '📁 本地' : '<span style="color:#999">— 无</span>');

                // Build play / cover / lyric URLs
                $playUrl = '';
                if (!empty($s['audioUrl'])) {
                    $playUrl = $s['audioUrl'];
                } elseif (!empty($s['audio'])) {
                    $playUrl = rtrim($options->siteUrl, '/') . '/usr/uploads/music/' . rawurlencode($folder) . '/' . rawurlencode($s['audio'][0]);
                }
                $coverUrl = !empty($s['coverUrl']) ? $s['coverUrl'] : (!empty($s['cover']) ? rtrim($options->siteUrl, '/') . '/usr/uploads/music/' . rawurlencode($folder) . '/' . rawurlencode($s['cover']) : '');
                $lyricUrl = !empty($s['lyricUrl']) ? $s['lyricUrl'] : (!empty($s['lyric']) ? rtrim($options->siteUrl, '/') . '/usr/uploads/music/' . rawurlencode($folder) . '/' . rawurlencode($s['lyric']) : '');

                echo '<tr>';
                echo '<td style="text-align:left"><strong>' . htmlspecialchars($folder) . '</strong></td>';
                echo '<td>' . $audioBadge . '</td>';
                echo '<td>' . $lyricBadge . '</td>';
                echo '<td>' . $coverBadge . '</td>';
                echo '<td style="text-align:center"><div style="display:flex;gap:8px;justify-content:center;align-items:center">';
                if ($playUrl) {
                    $playData = htmlspecialchars(
                        json_encode([$folder, $playUrl, $coverUrl, $lyricUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ENT_QUOTES, 'UTF-8'
                    );
                    echo '<button type="button" class="btn btn-xs" onclick="mpPlay(' . $playData . ')" style="cursor:pointer;white-space:nowrap">试听</button>';
                }
                echo '<a href="' . htmlspecialchars($pageUrl . '&edit=' . rawurlencode($folder), ENT_QUOTES, 'UTF-8') . '" class="btn btn-xs" style="text-decoration:none;white-space:nowrap;line-height:1;display:inline-flex;align-items:center">编辑</a>';
                echo '<form method="post" action="' . $pageUrl . '" style="display:inline" onsubmit="return confirm(\'确定删除「' . htmlspecialchars($folder) . '」吗？\')">';
                echo '<input type="hidden" name="_" value="' . $security->getToken($options->request->getRequestUrl()) . '">';
                echo '<input type="hidden" name="mp-action" value="delete">';
                echo '<input type="hidden" name="folder" value="' . htmlspecialchars($folder) . '">';
                echo '<button type="submit" class="btn btn-xs" style="color:#c33;white-space:nowrap">删除</button>';
                echo '</form>';
                echo '</div></td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
            echo '<p class="description">使用 <code>[music]文件夹名[/music]</code> 在文章中插入音乐</p>';

            // ── Edit modal ──
            if ($editFolder !== '' && isset($cache[$editFolder])) {
                $s = $cache[$editFolder];
                $folder = $editFolder;

                echo '<div id="mp-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:9999;display:flex;align-items:center;justify-content:center">';
                echo '<div style="background:#fff;border-radius:8px;padding:24px;max-width:720px;width:92%;max-height:85vh;overflow-y:auto;box-shadow:0 8px 30px rgba(0,0,0,0.2)">';
                echo '<h3 style="margin:0 0 14px;font-size:16px">✏ 编辑歌曲 — ' . htmlspecialchars($folder) . '</h3>';
                echo '<form method="post" action="' . $pageUrl . '" enctype="multipart/form-data">';
                echo '<input type="hidden" name="_" value="' . $security->getToken($options->request->getRequestUrl()) . '">';
                echo '<input type="hidden" name="mp-action" value="edit_save">';
                echo '<input type="hidden" name="originalFolder" value="' . htmlspecialchars($folder) . '">';
                echo '<div style="margin-bottom:12px"><label style="font-weight:600;font-size:13px">📁 歌曲名称</label> <input type="text" name="folderName" value="' . htmlspecialchars($folder) . '" style="width:100%;margin-top:4px;box-sizing:border-box"></div>';
                echo '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">';

                // Audio
                $audioMode = !empty($s['audioUrl']) ? 'url' : 'upload';
                echo '<fieldset style="border:1px solid #e8e8e8;border-radius:4px;padding:10px 12px">';
                echo '<legend style="font-weight:600;font-size:13px">🎵 音频</legend>';
                echo '<label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_audioMode" value="upload"' . ($audioMode === 'upload' ? ' checked' : '') . ' onclick="mpToggle(\'e-audio\',this.value)"> 本地</label>';
                echo '<label style="cursor:pointer"><input type="radio" name="edit_audioMode" value="url"' . ($audioMode === 'url' ? ' checked' : '') . ' onclick="mpToggle(\'e-audio\',this.value)"> 外链</label>';
                echo '<div id="e-audio-upload"' . ($audioMode === 'upload' ? '' : ' style="display:none"') . '><input type="file" name="audio" accept=".mp3,.flac,.ogg,.wav,.aac,.m4a,.wma" style="margin-top:6px;width:100%"></div>';
                echo '<div id="e-audio-url"' . ($audioMode === 'url' ? '' : ' style="display:none"') . '><input type="url" name="edit_audioUrl" value="' . htmlspecialchars($s['audioUrl'] ?? '') . '" placeholder="https://" style="margin-top:6px;width:100%"></div>';
                echo '</fieldset>';

                // Lyric
                $lyricMode = !empty($s['lyricUrl']) ? 'url' : (!empty($s['lyric']) ? 'upload' : 'skip');
                echo '<fieldset style="border:1px solid #e8e8e8;border-radius:4px;padding:10px 12px">';
                echo '<legend style="font-weight:600;font-size:13px">📝 歌词</legend>';
                echo '<label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_lyricMode" value="upload"' . ($lyricMode === 'upload' ? ' checked' : '') . ' onclick="mpToggle(\'e-lyric\',this.value)"> 本地</label>';
                echo '<label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_lyricMode" value="url"' . ($lyricMode === 'url' ? ' checked' : '') . ' onclick="mpToggle(\'e-lyric\',this.value)"> 外链</label>';
                echo '<label style="cursor:pointer"><input type="radio" name="edit_lyricMode" value="skip"' . ($lyricMode === 'skip' ? ' checked' : '') . ' onclick="mpToggle(\'e-lyric\',this.value)"> 跳过</label>';
                echo '<div id="e-lyric-upload"' . ($lyricMode === 'upload' ? '' : ' style="display:none"') . '><input type="file" name="lyric" accept=".lrc" style="margin-top:6px;width:100%"></div>';
                echo '<div id="e-lyric-url"' . ($lyricMode === 'url' ? '' : ' style="display:none"') . '><input type="url" name="edit_lyricUrl" value="' . htmlspecialchars($s['lyricUrl'] ?? '') . '" placeholder="https://" style="margin-top:6px;width:100%"></div>';
                echo '</fieldset>';

                // Cover
                $coverMode = !empty($s['coverUrl']) ? 'url' : (!empty($s['cover']) ? 'upload' : 'skip');
                echo '<fieldset style="border:1px solid #e8e8e8;border-radius:4px;padding:10px 12px">';
                echo '<legend style="font-weight:600;font-size:13px">🖼 封面</legend>';
                echo '<label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_coverMode" value="upload"' . ($coverMode === 'upload' ? ' checked' : '') . ' onclick="mpToggle(\'e-cover\',this.value)"> 本地</label>';
                echo '<label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_coverMode" value="url"' . ($coverMode === 'url' ? ' checked' : '') . ' onclick="mpToggle(\'e-cover\',this.value)"> 外链</label>';
                echo '<label style="cursor:pointer"><input type="radio" name="edit_coverMode" value="skip"' . ($coverMode === 'skip' ? ' checked' : '') . ' onclick="mpToggle(\'e-cover\',this.value)"> 跳过</label>';
                echo '<div id="e-cover-upload"' . ($coverMode === 'upload' ? '' : ' style="display:none"') . '><input type="file" name="cover" accept=".jpg,.jpeg,.png,.gif,.webp" style="margin-top:6px;width:100%"></div>';
                echo '<div id="e-cover-url"' . ($coverMode === 'url' ? '' : ' style="display:none"') . '><input type="url" name="edit_coverUrl" value="' . htmlspecialchars($s['coverUrl'] ?? '') . '" placeholder="https://" style="margin-top:6px;width:100%"></div>';
                echo '</fieldset>';

                echo '</div>'; // grid
                echo '<div style="margin-top:14px;display:flex;gap:10px;align-items:center">';
                echo '<button type="submit" class="btn primary">💾 保存修改</button>';
                echo '<a href="' . $pageUrl . '" class="btn btn-xs" style="text-decoration:none">✕ 取消</a>';
                echo '</div>';
                echo '</form>';
                echo '</div></div>';

                // Click overlay to close modal
                echo '<script>document.getElementById("mp-modal").addEventListener("click",function(e){if(e.target===this)window.location.href=' . json_encode($pageUrl, JSON_UNESCAPED_SLASHES) . '})</script>';
            }
        }

        // ── Inline player ──
        $pluginUrl = rtrim($options->pluginUrl, '/') . '/MusicPlayer';
        try {
            $savedTheme = $options->plugin('MusicPlayer')->theme ?? '#b7daff';
        } catch (\Throwable $e) {
            $savedTheme = '#b7daff';
        }

        echo '</div>'; // .mp-admin

        echo '<div id="mp-player" style="display:none;margin:16px auto;max-width:500px;min-height:82px"></div>';
        echo '<link rel="stylesheet" href="' . $pluginUrl . '/APlayer.min.css">';
        echo '<script src="' . $pluginUrl . '/APlayer.min.js"></script>';

        // ── JS toggle & inline player ──
        echo '<script>
function mpToggle(p,m){["upload","url"].forEach(function(k){var e=document.getElementById(p+"-"+k);if(e)e.style.display=k===m?"":"none"})}
!function(){["audio","lyric","cover"].forEach(function(t){var r=document.querySelector("input[name=\""+t+"Mode\"]:checked");if(r)mpToggle("c-"+t,r.value)})}();
var _mpP=null;function mpPlay(d){var e=document.getElementById("mp-player");e.style.display="block";if(_mpP)_mpP.destroy();var a={name:d[0],url:d[1]};if(d[2])a.cover=d[2];if(d[3])a.lrc=d[3];_mpP=new APlayer({container:e,audio:a,theme:"' . $savedTheme . '",lrcType:d[3]?3:0});var _a=e.querySelector(".aplayer-author");if(_a)_a.style.display="none"}
</script>';

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        //  Player settings (Typecho standard form)
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

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
                    'id'    => $id,
                    'name'  => $folder,
                    'url'   => !empty($song['audioUrl']) ? $song['audioUrl'] : $base . rawurlencode($song['audio'][0]),
                    'lyric' => !empty($song['lyricUrl']) ? $song['lyricUrl'] : (!empty($song['lyric']) ? $base . rawurlencode($song['lyric']) : null),
                    'cover' => !empty($song['coverUrl']) ? $song['coverUrl'] : (!empty($song['cover']) ? $base . rawurlencode($song['cover']) : null),
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
        echo '<style>.aplayer-author{display:none!important}</style>';
        echo '<script src="' . $pluginUrl . '/APlayer.min.js"></script>';
        echo '<script>';
        foreach (self::$playerConfigs as $p) {
            $audio = ['name' => $p['name'], 'url' => $p['url']];
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
                . 'var _a=_c.querySelector(".aplayer-author");if(_a)_a.style.display="none"'
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
        $preserveKeys = ['audioUrl', 'lyricUrl', 'coverUrl'];

        foreach ($songs as $folder => &$data) {
            if (isset($existing[$folder])) {
                foreach ($preserveKeys as $key) {
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

        $songs[$folder]['audioUrl'] = ($data['audioMode'] ?? '') === 'url' ? trim($data['audioUrl'] ?? '') : '';
        $songs[$folder]['lyricUrl'] = ($data['lyricMode'] ?? '') === 'url' ? trim($data['lyricUrl'] ?? '') : '';
        $songs[$folder]['coverUrl'] = ($data['coverMode'] ?? '') === 'url' ? trim($data['coverUrl'] ?? '') : '';

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
