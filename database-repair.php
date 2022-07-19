<?php
### Check Whether User Can Manage Database
if ( ! current_user_can( 'install_plugins' ) ) {
	die( 'Access Denied' );
}


### Variables Variables Variables
$base_name = plugin_basename('wp-dbmanager/database-manager.php');
$base_page = 'admin.php?page='.$base_name;

### Form Processing
if(!empty($_POST['do'])) {
	// Lets Prepare The Variables
	$repair = $_POST['repair'];
	$text = '';

	// Decide What To Do
	switch($_POST['do']) {
		case __('Repair', 'wp-dbmanager'):
			check_admin_referer('wp-dbmanager_repair');
			if(!empty($repair)) {
				$tables_string = '';
				foreach($repair as $key => $value) {
					if($value == 'yes') {
						$tables_string .=  '`, `'.$key;
					}
				}
			} else {
				$text = '<p style="color: red;">'.__('No Tables Selected', 'wp-dbmanager').'</p>';
			}
			$selected_tables = substr($tables_string, 2);
			$selected_tables .= '`';
			if(!empty($selected_tables)) {
				$repair2 = $wpdb->query("REPAIR TABLE $selected_tables");
				if(!$repair2) {
					$text = '<p style="color: red;">'.sprintf(__('Table(s) \'%s\' NOT Repaired', 'wp-dbmanager'), str_replace('`', '', $selected_tables)).'</p>';
				} else {
					$text = '<p style="color: green;">'.sprintf(__('Table(s) \'%s\' Repaired', 'wp-dbmanager'), str_replace('`', '', $selected_tables)).'</p>';
				}
			}
			break;
	}
}


### Show Tables
$tables = $wpdb->get_col("SHOW TABLES");
?>
<?php if(!empty($text)) { echo '<!-- Last Action --><div id="message" class="updated fade"><p>'.$text.'</p></div>'; } ?>
<!-- Repair Database -->
<form method="post" action="<?php echo admin_url('admin.php?page='.plugin_basename(__FILE__)); ?>">
	<?php wp_nonce_field('wp-dbmanager_repair'); ?>
	<div class="wrap">
		<h2><?php _e('Repair Database', 'wp-dbmanager'); ?></h2>
		<br style="clear" />
		<table class="widefat">
			<thead>
				<tr>
					<th><?php _e('Tables', 'wp-dbmanager'); ?></th>
					<th><?php _e('Options', 'wp-dbmanager'); ?></th>
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
						echo "<td><input type=\"radio\" id=\"$table_name-no\" name=\"repair[$table_name]\" value=\"no\" />&nbsp;<label for=\"$table_name-no\">".__('No', 'wp-dbmanager')."</label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type=\"radio\" id=\"$table_name-yes\" name=\"repair[$table_name]\" value=\"yes\" checked=\"checked\" />&nbsp;<label for=\"$table_name-yes\">".__('Yes', 'wp-dbmanager').'</label></td></tr>';
					}
				?>
			<tr>
				<td colspan="2" align="center"><input type="submit" name="do" value="<?php _e('Repair', 'wp-dbmanager'); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php _e('Cancel', 'wp-dbmanager'); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>