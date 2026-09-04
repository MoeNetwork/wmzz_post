<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); } 

$s = unserialize(option::get('plugin_wmzz_post'));

if (isset($_GET['ok'])) {
	echo '<div class="alert alert-success">设置保存成功</div>';
}

if (empty($s['device'])) {
	$s['device'] = 4;
}
// 新版用区间 sleep_min/sleep_max；旧版单值 sleep 仅用于历史停顿，展示时留空由用户按新区间填写
if (!isset($s['sleep_min'])) {
	$s['sleep_min'] = '';
}
if (!isset($s['sleep_max'])) {
	$s['sleep_max'] = '';
}
?>
<h3>贴吧帖子云灌水 - 管理</h3><br/>
<form action="setting.php?mod=plugin:wmzz_post" method="post">
<table class="table table-striped">
	<thead>
		<tr>
			<th style="width:45%">参数</th>
			<th style="width:55%">值</th>
		<iframe id="tmp_downloadhelper_iframe" style="display: none;"></iframe></tr>
	</thead>
	<tbody>
		<tr>
			<td>发帖时间间隔（秒）<br/>填一个区间：每条自动回帖后会随机等这个区间内的秒数，再发下一条，每次都不同，像真人慢慢盖楼。<br/>两边都留空或填 0 = 使用默认随机 1~3 分钟（防刷）。<br/>填同一个数 = 固定间隔。<br/><small>注：系统每 1 分钟检查一次，间隔会按分钟凑整生效（如填 100~300 秒，实际约 2~5 分钟随机）。账号自身设置的“固定 X 分钟”会叠加在此之上。</small></td>
			<td>
				<div>
					<input type="number" min="0" step="1" class="form-control" style="display:inline-block;width:44%" name="sleep_min" value="<?php echo htmlspecialchars($s['sleep_min'], ENT_QUOTES); ?>" placeholder="最小（例：100）">
					<span style="display:inline-block;width:8%;text-align:center"> ~ </span>
					<input type="number" min="0" step="1" class="form-control" style="display:inline-block;width:44%" name="sleep_max" value="<?php echo htmlspecialchars($s['sleep_max'], ENT_QUOTES); ?>" placeholder="最大（例：300）">
				</div>
			</td>
		</tr>
		<tr>
			<td>用户最大设置帖子数<br/>0 为无限，优先于总灌水量设置</td>
			<td>
				<input type="number" min="0" step="1" class="form-control" name="lmax" value="<?php echo $s['lmax'] ?>" required>
			</td>
		</tr>
		<tr>
			<td>用户最大单贴灌水帖子数<br/>0 为无限，优先于总灌水量设置</td>
			<td>
				<input type="number" min="0" step="1" class="form-control" name="cmax" value="<?php echo $s['cmax'] ?>" required>
			</td>
		</tr>
		<tr>
			<td>用户最大总灌水量<br/>0 为无限，计算公式： 设置的帖子数 x 每个帖子的灌水数量 = 总灌水量</td>
			<td>
				<input type="number" min="0" step="1" class="form-control" name="max" value="<?php echo $s['max'] ?>" required>
			</td>
		</tr>
		<tr>
			<td>预设灌水内容<br/><br/>每行一个，支持 HTML<br>可以使用 &lt;br/&gt; 换行</td>
			<td>
				<textarea class="form-control" name="defcont" style="height:400px;"><?php echo $s['defcont'] ?></textarea>
			</td>
		</tr>
		<tr>
			<td>模拟的客户端设备</td>
			<td>
				<select name="device" class="form-control" required>
					<option value="4" <?php if($s['device'] == '4') echo 'selected' ?> >Windows 8</option>
					<option value="3" <?php if($s['device'] == '3') echo 'selected' ?> >Windows Phone</option>
					<option value="2" <?php if($s['device'] == '2') echo 'selected' ?> >Android</option>
					<option value="1" <?php if($s['device'] == '1') echo 'selected' ?> >iPhone</option>
				</select>
			</td>
		</tr>
	</tbody>
</table>
<br/><br/>
<input type="submit" class="btn btn-primary" value="提交更改">
</form>