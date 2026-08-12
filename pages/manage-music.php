<?php
/**
 * MusicPlayer 后台管理面板
 *
 * 在 Typecho 后台「管理 → 音乐」中显示。
 * 通过 Helper::addPanel 注册，由 admin/extending.php 加载。
 *
 * 功能：添加/编辑/删除/刷新音乐缓存
 *
 * ─── 注意事项 ──────────────────────────────────
 * 必须在 header.php 之前处理 POST，因为 header/menu
 * 加载后 $request->isPost() 不再准确。
 */

if (!defined('__TYPECHO_ROOT_DIR__')) exit;

use TypechoPlugin\MusicPlayer\Plugin as MusicPlugin;

// ── POST 处理（必须在 header.php 之前）─────
// PRG 模式：POST 成功后重定向到 GET，避免刷新重复提交
$__redirect = false;
$__redirectMsg = '';
$__redirectStatus = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mp-action'])) {
    try {
        $security->protect();

        $action = $_POST['mp-action'];
        $pageUrl = 'extending.php?panel=' . urlencode('MusicPlayer/pages/manage-music.php');

        switch ($action) {
            case 'refresh':
                $__songs = MusicPlugin::scanMusicDir();
                MusicPlugin::writeCache($__songs);
                $__redirectMsg = '缓存已更新，共 ' . count($__songs) . ' 首歌曲';
                break;

            case 'create':
                $__folder = MusicPlugin::sanitizeFolderName($_POST['folderName'] ?? '');
                if ($__folder === '') throw new \RuntimeException('请输入有效的文件夹名');

                $__audioMode = $_POST['audioMode'] ?? 'upload';
                if ($__audioMode === 'upload') {
                    MusicPlugin::checkUploadError($_FILES['audio'] ?? [], '音频');
                }

                MusicPlugin::createOrUpdateSong($__folder, $_POST, $_FILES);
                $__redirectMsg = '歌曲「' . $__folder . '」已创建';
                break;

            case 'delete':
                $__folder = trim($_POST['folder'] ?? '');
                if ($__folder === '') throw new \RuntimeException('未指定歌曲');
                MusicPlugin::deleteSongFolder($__folder);
                $__redirectMsg = '歌曲「' . $__folder . '」已删除';
                break;

            case 'edit_save':
                $__originalFolder = trim($_POST['originalFolder'] ?? '');
                $__newFolder = MusicPlugin::sanitizeFolderName($_POST['folderName'] ?? '');
                if ($__newFolder === '' || $__originalFolder === '') {
                    throw new \RuntimeException('无效的歌曲名称');
                }
                $__allSongs = MusicPlugin::getCache();
                if (!isset($__allSongs[$__originalFolder])) {
                    throw new \RuntimeException('歌曲不存在');
                }

                // Rename folder
                if ($__newFolder !== $__originalFolder) {
                    if (isset($__allSongs[$__newFolder])) {
                        throw new \RuntimeException('歌曲「' . $__newFolder . '」已存在');
                    }
                    $__oldPath = MusicPlugin::getMusicDir() . '/' . $__originalFolder;
                    $__newPath = MusicPlugin::getMusicDir() . '/' . $__newFolder;
                    if (is_dir($__oldPath)) {
                        if (!rename($__oldPath, $__newPath)) {
                            throw new \RuntimeException('无法重命名文件夹');
                        }
                    } elseif (!is_dir($__newPath)) {
                        @mkdir($__newPath, 0755, true);
                    }
                    // Migrate cache entry
                    $__allSongs[$__newFolder] = $__allSongs[$__originalFolder];
                    unset($__allSongs[$__originalFolder]);
                    MusicPlugin::writeCache($__allSongs);
                }

                // Remap prefixed form fields to standard names
                $_POST['audioMode'] = $_POST['edit_audioMode'] ?? 'upload';
                $_POST['lyricMode'] = $_POST['edit_lyricMode'] ?? 'skip';
                $_POST['coverMode'] = $_POST['edit_coverMode'] ?? 'skip';
                $_POST['audioUrl']  = $_POST['edit_audioUrl'] ?? '';
                $_POST['lyricUrl']  = $_POST['edit_lyricUrl'] ?? '';
                $_POST['coverUrl']  = $_POST['edit_coverUrl'] ?? '';
                MusicPlugin::createOrUpdateSong($__newFolder, $_POST, $_FILES);
                $__redirectMsg = '歌曲「' . $__newFolder . '」已更新';
                break;
        }

        $__redirect = true;
    } catch (\Throwable $e) {
        $__redirectMsg = $e->getMessage();
        $__redirectStatus = 'error';
        $__redirect = true;
    }
}

