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
	// Lets Prepare The Variables
	$emptydrop = (!empty($_POST['emptydrop']) ? $_POST['emptydrop'] : array());
	$text = '';

	// Decide What To Do
	switch($_POST['do']) {
		case __('Empty/Drop', 'wp-dbmanager'):
			check_admin_referer('wp-dbmanager_empty');
			$empty_tables = array();
			$drop_tables = '';
			if(!empty($emptydrop)) {
				foreach($emptydrop as $key => $value) {
					if($value == 'empty') {
						$empty_tables[] = $key;
					} elseif($value == 'drop') {
						$drop_tables .=  ', '.$key;
					}
				}
			} else {
				$text = '<p style="color: red;">'.__('No Tables Selected.', 'wp-dbmanager').'</p>';
			}
			$drop_tables = substr($drop_tables, 2);
			if(!empty($empty_tables)) {
				foreach($empty_tables as $empty_table) {
					$empty_query = $wpdb->query("TRUNCATE $empty_table");
					$text .= '<p style="color: green;">'.sprintf(__('Table \'%s\' Emptied', 'wp-dbmanager'), $empty_table).'</p>';
				}
			}
			if(!empty($drop_tables)) {
				$drop_query = $wpdb->query("DROP TABLE $drop_tables");
				$text = '<p style="color: green;">'.sprintf(__('Table(s) \'%s\' Dropped', 'wp-dbmanager'), $drop_tables).'</p>';
			}
			break;
	}
}


### Show Tables
$tables = $wpdb->get_col("SHOW TABLES");
?>
<?php if(!empty($text)) { echo '<!-- Last Action --><div id="message" class="updated fade"><p>'.$text.'</p></div>'; } ?>
<!-- Empty/Drop Tables -->
<form method="post" action="<?php echo admin_url('admin.php?page='.plugin_basename(__FILE__)); ?>">
	<?php wp_nonce_field('wp-dbmanager_empty'); ?>
	<div class="wrap">
		<h2><?php _e('Empty/Drop Tables', 'wp-dbmanager'); ?></h2>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php _e('Tables', 'wp-dbmanager'); ?></th>
					<th><?php _e('Empty', 'wp-dbmanager'); ?> <sup><?php _e('1', 'wp-dbmanager'); ?></sup></th>
					<th><?php _e('Drop', 'wp-dbmanager'); ?> <sup><?php _e('2', 'wp-dbmanager'); ?></sup></th>
				</tr>
			</thead>
				<?php
					$no = 0;
					foreach($tables as $table_name) {
						if($no%2 == 0) {
							$style = '';
						} else {
							$style = ' class="alternate"';
						}
						$no++;
						echo "<tr $style><th align=\"left\" scope=\"row\">$table_name</th>\n";
						echo "<td><input type=\"radio\" id=\"$table_name-empty\" name=\"emptydrop[$table_name]\" value=\"empty\" />&nbsp;<label for=\"$table_name-empty\">".__('Empty', 'wp-dbmanager').'</label></td>';
						echo "<td><input type=\"radio\" id=\"$table_name-drop\" name=\"emptydrop[$table_name]\" value=\"drop\" />&nbsp;<label for=\"$table_name-drop\">".__('Drop', 'wp-dbmanager').'</label></td></tr>';
					}
				?>
			<tr>
				<td colspan="3">
					<?php _e('1. EMPTYING a table means all the rows in the table will be deleted. This action is not REVERSIBLE.', 'wp-dbmanager'); ?>
					<br />
					<?php _e('2. DROPPING a table means deleting the table. This action is not REVERSIBLE.', 'wp-dbmanager'); ?>
				</td>
			</tr>
			<tr>
				<td colspan="3" align="center"><input type="submit" name="do" value="<?php _e('Empty/Drop', 'wp-dbmanager'); ?>" class="button" onclick="return confirm('<?php _e('You Are About To Empty Or Drop The Selected Databases.\nThis Action Is Not Reversible.\n\n Choose [Cancel] to stop, [Ok] to delete.', 'wp-dbmanager'); ?>')" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php _e('Cancel', 'wp-dbmanager'); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>