<?php
	/*
		Plugin Name: Gravity Forms Conditional Notifications Add-On
		Plugin URI: https://github.com/soulseekah/Gravity-Forms-Conditional-Notifications
		Description: Adds a conditional notification message interface for field states of a form
		Version: 1
		Author URI: http://codeseekah.com
		Author: Gennady Kovshenin
		License: WTF-PL v.1
	*/


	class GFConditionalNotifications {
		private static $instance = null;
		private static $textdomain = 'gravityforms-conditional-notifications';

		public function __construct() {
			if ( is_object( self::$instance ) && get_class( self::$instance == __CLASS__ ) )
				wp_die( __CLASS__.' can have only one instance; won\'t initialize, use '.__CLASS__.'::get_instance()' );
			self::$instance = $this;

			$this->bootstrap();
		}

		public static function get_instance() {
			return ( get_class( self::$instance ) == __CLASS__ ) ? self::$instance : new self;
		}

		public function bootstrap() {

			$this->notifications = array(); /* contains UI messages */

			/* Attach hooks and other early initialization */
			add_action( 'plugins_loaded', array( $this, 'preinit' ) );

			/* Back-end user-interface */
            add_filter( 'gform_addon_navigation', array( $this, 'add_conditional_notifications_ui_item' ) );
			add_action( 'init', array( $this, 'process_ui_save_request' ) );

			/* User notification */
			add_filter( 'gform_disable_user_notification', array( $this, 'hijack_user_notification' ), null, 3 );
		}

		public function preinit() {
			/* Load languages if available */
			load_plugin_textdomain( self::$textdomain, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
		}

		public function hijack_user_notification( $disable, $form, $lead ) {
			/* If the form has valid notification conditions this method will return false to disable notifications and take care of notifying users itself */

			if ( !isset( $form['notification_conditions'] ) || !is_array( $form['notification_conditions'] ) || !sizeof( $form['notification_conditions'] ) )
				return $disable; /* no conditions to play around with */

			/* Check whether conditions inside of this form are valid */
			if ( !self::conditions_valid( &$form['fields'], &$form['notification_conditions'] ) ) return $disable;

			/* Look for a condition that matches */
			foreach ( $form['notification_conditions'] as $condition ) {
				if ( !isset( $lead[$condition['field']] ) ) continue; /* the lead hasn't got this field (optional) */
				if ( $lead[$condition['field']] != $condition['value'] ) continue; /* value condition has not been met */

				if ( $condition['status'] === true ) {
					/* modify the message */

					$form['autoResponder']['message'] = $condition['message'];
					$form['autoResponder']['subject'] = $condition['subject'];
					$form['autoResponder']['from'] = $condition['from'];
					$form['autoResponder']['fromName'] = $condition['fromName'];
					$form['autoResponder']['replyTo'] = $condition['replyTo'];
					$form['autoResponder']['toField'] = $condition['toField'];

					GFCommon::send_user_notification( $form, $lead ); /* send the notification */
				} /* if status is false we'll fall through and suppress */

				return true; /* suppress notification */
			}

			/* Whatever... */
			return $disable;
		}

		public function add_conditional_notifications_ui_item() {
			$has_full_access = current_user_can( 'gform_full_access' );
            /* Adds a new menu item to Gravity Forms */
            $conditional_notifications_item = array(
                    'name' => 'gf_conditional_notifications_ui',
                    'label' => __( 'Conditional Notifications', self::$textdomain ),
                    'callback' => array( $this, 'conditional_notifications_ui' ),
                    'permission' => 'gform_full_access'
                );
            $menu_items []= $conditional_notifications_item;
            return $menu_items;
		}

		public function conditional_notifications_ui() {
			/* Displays the backend Conditional Notifications UI */
            $forms = RGFormsModel::get_forms( null, 'title' );
            $id = RGForms::get( 'id' );
            if( sizeof($forms) == 0 ) {
                ?>
                    <div style="margin:50px 0 0 10px;">
	                   <?php echo sprintf(__("You don't have any active forms. Let's go %screate one%s", "gravityforms"), '<a href="?page=gravityforms.php&id=0">', '</a>'); ?>
                    </div>
                <?php
                return;
            } else {
                if( empty($id) ) $form_id = $forms[0]->id;
                else $form_id = $id;
            }
            $form = RGFormsModel::get_form_meta( $form_id );
            ?>
				<link rel="stylesheet" href="<?php echo GFCommon::get_base_url() ?>/css/admin.css" type="text/css" />
				<div class="wrap">

					<?php self::ui_header( $form, $forms ); ?>

					<?php foreach ( $this->notifications as $message ) echo '<div class="updated"><p>' . $message . '</p></div>'; ?>

					<div style="clear: both;">
						<p>All fields in a form that have conditional logic can have conditional notification logic. If a field is not conditioned here it will the form will fallback to default notification behavior set in Form Settings. Field relationships are OR relationships, meaning that as soon as one condition is met other conditions will not be checked. There is no guaranteed order in what fields are checked first, so it is important to set strict conditions that do not contradict other fields, otherwise undefined behaviour will follow.</p>
						<p>If used from values/fields are modified, moved or altered, conditional notification logic has to be revisited. All the usual placeholders are supported. A list of all placeholders for the form can be viewed in the stock Notification area.</p>
						<p>Subject, reply to, etc. fields override the default; if by default notifications are switched off these have to be filled.</p>
			        </div>
					<?php
						if ( !sizeof( GFCommon::get_email_fields( $form ) ) ):
							?>
							<div class="gold_notice">
								<p><?php echo sprintf(__("Your form does not have any %semail%s field.", "gravityforms"), "<strong>", "</strong>"); ?></p>
								<p><?php echo sprintf(__("Sending notifications to users require that the form has at least one email field. %sEdit your form%s", "gravityforms"),'<a href="?page=gf_edit_forms&id=' . absint( $form['id'] ) . '">', '</a>'); ?></p>
							</div>
							<?php
						else:
					?>
					<div>
						<script type="text/javascript">
							/* The available conditional fields and their values */
							/* MD5 is used to hash the text data out of any invalid characters to be used as keys */
							gfcn = {};
							gfcn.available_fields = {};
							<?php foreach ( $form['fields'] as $field ): ?>
								<?php if ( !in_array( $field['type'], array( 'select', 'checkbox', 'radio' ) ) ) continue; ?>
								gfcn.available_fields[<?php echo $field['id'] ?>] = {};
								gfcn.available_fields[<?php echo $field['id'] ?>]['label'] = '<?php echo esc_js($field['label']); ?>';
								gfcn.available_fields[<?php echo $field['id'] ?>]['choices'] = {};
								<?php foreach ( $field['choices'] as $choice_id => $choice ): ?>
									gfcn.available_fields[<?php echo $field['id'] ?>]['choices'][<?php echo $choice_id; ?>] = {};
									gfcn.available_fields[<?php echo $field['id'] ?>]['choices'][<?php echo $choice_id; ?>]['label'] = '<?php echo esc_js($choice['text']); ?>';
									gfcn.available_fields[<?php echo $field['id'] ?>]['choices'][<?php echo $choice_id; ?>]['value'] = '<?php echo esc_js($choice['value']); ?>';
									gfcn.available_fields[<?php echo $field['id'] ?>]['choices'][<?php echo $choice_id; ?>]['md5'] = '<?php echo esc_js(md5($choice['value'])); ?>';
								<?php endforeach; ?>
							<?php endforeach; ?>

							gfcn.conditions = {};

							<?php
								$notification_conditions = ( isset( $form['notification_conditions'] ) && is_array( $form['notification_conditions'] ) ) ? $form['notification_conditions'] : array();
								foreach ( $notification_conditions as $condition ):
									?>
										<?php
											if ( !isset( $condition['field'] ) ) $condition['field'] = '';
											if ( !isset( $condition['value'] ) ) $condition['value'] = '';
											if ( !isset( $condition['message'] ) ) $condition['message'] = false;
											if ( !isset( $condition['subject'] ) ) $condition['subject'] = '';
											if ( !isset( $condition['toField'] ) ) $condition['toField'] = 0;
											if ( !isset( $condition['from'] ) ) $condition['from'] = '';
											if ( !isset( $condition['replyTo'] ) ) $condition['replyTo'] = '';
											if ( !isset( $condition['fromName'] ) ) $condition['fromName'] = '';
										?>

										gfcn.conditions['<?php echo esc_js($condition['field'] . '_' . md5($condition['value'])); ?>'] = {};
										gfcn.conditions['<?php echo esc_js($condition['field'] . '_' . md5($condition['value'])); ?>']['field'] = '<?php echo esc_js($condition['field']); ?>';
										gfcn.conditions['<?php echo esc_js($condition['field'] . '_' . md5($condition['value'])); ?>']['value'] = '<?php echo esc_js($condition['value'])?>';
										gfcn.conditions['<?php echo esc_js($condition['field'] . '_' . md5($condition['value'])); ?>']['message'] = <?php echo $condition['message'] === false ? 'false' : '\'' . esc_js($condition['message']) . '\''; ?>;
										gfcn.conditions['<?php echo esc_js($condition['field'] . '_' . md5($condition['value'])); ?>']['subject'] = '<?php echo esc_js($condition['subject']); ?>';
										gfcn.conditions['<?php echo esc_js($condition['field'] . '_' . md5($condition['value'])); ?>']['to'] = <?php echo $condition['toField']; ?>;
										gfcn.conditions['<?php echo esc_js($condition['field'] . '_' . md5($condition['value'])); ?>']['from'] = '<?php echo esc_js($condition['from']); ?>';
										gfcn.conditions['<?php echo esc_js($condition['field'] . '_' . md5($condition['value'])); ?>']['reply'] = '<?php echo esc_js($condition['replyTo']); ?>';
										gfcn.conditions['<?php echo esc_js($condition['field'] . '_' . md5($condition['value'])); ?>']['from_name'] = '<?php echo esc_js($condition['fromName']); ?>';
									<?php
								endforeach;
							?>

							gfcn.condition_num = 0;
							gfcn.new_condition = function(target) {
								/* add a condition area */
								this.condition_num++; /* increase condition number unique identifier */
								jQuery('.gfcn_editors li:last').before('<li class="gfcn_editor" id="gfcn_editor_'+this.condition_num+'">'+this.new_editor(this.condition_num)+'</li>');
								/* hydrate condition fields */
								var condition_field = jQuery('#gfcn_editor_'+this.condition_num+' select[name=gfcn_notification_condition_field\\['+this.condition_num+'\\]]');
								jQuery.each(this.available_fields, function(i, e) {
									condition_field.append('<option value="'+i+'">'+e.label+'</option>');
								});

								/* set default choice */
								var condition_status = jQuery('#gfcn_editor_'+this.condition_num+' input[name=gfcn_notification_condition_status\\['+this.condition_num+'\\]]');
								jQuery(condition_status[0]).attr('checked', 'checked'); // check it
								condition_status.bind('change', function(e) {
									if (jQuery(e.currentTarget).val() == "0")
										jQuery(e.currentTarget).parent().parent().find('.gfcn-details').hide();
									else jQuery(e.currentTarget).parent().parent().find('.gfcn-details').show();
								});

								return this.condition_num;
							}
							gfcn.remove_condition = function (target) {
								/* remove a condition area (all controls, notification) */
								jQuery(target).parents('li.gfcn_editor').remove();
							}

							gfcn.change_field = function(target) {
								/* hydrate the values for a selected field */
								var condition_id = jQuery(target).attr('name').replace(/gfcn_notification_condition_field\[(.*)\]/, '$1');
								var field_value = jQuery(target).val();

								var condition_value = jQuery('#gfcn_editor_'+condition_id+' select[name=gfcn_notification_condition_value\\['+condition_id+'\\]]');
								condition_value.html('<option value="-">-</option>');
								jQuery.each(this.available_fields[field_value].choices, function(i, e) {
									condition_value.append('<option value="'+e.value+'">'+e.label+'</option>');
								});
							}

							gfcn.new_editor = function(id) {
								var out = '';

								out += '<div class="gfcn_editor_controls" style="float: left;">';

									out += 'If <select name="gfcn_notification_condition_field['+id+']" onchange="gfcn.change_field(this);"><option value="-">-</option></select> is <select name="gfcn_notification_condition_value['+id+']"><option value="-">-</option></select>';
									out += '<input type="radio" name="gfcn_notification_condition_status['+id+']" value="1" /> send the following message';
									out += '<input type="radio" name="gfcn_notification_condition_status['+id+']" value="0" /> do not send anything';

								out += '</div>';
								out += '<input style="float: right;" type="button" onclick="gfcn.remove_condition(this);" value="remove"';
								out += '<div style="clear:both"></div>';

								out += '<div class="gfcn-details" style="clear: both;">';
									out += 'Subject: <input type="text" value="" name="gfcn_notification_condition_subject['+id+']" id="gfcn_notification_condition_subject_'+id+'" class="fieldwidth-1">';
									out += '<br />To: <select name="gfcn_notification_condition_to['+id+']" id="gfcn_notification_condition_to_'+id+'">';
										<?php foreach ( GFCommon::get_email_fields( $form ) as $email_field ): ?>
											out += '<option value="<?php echo $email_field['id']; ?>"><?php echo $email_field['label']; ?></option>';
										<?php endforeach; ?>
									out += '</select>';
									out += '<br />From email: <input type="text" name="gfcn_notification_condition_from['+id+']" id="gfcn_notification_condition_from_'+id+'" class="fieldwidth-2">';
									out += '<br />From name: <input type="text" name="gfcn_notification_condition_from_name['+id+']" id="gfcn_notification_condition_from_name_'+id+'" class="fieldwidth-2">';
									out += '<br />Reply to: <input type="text" name="gfcn_notification_condition_reply['+id+']" id="gfcn_notification_condition_reply'+id+'" class="fieldwidth-2">';
									out += '<textarea name="gfcn_notification_condition_message['+id+']" id="gfcn_notification_condition_message_'+id+'" class="fieldwidth-1 fieldheight-1" ></textarea>';
								out += '</div>';

								out += '<div style="clear:both"></div>';

								return out;
							}

							gfcn.init = function() {
								/* for each condition create a new editor */
								jQuery.each(gfcn.conditions, function(i, e) {
									var condition_id = gfcn.new_condition(null);
									jQuery('#gfcn_editor_'+condition_id+' textarea').html(e.message);
									jQuery('#gfcn_editor_'+condition_id+' select[name=gfcn_notification_condition_to\\['+condition_id+'\\]]').val(e.toField);
									jQuery('#gfcn_editor_'+condition_id+' input[name=gfcn_notification_condition_subject\\['+condition_id+'\\]]').val(e.subject);
									if (!e.from) e.from = '{admin_email}'; /* default placeholder */
									jQuery('#gfcn_editor_'+condition_id+' input[name=gfcn_notification_condition_from\\['+condition_id+'\\]]').val(e.from);
									jQuery('#gfcn_editor_'+condition_id+' input[name=gfcn_notification_condition_reply\\['+condition_id+'\\]]').val(e.reply);
									jQuery('#gfcn_editor_'+condition_id+' input[name=gfcn_notification_condition_from_name\\['+condition_id+'\\]]').val(e.from_name);
									jQuery('#gfcn_editor_'+condition_id+' select[name=gfcn_notification_condition_field\\['+condition_id+'\\]]').val(e.field).trigger('change');
									jQuery('#gfcn_editor_'+condition_id+' select[name=gfcn_notification_condition_value\\['+condition_id+'\\]]').val(e.value);
									var condition_status = e.message === false ? "1" : "0";
									jQuery('#gfcn_editor_'+condition_id+' input[name=gfcn_notification_condition_status\\['+condition_id+'\\]]:nth('+condition_status+')').attr('checked', 'checked').trigger('change');
								});

							}; jQuery('document').ready(gfcn.init);
						</script>
						<form method="POST">
							<?php wp_nonce_field( 'gfcn_save_notifications', 'gfcn_admin_nonce' ); ?>
							<input type="hidden" name="gfcn_form_id" value="<?php echo $form['id']; ?>">
							<ul class="gfcn_editors">
								<li class="gfcn_controls">
									<input type="submit" value="Submit" name="gfcn_submit">
									<input type="button" value="Add new condition" onclick="gfcn.new_condition(this);">
								</li>
							</ul>
						</form>
					</div>
					<?php endif; ?>
				</div> <!-- /wrap -->
            <?php
		}

		public function process_ui_save_request() {
			/* Process a save request */
			if ( !isset($_POST['gfcn_submit']) ) return;
			check_admin_referer( 'gfcn_save_notifications', 'gfcn_admin_nonce' );

			$form_id = isset( $_POST['gfcn_form_id'] ) ? intval( $_POST['gfcn_form_id'] ) : 0;
			if ( $form_id <= 0 ) return; /* an invalid form id? */

			$form = @RGFormsModel::get_form( $form_id ); /* what do you mean no form? */
			if ( !isset( $form->id ) || $form->id != $form_id ) return;
			$form->meta = RGFormsModel::get_form_meta( $form->id );

			if ( !isset( $_POST['gfcn_notification_condition_field'] ) && !isset( $_POST['gfcn_notification_condition_value'] ) && !isset( $_POST['gfcn_notification_condition_status'] ) && !isset( $_POST['gfcn_notification_condition_message'] ) ) {
				unset( $form->meta['notification_conditions'] );
				RGFormsModel::update_form_meta( $form->id, $form->meta );
				$this->notifications []= __( 'Conditions have been successfully saved', self::$textdomain );
			}

			if ( !isset( $_POST['gfcn_notification_condition_field'] ) ) return; /* no condition fields? */
			if ( !isset( $_POST['gfcn_notification_condition_value'] ) ) return; /* no condition values? */
			if ( !isset( $_POST['gfcn_notification_condition_status'] ) ) return; /* no condition status? */
			if ( !isset( $_POST['gfcn_notification_condition_message'] ) ) return; /* no condition message? */
			if ( !isset( $_POST['gfcn_notification_condition_subject'] ) ) return; /* no condition subject? */
			if ( !isset( $_POST['gfcn_notification_condition_from'] ) ) return; /* no condition from? */
			if ( !isset( $_POST['gfcn_notification_condition_reply'] ) ) return; /* no condition reply? */
			if ( !isset( $_POST['gfcn_notification_condition_from_name'] ) ) return; /* no condition from name? */
			if ( !isset( $_POST['gfcn_notification_condition_to'] ) ) return; /* no condition from name? */

			/* transform into an array if is single */
			if ( !is_array( $_POST['gfcn_notification_condition_field'] ) ) $_POST['gfcn_notification_condition_field'] = array( $_POST['gfcn_notification_condition_field'] );
			if ( !is_array( $_POST['gfcn_notification_condition_value'] ) ) $_POST['gfcn_notification_condition_value'] = array( $_POST['gfcn_notification_condition_value'] );
			if ( !is_array( $_POST['gfcn_notification_condition_status'] ) ) $_POST['gfcn_notification_condition_status'] = array( $_POST['gfcn_notification_condition_status'] );
			if ( !is_array( $_POST['gfcn_notification_condition_message'] ) ) $_POST['gfcn_notification_condition_message'] = array( $_POST['gfcn_notification_condition_message'] );
			if ( !is_array( $_POST['gfcn_notification_condition_subject'] ) ) $_POST['gfcn_notification_condition_subject'] = array( $_POST['gfcn_notification_condition_subject'] );
			if ( !is_array( $_POST['gfcn_notification_condition_from'] ) ) $_POST['gfcn_notification_condition_from'] = array( $_POST['gfcn_notification_condition_from'] );
			if ( !is_array( $_POST['gfcn_notification_condition_reply'] ) ) $_POST['gfcn_notification_condition_reply'] = array( $_POST['gfcn_notification_condition_reply'] );
			if ( !is_array( $_POST['gfcn_notification_condition_from_name'] ) ) $_POST['gfcn_notification_condition_from_name'] = array( $_POST['gfcn_notification_condition_from_name'] );
			if ( !is_array( $_POST['gfcn_notification_condition_to'] ) ) $_POST['gfcn_notification_condition_to'] = array( $_POST['gfcn_notification_condition_to'] );

			$conditions = array();
			foreach ( $_POST['gfcn_notification_condition_field'] as $index => $value ) {
				$condition = array();

				$condition['field'] = $value;

				if ( !isset( $_POST['gfcn_notification_condition_value'][$index] ) ) return;
				if ( !isset( $_POST['gfcn_notification_condition_status'][$index] ) ) return;

				$condition['value'] = $_POST['gfcn_notification_condition_value'][$index];
				$condition['status'] = (boolean)$_POST['gfcn_notification_condition_status'][$index];
				$condition['message'] = $condition['status'] ? $_POST['gfcn_notification_condition_message'][$index] : false;
				$condition['subject'] = $condition['status'] ? $_POST['gfcn_notification_condition_subject'][$index] : false;
				$condition['toField'] = $condition['status'] ? $_POST['gfcn_notification_condition_to'][$index] : false;
				$condition['from'] = $condition['status'] ? $_POST['gfcn_notification_condition_from'][$index] : false;
				$condition['fromName'] = $condition['status'] ? $_POST['gfcn_notification_condition_from_name'][$index] : false;
				$condition['replyTo'] = $condition['status'] ? $_POST['gfcn_notification_condition_reply'][$index] : false;

				if ( !self::condition_valid( $form->meta['fields'], $condition ) ) return false; /* condition is not one of the valid ones */

				$conditions[$condition['field'] . '_' . md5( $condition['value'] )] = $condition; /* store condition */
			}

			$form->meta['notification_conditions'] = $conditions;
			RGFormsModel::update_form_meta( $form->id, $form->meta );

			$this->notifications []= __( 'Conditions have been successfully saved', self::$textdomain );
		}

		private static function conditions_valid( &$fields, &$conditions ) {
			/* Checks each and every conditions for valid fields and values */
			foreach ( $conditions as $condition )
				if ( !self::condition_valid( $fields, $condition ) ) return false;
			return true;
		}

		private static function condition_valid( &$fields, &$condition ) {
			/* Checks condition for valid fields and values */
			foreach ( $fields as $field ) {
				if ( $field['id'] == $condition['field'] ) {
					if ( !isset( $field['choices'] ) ) continue;
					foreach ( $field['choices'] as $choice )
						if ( $choice['value'] == $condition['value'] ) return true;
				}
			}
			return false;
		}

		private static function ui_header( $form, &$forms ) {
			$form_id = $form['id'];
			?>
				<div class="icon32" id="gravity-entry-icon"><br></div>
				<h2><?php _e( 'Conditional Notifications for ', self::$textdomain ); echo $form['title']; ?></h2>

				<script type="text/javascript">
					function GF_ReplaceQuery(key, newValue){
						var new_query = "";
						var query = document.location.search.substring(1);
						var ary = query.split("&");
						var has_key=false;
						for (i=0; i < ary.length; i++) {
							var key_value = ary[i].split("=");

							if (key_value[0] == key){
								new_query += key + "=" + newValue + "&";
								has_key = true;
							}
							else if(key_value[0] != "display_settings"){
								new_query += key_value[0] + "=" + key_value[1] + "&";
							}
						}

						if(new_query.length > 0)
							new_query = new_query.substring(0, new_query.length-1);

						if(!has_key)
							new_query += new_query.length > 0 ? "&" + key + "=" + newValue : "?" + key + "=" + newValue;

						return new_query;
					}
					function GF_SwitchForm(id){
						if(id.length > 0){
							query = GF_ReplaceQuery("id", id);
							query = query.replace("gf_new_form", "gf_edit_forms");
							document.location = "?" + query;
						}
					}
				</script>
				<div id="gf_form_toolbar">
					<ul id="gf_form_toolbar_links">
						<?php
						if( GFCommon::current_user_can_any( 'gravityforms_edit_forms' ) ):
							?>
							<li class="gf_form_toolbar_editor"><a href="?page=gf_edit_forms&id=<?php echo $form_id; ?>"  <?php echo self::toolbar_class("editor"); ?>><?php _e("Form Editor", "gravityforms"); ?></a></li>
							<li class="gf_form_toolbar_settings"><a href="javascript: if(jQuery('#gform_heading.selectable').length > 0){FieldClick(jQuery('#gform_heading')[0]);} else{document.location = '?page=gf_edit_forms&id=<?php echo $form_id ?>&display_settings';}" <?php echo self::toolbar_class("settings"); ?>><?php _e("Form Settings", "gravityforms"); ?></a></li>
							<li class="gf_form_toolbar_notifications"><a href="?page=gf_edit_forms&view=notification&id=<?php echo $form_id ?>"  <?php echo self::toolbar_class("notifications"); ?>><?php _e("Notifications", "gravityforms"); ?></a></li>
							<?php
						endif;

						if(GFCommon::current_user_can_any(array('gravityforms_view_entries','gravityforms_edit_entries','gravityforms_delete_entries'))){
							?>
							<li class="gf_form_toolbar_entries"><a href="?page=gf_entries&id=<?php echo $form_id ?>"  <?php echo self::toolbar_class("entries"); ?>>
								<?php echo RG_CURRENT_VIEW != 'entry' ? __("Entries", "gravityforms") : __("Entries", "gravityforms"); ?>
							</a></li>
							<?php
						}

						if(GFCommon::current_user_can_any(array("gravityforms_edit_forms", "gravityforms_create_form", "gravityforms_preview_forms"))){
							?>
							<li class="gf_form_toolbar_preview"><a href="<?php echo site_url() ?>/?gf_page=preview&id=<?php echo $form_id ?>" target="_blank" <?php echo self::toolbar_class("preview"); ?>><?php _e("Preview", "gravityforms"); ?></a></li>
							<?php
						}
						?>

						<li class="gf_form_switcher">
							<label for="export_form"><?php _e( 'Select A Form', 'gravityforms' ) ?></label>

							<?php
							if( RG_CURRENT_VIEW != 'entry' ): ?>
								<select name="form_switcher" id="form_switcher" onchange="GF_SwitchForm(jQuery(this).val());">
									<option value=""><?php _e( 'Switch Form', 'gravityforms' ) ?></option>
									<?php foreach($forms as $form_info): ?>
										<option value="<?php echo $form_info->id ?>"><?php echo $form_info->title ?></option>
									<?php endforeach; ?>
								</select>
							<?php
							endif; ?>

						</li>
					</ul>
				</div>
			<?php
		}

		private static function toolbar_class($item){
			/* Retrieves the necessary classes */
			switch($item){

				case "editor":
					if(in_array(rgget("page"), array("gf_edit_forms", "gf_new_form")) && rgempty("view", $_GET))
						return "class='gf_toolbar_active'";
				break;

				case "notifications" :
					if(rgget("page") == "gf_new_form")
						return "class='gf_toolbar_disabled'";
					else if(rgget("page") == "gf_edit_forms" && rgget("view") == "notification")
						return "class='gf_toolbar_active'";

				break;

				case "entries" :
					if(rgget("page") == "gf_new_form")
						return "class='gf_toolbar_disabled'";
					else if(rgget("page") == "gf_entries")
						return "class='gf_toolbar_active'";

				break;

				case "preview" :
					if(rgget("page") == "gf_new_form")
						return "class='gf_toolbar_disabled'";

				break;
			}

			return "";
		}
	}

	if ( defined( 'WP_CONTENT_DIR' ) ) new GFConditionalNotifications; /* initialize */
?>
