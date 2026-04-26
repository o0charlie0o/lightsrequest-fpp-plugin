<?php
$pages = Array(
	'name' => "Lights Request",
	'type' => "content",
	'page' => "remote_falcon_ui.html");
?>

<li><a href="<?php echo preg_replace('/.*\/plugins\/(.*)/', 'plugin.php?plugin=$1&page=remote_falcon_ui.html', dirname(__FILE__)); ?>">Lights Request</a></li>
