<?php
if (!defined('SYSTEM_ROOT')) { die('Insufficient Permissions'); } 

$s = unserialize(option::get('plugin_wmzz_post'));

if (isset($_GET['ok'])) {
	echo '<div class="alert alert-success">设置保存成功</div>';
}

if (!isset($s['device']) || !in_array(intval($s['device']), array(1, 2, 3, 4))) {
	$s['device'] = 4; // 1=iPhone 2=Android 3=WindowsPhone 4=Windows/Wap（3/4 仍纳入自动切换，非永久废弃）
}
// 每小时自动回帖上限（0=不限），用于显示
if (!isset($s['hour'])) {
	$s['hour'] = 0;
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
			<td>每小时最多自动回帖数量<br/>0 = 不限制。达到上限后本小时剩余时间不再自动回帖，到下一个整点自动恢复。<br/><small>建议结合“发帖时间间隔”使用（例如 6 条/小时），进一步避免被识别为刷帖；只统计自动成功条数。</small></td>
			<td>
				<input type="number" min="0" step="1" class="form-control" name="hour" value="<?php echo isset($s['hour']) ? intval($s['hour']) : 0; ?>" required>
			</td>
		</tr>
		<tr>
			<td>被拒后退避区间（分钟）<br/>所有发帖通道都被拒绝后，按连续被拒次数在这个区间内递增随机等待：第 1 次取下限段、第 2 次取中段、第 3 次起取上限段（每次都随机、不断增大）。<br/><small>留空或填 0 = 默认 20~150（约 20 分钟~2.5 小时）。建议结合“每小时上限”使用。</small></td>
			<td>
				<div>
					<input type="number" min="0" step="1" class="form-control" style="display:inline-block;width:44%" name="back_min" value="<?php echo isset($s['back_min']) ? htmlspecialchars($s['back_min'], ENT_QUOTES) : ''; ?>" placeholder="最小（例：20）">
					<span style="display:inline-block;width:8%;text-align:center"> ~ </span>
					<input type="number" min="0" step="1" class="form-control" style="display:inline-block;width:44%" name="back_max" value="<?php echo isset($s['back_max']) ? htmlspecialchars($s['back_max'], ENT_QUOTES) : ''; ?>" placeholder="最大（例：150）">
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
			<td>首选客户端类型<br/>发帖会自动在 网页端 → 首选 → 其余客户端类型(type 4/2/1/3) 间切换，哪个能发用哪个，无需手工换。<br/><small>推荐 Windows/Wap(type 4) 或 Android(type 2)。</small></td>
			<td>
				<select name="device" class="form-control" required>
					<option value="4" <?php if($s['device'] == '4') echo 'selected' ?> >Windows/Wap（type 4）</option>
					<option value="2" <?php if($s['device'] == '2') echo 'selected' ?> >Android（type 2）</option>
					<option value="1" <?php if($s['device'] == '1') echo 'selected' ?> >iPhone（type 1）</option>
					<option value="3" <?php if($s['device'] == '3') echo 'selected' ?> >WindowsPhone（type 3）</option>
				</select>
			</td>
		</tr>
	</tbody>
</table>
<br/><br/>
<input type="submit" class="btn btn-primary" value="提交更改">
</form>