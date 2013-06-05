<?php
/*
+----------------------------------------------------------------+
|																							|
|	WordPress 2.8 Plugin: WP-DBManager 2.63								|
|	Copyright (c) 2009 Lester "GaMerZ" Chan									|
|																							|
|	File Written By:																	|
|	- Lester "GaMerZ" Chan															|
|	- http://lesterchan.net															|
|																							|
|	File Information:																	|
|	- Database Optimize																|
|	- wp-content/plugins/wp-dbmanager/database-optimize.php		|
|																							|
+----------------------------------------------------------------+
*/


### Check Whether User Can Manage Database
if(!current_user_can('manage_database')) {
	die('Access Denied');
}


### Variables Variables Variables
$base_name = plugin_basename('wp-dbmanager/database-manager.php');
$base_page = 'admin.php?page='.$base_name;

### Form Processing 
if($_POST['do']) {
	// Lets Prepare The Variables
	$optimize = $_POST['optimize'];

	// Decide What To Do
	switch($_POST['do']) {
		case __('Optimize', 'wp-dbmanager'):
			check_admin_referer('wp-dbmanager_optimize');
			if(!empty($optimize)) {
				foreach($optimize as $key => $value) {
					if($value == 'yes') {
						$tables_string .=  '`, `'.$key;
					}
				}
			} else {
				$text = '<font color="red">'.__('No Tables Selected', 'wp-dbmanager').'</font>';
			}
			$selected_tables = substr($tables_string, 3);
			$selected_tables .= '`';
			if(!empty($selected_tables)) {
				$optimize2 = $wpdb->query("OPTIMIZE TABLE $selected_tables");
				if(!$optimize2) {
					$text = '<font color="red">'.sprintf(__('Table(s) \'%s\' NOT Optimized', 'wp-dbmanager'), str_replace('`', '', $selected_tables)).'</font>';
				} else {
					$text = '<font color="green">'.sprintf(__('Table(s) \'%s\' Optimized', 'wp-dbmanager'), str_replace('`', '', $selected_tables)).'</font>';
				}
			}
			break;
	}
}


### Show Tables
$tables = $wpdb->get_col("SHOW TABLES");
?>
<?php if(!empty($text)) { echo '<!-- Last Action --><div id="message" class="updated fade"><p>'.$text.'</p></div>'; } ?>
<!-- Optimize Database -->
<form method="post" action="<?php echo admin_url('admin.php?page='.plugin_basename(__FILE__)); ?>">
	<?php wp_nonce_field('wp-dbmanager_optimize'); ?>
	<div class="wrap">
		<div id="icon-wp-dbmanager" class="icon32"><br /></div>
		<h2><?php _e('Optimize Database', 'wp-dbmanager'); ?></h2>
		<br style="clear" />	
		<table class="widefat">
			<thead>
				<tr>
					<th><?php _e('Tables', 'wp-dbmanager'); ?></th>
					<th><?php _e('Options', 'wp-dbmanager'); ?></th>
				</tr>
			</thead>
				<?php
					foreach($tables as $table_name) {
						if($no%2 == 0) {
							$style = '';							
						} else {
							$style = ' class="alternate"';
						}
						$no++;
						echo "<tr$style><th align=\"left\" scope=\"row\">$table_name</th>\n";
						echo "<td><input type=\"radio\" id=\"$table_name-no\" name=\"optimize[$table_name]\" value=\"no\" />&nbsp;<label for=\"$table_name-no\">".__('No', 'wp-dbmanager')."</label>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<input type=\"radio\" id=\"$table_name-yes\" name=\"optimize[$table_name]\" value=\"yes\" checked=\"checked\" />&nbsp;<label for=\"$table_name-yes\">".__('Yes', 'wp-dbmanager').'</label></td></tr>';
					}
				?>
			<tr>
				<td colspan="2" align="center"><?php _e('Database should be optimize once every month.', 'wp-dbmanager'); ?></td>
			</tr>
			<tr>
				<td colspan="2" align="center"><input type="submit" name="do" value="<?php _e('Optimize', 'wp-dbmanager'); ?>" class="button" />&nbsp;&nbsp;<input type="button" name="cancel" value="<?php _e('Cancel', 'wp-dbmanager'); ?>" class="button" onclick="javascript:history.go(-1)" /></td>
			</tr>
		</table>
	</div>
</form>