<div class="wrap">
<h1>Data Package Import</h1>
<form method="POST">
	<?php wp_nonce_field($this->dataAction, 'gndp-nonce'); ?>	
	<input type="hidden" name="gndp_data_action" value="<?php echo $this->dataAction; ?>"/>
	<table class="form-table">
		<tbody>
			<tr>
				<th scope="row"><label for="gndp_table_prefix">Table Prefix:</label></th>
				<td>
					<input type="text" name="table_prefix" id="gndp_table_prefix" value="<?php echo htmlspecialchars($_POST['table_prefix']); ?>" />
					<p class="description">Specify a prefix for the imported tables.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">ZIP file:</th>
				<td>
					<input type="file" name="zip_file" accept=".zip"/>
					<p class="description">Upload a ZIP archive of the data package.</p>
				</td>
			</tr>
		</tbody>
	</table>
	<p class="submit"><input type="submit" class="button-primary" value="Upload" /></p>
</form>
</div>