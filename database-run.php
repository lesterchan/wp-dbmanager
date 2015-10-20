<?php
### Check Whether User Can Manage Database
if(!current_user_can('manage_database')) {
	die('Access Denied');
}


### Variables Variables Variables
$base_name = plugin_basename('wp-dbmanager/database-manager.php');
$base_page = 'admin.php?page='.$base_name;
$backup = array();
$backup_options = get_option('dbmanager_options');
$backup['date'] = current_time('timestamp');
$backup['mysqldumppath'] = $backup_options['mysqldumppath'];
$backup['mysqlpath'] = $backup_options['mysqlpath'];
$backup['path'] = $backup_options['path'];


### Form Processing
if(!empty($_POST['do'])) {
	$text = '';
	// Decide What To Do
	switch($_POST['do']) {
		case __('Run', 'wp-dbmanager'):
			check_admin_referer('wp-dbmanager_run');
			$sql_queries2 = trim($_POST['sql_query']);
			$totalquerycount = 0;
			$successquery = 0;
			if($sql_queries2) {
				$sql_queries = array();
				$sql_queries2 = explode("\n", $sql_queries2);
				foreach($sql_queries2 as $sql_query2) {
					$sql_query2 = trim(stripslashes($sql_query2));
					$sql_query2 = preg_replace("/[\r\n]+/", '', $sql_query2);
					if(!empty($sql_query2)) {
						$sql_queries[] = $sql_query2;
					}
				}
				if($sql_queries) {
					foreach( $sql_queries as $sql_query ) {
						if ( preg_match( "/LOAD_FILE/i", $sql_query ) ) {
							$text .= "<p style=\"color: red;\">$sql_query</p>";
							$totalquerycount++;
						} elseif( preg_match( "/^\\s*(select|drop|show|grant) /i", $sql_query ) ) {
							$text .= "<p style=\"color: red;\">$sql_query</p>";
							$totalquerycount++;
						} else if ( preg_match( "/^\\s*(insert|update|replace|delete|create|alter) /i", $sql_query ) ) {
							$run_query = $wpdb->query( $sql_query );
							if( ! $run_query ) {
								$text .= "<p style=\"color: red;\">$sql_query</p>";
							} else {
								$successquery++;
								$text .= "<p style=\"color: green;\">$sql_query</p>";
							}
							$totalquerycount++;
						}
					}
					$text .= '<p style="color: blue;">'.number_format_i18n($successquery).'/'.number_format_i18n($totalquerycount).' '.__('Query(s) Executed Successfully', 'wp-dbmanager').'</p>';
				} else {
					$text = '<p style="color: red;">'.__('Empty Query', 'wp-dbmanager').'</p>';
				}
			} else {
				$text = '<p style="color: red;">'.__('Empty Query', 'wp-dbmanager').'</p>';
			}
			break;
	}
}
?>
<?php if(!empty($text)) { echo '<!-- Last Action --><div id="message" class="updated fade"><p>'.$text.'</p></div>'; } ?>
<!-- Run SQL Query -->
<form method="post" action="<?php echo admin_url('admin.php?page='.plugin_basename(__FILE__)); ?>">
	<?php wp_nonce_field('wp-dbmanager_run'); ?>
	<div class="wrap">
		<h2><?php _e('Run SQL Query', 'wp-dbmanager'); ?></h2>
		<br style="clear" />
		<div>
			<strong><?php _e('Separate Multiple Queries With A New Line', 'wp-dbmanager'); ?></strong><br />
			<p style="color: green;"><?php _e('Use Only INSERT, UPDATE, REPLACE, DELETE, CREATE and ALTER statements.', 'wp-dbmanager'); ?></p>
		</div>
		<table class="form-table">
			<tr>
				<td align="center"><textarea cols="120" rows="30" name="sql_query" style="width: 99%;" dir="ltr" ></textarea></td>
			</tr>
			<tr>
				<td align="center"><input type="submit" name="do" value="<?php _e('Run', 'wp-dbmanager'); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php _e('Cancel', 'wp-dbmanager'); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>
		</table>
		<p>
			<?php _e('1. CREATE statement will return an error, which is perfectly normal due to the database class. To confirm that your table has been created check the Manage Database page.', 'wp-dbmanager'); ?><br />
			<?php _e('2. UPDATE statement may return an error sometimes due to the newly updated value being the same as the previous value.', 'wp-dbmanager'); ?><br />
			<?php _e('3. ALTER statement will return an error because there is no value returned.', 'wp-dbmanager'); ?>
		</p>
	</div>
</form>