// PRG 重定向
if ($__redirect) {
    setcookie('mp_flash', $__redirectMsg, time() + 60, '/');
    setcookie('mp_flash_status', $__redirectStatus, time() + 60, '/');
    header('Location: extending.php?panel=' . urlencode('MusicPlayer/pages/manage-music.php'));
    exit;
}

include 'header.php';
include 'menu.php';

// ── 读取 Flash Message ──
$mpFlash = $_COOKIE['mp_flash'] ?? '';
$mpFlashStatus = $_COOKIE['mp_flash_status'] ?? 'success';
if (!empty($mpFlash)) {
    setcookie('mp_flash', '', time() - 3600, '/');
    setcookie('mp_flash_status', '', time() - 3600, '/');
}

// ── 读取数据 ──
$cache = MusicPlugin::getCache();
$musicDir = MusicPlugin::getMusicDir();
$hasDir = is_dir($musicDir);
$cacheFile = MusicPlugin::getCacheFilePath();
$cacheTime = file_exists($cacheFile) ? date('Y-m-d H:i:s', filemtime($cacheFile)) : '尚未生成';

// 歌曲列表
$songs = [];
if (count($cache) > 0) {
    foreach ($cache as $folder => $info) {
        $songs[] = $info + ['folder' => $folder];
    }
    usort($songs, fn($a, $b) => strcmp($a['folder'], $b['folder']));
}

$editFolder = $_GET['edit'] ?? '';
$pluginUrl = rtrim($options->pluginUrl, '/') . '/MusicPlayer';

// 获取播放器主题色（用于试听播放器）
try {
    $savedTheme = $options->plugin('MusicPlayer')->theme ?? '#b7daff';
} catch (\Throwable $e) {
    $savedTheme = '#b7daff';
}
?>

