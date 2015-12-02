<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); } 

$s = unserialize(option::get('plugin_wmzz_post'));

if (isset($_GET['ok'])) {
	echo '<div class="alert alert-success">设置保存成功</div>';
}

if (empty($s['device'])) {
	$s['device'] = 4;
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
			<td>发帖时间间隔<br/>0 为无间隔，单位为秒。设置间隔可避免发帖过快及验证码的问题，但是可能会导致程序超时</td>
			<td>
				<input type="number" min="0" step="1" class="form-control" name="sleep" value="<?php echo $s['sleep'] ?>" required>
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
			<td>单次计划任务灌水数量<br/>设置执行一次计划任务为多少个帖子灌水，至少为 1</td>
			<td>
				<input type="number" min="1" step="1" class="form-control" name="rem" value="<?php echo $s['rem'] ?>" required>
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