<div class="row typecho-page-main">
    <div class="col-mb-12 col-tb-12">
        <div class="mp-wrap" style="max-width:1200px;margin:0 auto;width:100%">

        <div class="typecho-page-title">
            <h2>🎵 音乐管理</h2>
        </div>

        <?php if (!empty($mpFlash)): ?>
            <div class="message <?php echo $mpFlashStatus === 'success' ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars(urldecode($mpFlash)); ?>
            </div>
        <?php endif; ?>

        <div class="mp-admin" style="margin-bottom:24px">

            <!-- 状态栏 -->
            <div style="background:#f5f5f5;padding:10px 14px;border-radius:4px;margin-bottom:16px">
                <strong>缓存状态：</strong>共 <strong><?php echo count($cache); ?></strong> 首 &nbsp;|&nbsp;
                更新：<?php echo $cacheTime; ?> &nbsp;|&nbsp;
                目录：<code><?php echo $musicDir; ?></code>
                <?php echo $hasDir ? '' : ' <span style="color:#c33">（不存在）</span>'; ?>
            </div>

            <!-- 刷新缓存 -->
            <form method="post" action="<?php echo $security->getTokenUrl($request->getRequestUrl()); ?>" style="margin-bottom:16px">
                <input type="hidden" name="_" value="<?php echo $security->getToken($request->getRequestUrl()); ?>">
                <input type="hidden" name="mp-action" value="refresh">
                <button type="submit" class="btn primary">🔄 刷新缓存</button>
                <span class="description">扫描目录，保留已有外链</span>
            </form>

            <!-- 新增歌曲 -->
            <details style="margin-bottom:16px;border:1px solid #e9e9e9;border-radius:4px;padding:12px 14px" open>
                <summary style="cursor:pointer;font-weight:bold;font-size:14px">➕ 新增歌曲</summary>
                <form method="post" action="<?php echo $security->getTokenUrl($request->getRequestUrl()); ?>" enctype="multipart/form-data" style="margin-top:10px">
                    <input type="hidden" name="_" value="<?php echo $security->getToken($request->getRequestUrl()); ?>">
                    <input type="hidden" name="mp-action" value="create">

                    <table class="typecho-list-table">
                        <tr><td style="width:100px"><label>文件夹名 *</label></td>
                            <td><input type="text" name="folderName" required placeholder="如：晴天" style="width:60%"></td></tr>
                        <tr><td>🎤 歌手</td><td><input type="text" name="artist" placeholder="FmCoral（留空则默认）" style="width:60%"></td></tr>
                        <tr><td>🌐 Emo 页展示</td><td>
                            <input type="hidden" name="showInEmo" value="0">
                            <label style="cursor:pointer"><input type="checkbox" name="showInEmo" value="1" checked> 在 Emo 音乐页面展示</label>
                            <span class="description">取消勾选后不出现在 Emo 页面，文章 [music] 短代码不受影响</span>
                        </td></tr>

                        <!-- 音频 -->
                        <tr><td>🎵 音频</td><td>
                            <label style="margin-right:12px;cursor:pointer"><input type="radio" name="audioMode" value="upload" checked onclick="mpToggle('c-audio',this.value)"> 上传文件</label>
                            <label style="cursor:pointer"><input type="radio" name="audioMode" value="url" onclick="mpToggle('c-audio',this.value)"> 外链</label>
                            <div id="c-audio-upload" style="margin-top:6px"><input type="file" name="audio" accept=".mp3,.flac,.ogg,.wav,.aac,.m4a,.wma"></div>
                            <div id="c-audio-url" style="margin-top:6px;display:none"><input type="url" name="audioUrl" placeholder="https://example.com/song.mp3" style="width:90%"></div>
                        </td></tr>

                        <!-- 歌词 -->
                        <tr><td>📝 歌词</td><td>
                            <label style="margin-right:12px;cursor:pointer"><input type="radio" name="lyricMode" value="upload" onclick="mpToggle('c-lyric',this.value)"> 上传文件</label>
                            <label style="margin-right:12px;cursor:pointer"><input type="radio" name="lyricMode" value="url" onclick="mpToggle('c-lyric',this.value)"> 外链</label>
                            <label style="cursor:pointer"><input type="radio" name="lyricMode" value="skip" checked onclick="mpToggle('c-lyric',this.value)"> 跳过</label>
                            <div id="c-lyric-upload" style="margin-top:6px;display:none"><input type="file" name="lyric" accept=".lrc"></div>
                            <div id="c-lyric-url" style="margin-top:6px;display:none"><input type="url" name="lyricUrl" placeholder="https://example.com/lyric.lrc" style="width:90%"></div>
                        </td></tr>

                        <!-- 封面 -->
                        <tr><td>🖼 封面</td><td>
                            <label style="margin-right:12px;cursor:pointer"><input type="radio" name="coverMode" value="upload" onclick="mpToggle('c-cover',this.value)"> 上传文件</label>
                            <label style="margin-right:12px;cursor:pointer"><input type="radio" name="coverMode" value="url" onclick="mpToggle('c-cover',this.value)"> 外链</label>
                            <label style="cursor:pointer"><input type="radio" name="coverMode" value="skip" checked onclick="mpToggle('c-cover',this.value)"> 跳过</label>
                            <div id="c-cover-upload" style="margin-top:6px;display:none"><input type="file" name="cover" accept=".jpg,.jpeg,.png,.gif,.webp"></div>
                            <div id="c-cover-url" style="margin-top:6px;display:none"><input type="url" name="coverUrl" placeholder="https://example.com/cover.jpg" style="width:90%"></div>
                        </td></tr>
                    </table>
                    <button type="submit" class="btn primary" style="margin-top:8px">⬆ 创建</button>
                </form>
            </details>

            <!-- 歌曲列表 -->
            <h4 style="margin:0 0 8px">📋 歌曲列表</h4>

            <?php if (empty($songs)): ?>
                <p style="color:#999">暂无歌曲</p>
            <?php else: ?>
                <div class="typecho-table-wrap">
                    <table class="typecho-list-table">
                        <thead>
                            <tr><th style="text-align:left">歌曲名称</th><th>音频来源</th><th>歌词来源</th><th>封面来源</th><th style="text-align:center">Emo 页</th><th style="text-align:center">操作</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($songs as $s):
                                $folder = $s['folder'];
                                $audioBadge = !empty($s['audioUrl']) ? '<span style="color:#467fcf">🌐 外链</span>' : (!empty($s['audio']) ? '📁 本地' : '<span style="color:#c33">✕ 无</span>');
                                $lyricBadge = !empty($s['lyricUrl']) ? '<span style="color:#467fcf">🌐 外链</span>' : (!empty($s['lyric']) ? '📁 本地' : '<span style="color:#999">— 无</span>');
                                $coverBadge = !empty($s['coverUrl']) ? '<span style="color:#467fcf">🌐 外链</span>' : (!empty($s['cover']) ? '📁 本地' : '<span style="color:#999">— 无</span>');
                                $emoBadge = (isset($s['showInEmo']) ? !empty($s['showInEmo']) : true)
                                    ? '<span style="color:#5cb85c">✔ 展示</span>'
                                    : '<span style="color:#999">— 隐藏</span>';

                                // Build play / cover / lyric URLs
                                $playUrl = '';
                                if (!empty($s['audioUrl'])) {
                                    $playUrl = $s['audioUrl'];
                                } elseif (!empty($s['audio'])) {
                                    $playUrl = rtrim($options->siteUrl, '/') . '/usr/uploads/music/' . rawurlencode($folder) . '/' . rawurlencode($s['audio'][0]);
                                }
                                $coverUrl = !empty($s['coverUrl']) ? $s['coverUrl'] : (!empty($s['cover']) ? rtrim($options->siteUrl, '/') . '/usr/uploads/music/' . rawurlencode($folder) . '/' . rawurlencode($s['cover']) : '');
                                $lyricUrl = !empty($s['lyricUrl']) ? $s['lyricUrl'] : (!empty($s['lyric']) ? rtrim($options->siteUrl, '/') . '/usr/uploads/music/' . rawurlencode($folder) . '/' . rawurlencode($s['lyric']) : '');
                            ?>
                            <tr>
                                <td style="text-align:left"><strong><?php echo htmlspecialchars($folder); ?></strong></td>
                                <td><?php echo $audioBadge; ?></td>
                                <td><?php echo $lyricBadge; ?></td>
                                <td><?php echo $coverBadge; ?></td>
                                <td style="text-align:center"><?php echo $emoBadge; ?></td>
                                <td style="text-align:center">
                                    <div style="display:flex;gap:8px;justify-content:center;align-items:center">
                                        <?php if ($playUrl):
                                            $playData = htmlspecialchars(
                                                json_encode([$folder, $playUrl, $coverUrl, $lyricUrl], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                                                ENT_QUOTES, 'UTF-8'
                                            );
                                        ?>
                                            <button type="button" class="btn btn-xs" onclick="mpPlay(<?php echo $playData; ?>)" style="cursor:pointer;white-space:nowrap">试听</button>
                                        <?php endif; ?>
                                        <a href="extending.php?panel=<?php echo urlencode('MusicPlayer/pages/manage-music.php'); ?>&edit=<?php echo rawurlencode($folder); ?>" class="btn btn-xs" style="text-decoration:none;white-space:nowrap;line-height:1;display:inline-flex;align-items:center">编辑</a>
                                        <form method="post" action="<?php echo $security->getTokenUrl($request->getRequestUrl()); ?>" style="display:inline" onsubmit="return confirm('确定删除「<?php echo htmlspecialchars($folder); ?>」吗？')">
                                            <input type="hidden" name="_" value="<?php echo $security->getToken($request->getRequestUrl()); ?>">
                                            <input type="hidden" name="mp-action" value="delete">
                                            <input type="hidden" name="folder" value="<?php echo htmlspecialchars($folder); ?>">
                                            <button type="submit" class="btn btn-xs" style="color:#c33;white-space:nowrap">删除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="description">使用 <code>[music]文件夹名[/music]</code> 在文章中插入音乐</p>

                <!-- 编辑弹窗 -->
                <?php if ($editFolder !== '' && isset($cache[$editFolder])):
                    $s = $cache[$editFolder];
                    $folder = $editFolder;
                ?>
                <div id="mp-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:9999;display:flex;align-items:center;justify-content:center">
                    <div style="background:#fff;border-radius:8px;padding:24px;max-width:720px;width:92%;max-height:85vh;overflow-y:auto;box-shadow:0 8px 30px rgba(0,0,0,0.2)">
                        <h3 style="margin:0 0 14px;font-size:16px">✏ 编辑歌曲 — <?php echo htmlspecialchars($folder); ?></h3>
                        <form method="post" action="<?php echo $security->getTokenUrl($request->getRequestUrl()); ?>" enctype="multipart/form-data">
                            <input type="hidden" name="_" value="<?php echo $security->getToken($request->getRequestUrl()); ?>">
                            <input type="hidden" name="mp-action" value="edit_save">
                            <input type="hidden" name="originalFolder" value="<?php echo htmlspecialchars($folder); ?>">
                            <?php $artistVal = htmlspecialchars($s['artist'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                            <div style="margin-bottom:12px"><label style="font-weight:600;font-size:13px">📁 歌曲名称</label> <input type="text" name="folderName" value="<?php echo htmlspecialchars($folder); ?>" style="width:100%;margin-top:4px;box-sizing:border-box"></div>
                            <div style="margin-bottom:12px"><label style="font-weight:600;font-size:13px">🎤 歌手</label> <input type="text" name="artist" value="<?php echo $artistVal; ?>" placeholder="FmCoral（留空则默认）" style="width:100%;margin-top:4px;box-sizing:border-box"></div>
                            <div style="margin-bottom:12px">
                                <label style="font-weight:600;font-size:13px">🌐 Emo 页展示</label>
                                <div style="margin-top:4px">
                                    <input type="hidden" name="showInEmo" value="0">
                                    <label style="cursor:pointer"><input type="checkbox" name="showInEmo" value="1"<?php echo (isset($s['showInEmo']) ? !empty($s['showInEmo']) : true) ? ' checked' : ''; ?>> 在 Emo 音乐页面展示</label>
                                    <span class="description" style="margin-left:8px">取消勾选后仅 [music] 短代码可播放，不出现在 Emo 页面</span>
                                </div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">

                                <!-- 音频编辑 -->
                                <?php $audioMode = !empty($s['audioUrl']) ? 'url' : 'upload'; ?>
                                <fieldset style="border:1px solid #e8e8e8;border-radius:4px;padding:10px 12px">
                                    <legend style="font-weight:600;font-size:13px">🎵 音频</legend>
                                    <label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_audioMode" value="upload"<?php echo $audioMode === 'upload' ? ' checked' : ''; ?> onclick="mpToggle('e-audio',this.value)"> 本地</label>
                                    <label style="cursor:pointer"><input type="radio" name="edit_audioMode" value="url"<?php echo $audioMode === 'url' ? ' checked' : ''; ?> onclick="mpToggle('e-audio',this.value)"> 外链</label>
                                    <div id="e-audio-upload"<?php echo $audioMode === 'upload' ? '' : ' style="display:none"'; ?>><input type="file" name="audio" accept=".mp3,.flac,.ogg,.wav,.aac,.m4a,.wma" style="margin-top:6px;width:100%"></div>
                                    <div id="e-audio-url"<?php echo $audioMode === 'url' ? '' : ' style="display:none"'; ?>><input type="url" name="edit_audioUrl" value="<?php echo htmlspecialchars($s['audioUrl'] ?? ''); ?>" placeholder="https://" style="margin-top:6px;width:100%"></div>
                                </fieldset>

                                <!-- 歌词编辑 -->
                                <?php $lyricMode = !empty($s['lyricUrl']) ? 'url' : (!empty($s['lyric']) ? 'upload' : 'none'); ?>
                                <fieldset style="border:1px solid #e8e8e8;border-radius:4px;padding:10px 12px">
                                    <legend style="font-weight:600;font-size:13px">📝 歌词</legend>
                                    <label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_lyricMode" value="upload"<?php echo $lyricMode === 'upload' ? ' checked' : ''; ?> onclick="mpToggle('e-lyric',this.value)"> 本地</label>
                                    <label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_lyricMode" value="url"<?php echo $lyricMode === 'url' ? ' checked' : ''; ?> onclick="mpToggle('e-lyric',this.value)"> 外链</label>
                                    <label style="cursor:pointer"><input type="radio" name="edit_lyricMode" value="none"<?php echo $lyricMode === 'none' ? ' checked' : ''; ?> onclick="mpToggle('e-lyric',this.value)"> 清除</label>
                                    <div id="e-lyric-upload"<?php echo $lyricMode === 'upload' ? '' : ' style="display:none"'; ?>><input type="file" name="lyric" accept=".lrc" style="margin-top:6px;width:100%"></div>
                                    <div id="e-lyric-url"<?php echo $lyricMode === 'url' ? '' : ' style="display:none"'; ?>><input type="url" name="edit_lyricUrl" value="<?php echo htmlspecialchars($s['lyricUrl'] ?? ''); ?>" placeholder="https://" style="margin-top:6px;width:100%"></div>
                                </fieldset>

                                <!-- 封面编辑 -->
                                <?php $coverMode = !empty($s['coverUrl']) ? 'url' : (!empty($s['cover']) ? 'upload' : 'none'); ?>
                                <fieldset style="border:1px solid #e8e8e8;border-radius:4px;padding:10px 12px">
                                    <legend style="font-weight:600;font-size:13px">🖼 封面</legend>
                                    <label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_coverMode" value="upload"<?php echo $coverMode === 'upload' ? ' checked' : ''; ?> onclick="mpToggle('e-cover',this.value)"> 本地</label>
                                    <label style="margin-right:10px;cursor:pointer"><input type="radio" name="edit_coverMode" value="url"<?php echo $coverMode === 'url' ? ' checked' : ''; ?> onclick="mpToggle('e-cover',this.value)"> 外链</label>
                                    <label style="cursor:pointer"><input type="radio" name="edit_coverMode" value="none"<?php echo $coverMode === 'none' ? ' checked' : ''; ?> onclick="mpToggle('e-cover',this.value)"> 清除</label>
                                    <div id="e-cover-upload"<?php echo $coverMode === 'upload' ? '' : ' style="display:none"'; ?>><input type="file" name="cover" accept=".jpg,.jpeg,.png,.gif,.webp" style="margin-top:6px;width:100%"></div>
                                    <div id="e-cover-url"<?php echo $coverMode === 'url' ? '' : ' style="display:none"'; ?>><input type="url" name="edit_coverUrl" value="<?php echo htmlspecialchars($s['coverUrl'] ?? ''); ?>" placeholder="https://" style="margin-top:6px;width:100%"></div>
                                </fieldset>

                            </div>
                            <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
                                <button type="submit" class="btn primary">💾 保存修改</button>
                                <a href="extending.php?panel=<?php echo urlencode('MusicPlayer/pages/manage-music.php'); ?>" class="btn btn-xs" style="text-decoration:none">✕ 取消</a>
                            </div>
                        </form>
                    </div>
                </div>
                <script>document.getElementById("mp-modal").addEventListener("click",function(e){if(e.target===this)window.location.href='extending.php?panel=<?php echo urlencode('MusicPlayer/pages/manage-music.php'); ?>'})</script>
                <?php endif; ?>
            <?php endif; ?>

        </div>
        <!-- /.mp-admin -->

        <!-- 试听播放器 -->
        <div id="mp-player" style="display:none;margin:16px auto;max-width:500px;min-height:82px"></div>
        <link rel="stylesheet" href="<?php echo $pluginUrl; ?>/APlayer.min.css">
        <script src="<?php echo $pluginUrl; ?>/APlayer.min.js"></script>

        <script>
function mpToggle(p,m){["upload","url"].forEach(function(k){var e=document.getElementById(p+"-"+k);if(e)e.style.display=k===m?"":"none"})}
!function(){["audio","lyric","cover"].forEach(function(t){var r=document.querySelector("input[name=\""+t+"Mode\"]:checked");if(r)mpToggle("c-"+t,r.value)})}();
var _mpP=null;function mpPlay(d){var e=document.getElementById("mp-player");e.style.display="block";if(_mpP)_mpP.destroy();var a={name:d[0],url:d[1]};if(d[2])a.cover=d[2];if(d[3])a.lrc=d[3];_mpP=new APlayer({container:e,audio:a,theme:"<?php echo $savedTheme; ?>",lrcType:d[3]?3:0});var _a=e.querySelector(".aplayer-author");if(_a)_a.style.display="none"}
        </script>

        </div>
        <!-- /.mp-wrap -->
    </div>
    <!-- /.col-mb-12 col-tb-12 -->
</div>
<!-- /.row typecho-page-main -->

<?php
include 'copyright.php';
include 'footer.php';
