<?php
/**
 * @package   	OneAll Social Login
 * @copyright 	Copyright 2011-2015 http://www.oneall.com - All rights reserved.
 * @license   	GNU/GPL 2 or later
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307,USA.
 *
 * The "GNU General Public License" (GPL) is available at
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 */
if (!defined('SMF'))
{
	die('You are not allowed to access this file directly');
}

// OneAll Social Login Version
define('OASL_VERSION', '3.5');
define('OASL_USER_AGENT', 'SocialLogin/' . OASL_VERSION . ' SMF/2.x.x (+http://www.oneall.com/)');


/**
 * Removes any session data stored by the plugin.
 */
function oneall_social_login_clear_session()
{
	foreach (array('session_open', 'session_time', 'social_data', 'origin') AS $key)
	{
		$key = 'oasl_' . $key;

		if (isset($_SESSION [$key]))
		{
			unset($_SESSION [$key]);
		}
	}
}


/**
 * Extracts the social network data from a result-set returned by the OneAll API.
 */
function oneall_social_login_extract_social_network_profile ($social_data)
{
	// Check API result.
	if (is_object ($social_data) && property_exists ($social_data, 'http_code') && $social_data->http_code == 200 && property_exists ($social_data, 'http_data'))
	{
		// Decode the social network profile Data.
		$social_data = json_decode ($social_data->http_data);

		// Make sur that the data has beeen decoded properly
		if (is_object ($social_data))
		{
			// Container for user data
			$data = array();

			// Save the social network data in a session.
			$_SESSION ['oasl_session_open'] = 1;
			$_SESSION ['oasl_session_time'] = time();
			$_SESSION ['oasl_social_data'] = serialize($social_data);

			// Parse Social Profile Data.
			$identity = $social_data->response->result->data->user->identity;

			$data['identity_token'] = $identity->identity_token;
			$data['identity_provider'] = $identity->source->name;

			$data['user_token'] = $social_data->response->result->data->user->user_token;
			$data['user_first_name'] = !empty ($identity->name->givenName) ? $identity->name->givenName : '';
			$data['user_last_name'] = !empty ($identity->name->familyName) ? $identity->name->familyName : '';
			$data['user_location'] = !empty ($identity->currentLocation) ? $identity->currentLocation : '';
			$data['user_constructed_name'] = trim ($data['user_first_name'] . ' ' . $data['user_last_name']);
			$data['user_picture'] = !empty ($identity->pictureUrl) ? $identity->pictureUrl : '';
			$data['user_thumbnail'] = !empty ($identity->thumbnailUrl) ? $identity->thumbnailUrl : '';
			$data['user_about_me'] = !empty ($identity->aboutMe) ? $identity->aboutMe : '';

			// Birthdate - SMF expects %Y-%m-%d
			if ( ! empty ($identity->birthday) && preg_match ('/^([0-9]{2})\/([0-9]{2})\/([0-9]{4})$/', $identity->birthday, $matches))
			{
				//Year
				$data['user_birthdate'] =  str_pad($matches[3], 4, '0', STR_PAD_LEFT);

				//Month
				$data['user_birthdate'] .= '-'. str_pad ($matches[1], 2, '0' , STR_PAD_LEFT);

				//Day
				$data['user_birthdate'] .= '-'. str_pad ($matches[2], 2, '0' , STR_PAD_LEFT);
			}
			else
			{
				$data['user_birthdate'] = '0001-01-01';
			}

			// Fullname.
			if (!empty ($identity->name->formatted))
			{
				$data['user_full_name'] = $identity->name->formatted;
			}
			elseif (!empty ($identity->name->displayName))
			{
				$data['user_full_name'] = $identity->name->displayName;
			}
			else
			{
				$data['user_full_name'] = $data['user_constructed_name'];
			}

			// Preferred Username.
			if (!empty ($identity->preferredUsername))
			{
				$data['user_login'] = $identity->preferredUsername;
			}
			elseif (!empty ($identity->displayName))
			{
				$data['user_login'] = $identity->displayName;
			}
			else
			{
				$data['user_login'] = $data['user_full_name'];
			}

			// Email Address.
			$data['user_email'] = '';
			if (property_exists ($identity, 'emails') && is_array ($identity->emails))
			{
				$data['user_email_is_verified'] = false;
				while ($data['user_email_is_verified'] !== true && (list(, $obj) = each ($identity->emails)))
				{
					$data['user_email'] = $obj->value;
					$data['user_email_is_verified'] = !empty ($obj->is_verified);
				}
			}

			// Website/Homepage.
			$data['user_website'] = '';
			if (!empty ($identity->profileUrl))
			{
				$data['user_website'] = $identity->profileUrl;
			}
			elseif (!empty ($identity->urls [0]->value))
			{
				$data['user_website'] = $identity->urls [0]->value;
			}

			// Gender
			$data['user_gender'] = 0;
			if (!empty ($identity->gender))
			{
				switch ($identity->gender)
				{
					case 'male':
						$data['user_gender'] = 1;
						break;

					case 'female':
						$data['user_gender'] = 2;
						break;
				}
			}

			return $data;
		}
	}
	return false;
}


/**
 * Logs a given user in.
 */
function oneall_social_login_login_user ($id_member, &$error_flag)
{
	// Setup global forum vars.
	global $txt, $boarddir, $sourcedir, $user_settings, $context, $modSettings, $smcFunc;

	// Check identifier.
	if (is_numeric ($id_member) AND $id_member > 0)
	{
		// Read user data.
		$request = $smcFunc ['db_query'] ('', '
			SELECT passwd, id_member, id_group, lngfile, is_activated, email_address, additional_groups, member_name, password_salt, openid_uri, passwd_flood
			FROM {db_prefix}members
			WHERE id_member = {int:id_member} LIMIT 1',
				array (
						'id_member' => intval ($id_member)
				)
		);
		$user_settings = $smcFunc ['db_fetch_assoc'] ($request);
		$smcFunc ['db_free_result'] ($request);

		// Do we have a valid member here?
		if (!empty ($user_settings ['id_member']))
		{
			//Set Login Cookie Expiration (Sources/LogInOut.php)
			$modSettings['cookieTime'] = 3153600;

			// Login.
			require_once($sourcedir . '/LogInOut.php');

			// Check their activation status.
			if (!checkActivation())
			{
				$error_flag = 'require_activation';
				return false;
			}

			// Login.
			DoLogin ();

			//Done
			return true;
		}
	}

	//Error
	return false;
}


/**
 * Computes the activation flag for new users.
 */
function oneall_social_login_get_activation_flag ()
{
	// Global vars.
	global $modSettings;

	// Make sure registration of new members is allowed.
	if ( ! empty ($modSettings ['oasl_settings_reg_method']))
	{
		// Automatic Approval.
		if ($modSettings ['oasl_settings_reg_method'] == 'auto')
		{
			return 'nothing';
		}

		// Registration is disabled.
		if ($modSettings ['oasl_settings_reg_method'] == 'disable')
		{
			return 'disabled';
		}

		// Email Approval.
		if ($modSettings ['oasl_settings_reg_method'] == 'email')
		{
			return 'activation';
		}

		// Admin Approval.
		if ($modSettings ['oasl_settings_reg_method'] == 'admin')
		{
			return 'approval';
		}

		// We have to use the system-wide settings.
		if ($modSettings ['oasl_settings_reg_method'] == 'system')
		{
			// Automatic Approval.
			if (empty ($modSettings ['registration_method']))
			{
				return 'nothing';
			}

			// Registration disabled.
			if ($modSettings ['registration_method'] == '3')
			{
				return 'disabled';
			}

			// Email Approval.
			if ($modSettings ['registration_method'] == '1')
			{
				return 'activation';
			}

			// Admin Approval.
			if ($modSettings ['registration_method'] == '2')
			{
				return 'approval';
			}
		}
	}

	// Automatic Approval.
	return 'nothing';
}


/**
 * Upload a new avatar
 */
function oneall_social_upload_user_avatar ($id_member, $data)
{
	// Global vars.
	global $modSettings, $sourcedir, $smcFunc, $profile_vars, $cur_profile, $context;

	// Manage Attachments.
	require_once($sourcedir . '/ManageAttachments.php');

	// Check data format.
	if (is_array ($data) && (! empty ($data ['user_thumbnail']) || ! empty ($data ['user_picture'])))
	{
		// Use this avatar.
		$social_network_avatar = (! empty ($data ['user_picture']) ? $data ['user_picture'] : $data ['user_thumbnail']);

		// Which connection handler do we have to use?
		$oasl_api_handler = (!empty ($modSettings ['oasl_api_handler']) && $modSettings ['oasl_api_handler'] == 'fsockopen') ? 'fsockopen' : 'curl';

		// Retrieve file data.
		$result = oneall_social_login_do_api_request ($oasl_api_handler, $social_network_avatar);

		// Success?
		if (is_object ($result) && property_exists ($result, 'http_code') && $result->http_code == 200 && property_exists ($result, 'http_data'))
		{
			// File data.
			$file_data = $result->http_data;

			// We need to know where we're going to be putting it (cf. Sources/Profile-Modify.php)
			if (!empty($modSettings['custom_avatar_enabled']))
			{
				$upload_dir = $modSettings['custom_avatar_dir'];
				$id_folder = 1;
			}
			elseif (!empty($modSettings['currentAttachmentUploadDir']))
			{
				if (!is_array($modSettings['attachmentUploadDir']))
				{
					$modSettings['attachmentUploadDir'] = unserialize($modSettings['attachmentUploadDir']);
				}

				// Just use the current path for temp files.
				$upload_dir = $modSettings['attachmentUploadDir'][$modSettings['currentAttachmentUploadDir']];
				$id_folder = $modSettings['currentAttachmentUploadDir'];
			}
			else
			{
				$upload_dir = $modSettings['attachmentUploadDir'];
				$id_folder = 1;
			}

			// The directory must be writeable.
			if (is_dir ($upload_dir) && is_writable ($upload_dir))
			{
				// Generate a temporary filename.
				$file_tmp =  $upload_dir . '/' . getAttachmentFilename('avatar_tmp_' . $id_member, false, null, true);

				// Save file.
				if (($fp = @fopen ($file_tmp, 'wb')) !== false)
				{
					// Write file data.
					$file_new_size = fwrite ($fp, $file_data);
					fclose ($fp);

					// Attempt to chmod it.
					@chmod ($file_tmp, 0644);

					// Allowed file extensions.
					$file_exts = array ();
					$file_exts [IMAGETYPE_GIF] = 'gif';
					$file_exts [IMAGETYPE_JPEG] = 'jpg';
					$file_exts [IMAGETYPE_PNG] = 'png';

					// Get image data.
					list ($width, $height, $type, $attr) = @getimagesize ($file_tmp);

					// Check image type
					if ( ! empty ($width) && ! empty ($height) && isset ($file_exts [$type]))
					{
						// Read file extension
						$file_new_ext = $file_exts [$type];

						// Check if we can resize the image if needed
						if (function_exists ('imagecreatetruecolor') && function_exists ('imagecopyresampled'))
						{
							$max_width = (! empty($modSettings['avatar_max_width_upload']) ? $modSettings['avatar_max_width_upload'] : $width);
							$max_height = (! empty($modSettings['avatar_max_height_upload']) ? $modSettings['avatar_max_height_upload'] : $height);

							// Check if we need to resize
							if ($width > $max_width || $height > $max_height)
							{
								// Keep original size
								$orig_height = $height;
								$orig_width = $width;

								// Taller
								if ($height > $max_height)
								{
									$width = ($max_height / $height) * $width;
									$height = $max_height;
								}

								// Wider
								if ($width > $max_width)
								{
									$height = ($max_width / $width) * $height;
									$width = $max_width;
								}

								// Destination
								$image_resized = imagecreatetruecolor ($width, $height);

								// Resize
								switch ($file_new_ext)
								{
									case 'gif':
										$image_source = imagecreatefromgif ($file_tmp);
										imagecopyresampled ($image_resized, $image_source, 0, 0, 0, 0, $width, $height, $orig_width, $orig_height);
										imagegif ($image_resized, $file_tmp);
									break;

									case 'png':
										$image_source = imagecreatefrompng ($file_tmp);
										imagecopyresampled ($image_resized, $image_source, 0, 0, 0, 0, $width, $height, $orig_width, $orig_height);
										imagepng ($image_resized, $file_tmp);
									break;

									case 'jpg':
										$image_source = imagecreatefromjpeg ($file_tmp);
										imagecopyresampled ($image_resized, $image_source, 0, 0, 0, 0, $width, $height, $orig_width, $orig_height);
										imagejpeg ($image_resized, $file_tmp);
									break;
								}

								// File size of the resized image
								$file_new_size = filesize ($file_tmp);
							}
						}

						// Setup new image
						$file_new_name = 'avatar_' . $id_member . '_' . time() . '.' . $file_new_ext;
						$file_new_hash = (empty($modSettings['custom_avatar_enabled']) ? getAttachmentFilename($file_new_name, false, null, true) : '');
						$file_new_mime = 'image/' . ($file_new_ext === 'jpg' ? 'jpeg' : ($file_new_ext === 'bmp' ? 'x-ms-bmp' : $file_new_ext));

						// Remove previous attachments this member might have had.
						removeAttachments(array('id_member' => $id_member));

						// Insert attachment
						$smcFunc['db_insert']('',
								'{db_prefix}attachments',
								array(
									'id_member' => 'int',
									'attachment_type' => 'int',
									'filename' => 'string',
									'file_hash' => 'string',
									'fileext' => 'string',
									'size' => 'int',
									'width' => 'int',
									'height' => 'int',
									'mime_type' => 'string',
									'id_folder' => 'int',
								),
								array(
									$id_member,
									(empty($modSettings['custom_avatar_enabled']) ? 0 : 1),
									$file_new_name,
									$file_new_hash,
									$file_new_ext,
									$file_new_size,
									(int) $width,
									(int) $height,
									$file_new_mime,
									$id_folder,
								),
								array('id_attach')
						);

						// Update profile
						$cur_profile['avatar'] = '';
						$cur_profile['id_attach'] = $smcFunc['db_insert_id']('{db_prefix}attachments', 'id_attach');
						$cur_profile['filename'] = $file_new_name;
						$cur_profile['attachment_type'] = (empty($modSettings['custom_avatar_enabled']) ? 0 : 1);

						// Final file/ location.
						$file_new = $upload_dir . '/' . (empty($file_new_hash) ? $file_new_name : $cur_profile['id_attach'] . '_' . $file_new_hash);

						// Renamde file.
						if (@rename ($file_tmp, $file_new))
						{
							 // Attempt to chmod it.
               @chmod($file_new, 0644);

               // Success!
               return $file_new;
						}
						else
						{
							removeAttachments(array('id_member' => $id_member));
						}
					}

					// Error.
					@unlink ($file_tmp);
				}
			}
			else
			{
				fatal_lang_error('attachments_no_write', 'critical');
			}
		}
	}

	// Error
	return false;
}

/**
 * Creates a new user based on the given data.
 */
function oneall_social_login_create_user (Array $data)
{
	if (is_array ($data) && ! empty ($data['user_token']) && ! empty ($data['identity_token']))
	{
		// Global vars.
		global $boarddir, $sourcedir, $user_settings, $user_info, $context, $modSettings, $smcFunc;

		// Registration functions.
		require_once($sourcedir . '/Subs-Members.php');

		// Build User fields.
		$regOptions = array ();
		$regOptions ['password'] = substr (md5 (mt_rand ()), 0, 8);
		$regOptions ['password_check'] = $regOptions ['password'];
		$regOptions ['auth_method'] = 'password';
		$regOptions ['interface'] = 'guest';

		// Email address is provided.
		if (!empty ($data['user_email']))
		{
			$regOptions ['email'] = $data['user_email'];
			$regOptions ['hide_email'] = ! isset ($data['hide_email']) ? 0 : $data['hide_email'];
		}
		// Email address is not provided.
		else
		{
			$regOptions ['email'] = oneall_social_login_create_rand_email_address ();
			$regOptions ['hide_email'] = 1;
		}

		// We need a unique email address.
		while (oneall_social_login_get_id_member_for_email_address ($regOptions ['email']) !== false)
		{
			$regOptions ['email'] = oneall_social_login_create_rand_email_address ();
			$regOptions ['hide_email'] = 1;
		}

		// Additional user fields.
		$regOptions ['extra_register_vars'] ['website_url'] = $data['user_website'];
		$regOptions ['extra_register_vars'] ['gender'] = $data['user_gender'];
		$regOptions ['extra_register_vars'] ['location'] = $data['user_location'];
		$regOptions ['extra_register_vars'] ['real_name'] = (! empty ($data['user_full_name']) ? $data['user_full_name'] : $data['user_login']);

		//About Me - Replace line breaks with a spaces
		$data['user_about_me'] = preg_replace("/\r\n|\r|\n/", ' ', $data['user_about_me']);
		$data['user_about_me'] = trim(preg_replace("/\s\s+/", ' ', $data['user_about_me']));
		$regOptions ['extra_register_vars'] ['personal_text'] = $data['user_about_me'];

		// Setup birthdate (Regexp taken from:/Sources/Subs-Db-mysql.php)
		if (preg_match('~^(\d{4})-([0-1]?\d)-([0-3]?\d)$~', $data['user_birthdate']) === 1)
		{
			$regOptions ['extra_register_vars'] ['birthdate'] = $data['user_birthdate'];
		}
		else
		{
			$regOptions ['extra_register_vars'] ['birthdate'] = '0001-01-01';
		}

		// Social Network Avatar
		if (!empty ($modSettings ['oasl_settings_use_avatars']) && !empty ($data['user_picture']))
		{
			$regOptions ['extra_register_vars'] ['avatar'] = $data['user_picture'];
		}

		// Account activation settings.
		$regOptions ['require'] = (isset ($data['activation_flag']) ? $data['activation_flag'] : 'nothing');

		// Do not check the password strength.
		$regOptions ['check_password_strength'] = false;

		// Compute a unique username.
		$regOptions ['username'] = $data['user_login'];

		//Remove characters that SMF does not permit (See Sources/Subs-Members.php)
		$regOptions ['username'] = preg_replace('~&#(?:\\d{1,7}|x[0-9a-fA-F]{1,6});~', '', $regOptions['username']);
		$regOptions ['username'] = preg_replace('~[<>&"\'=\\\\]~', '', $regOptions['username']);

		// Cut if username is too long.
		$regOptions ['username'] = substr ($regOptions ['username'], 0, 25);

		//Make sure we have a valid username
		if (isReservedName ($regOptions ['username']))
		{
			$i = 1;
			do
			{
				$tmp = $regOptions ['username'] . ($i++);
			}
			while (isReservedName ($tmp));
			$regOptions ['username'] = $tmp;
		}


		// Encode.
		if (!$context['utf8'])
		{
			$regOptions ['extra_register_vars'] ['location'] = utf8_decode($regOptions ['extra_register_vars'] ['location']);
			$regOptions ['extra_register_vars'] ['real_name'] = utf8_decode($regOptions ['extra_register_vars'] ['real_name']);
			$regOptions ['extra_register_vars'] ['personal_text'] = utf8_decode($regOptions ['extra_register_vars'] ['personal_text']);
			$regOptions ['username'] = utf8_decode($regOptions ['username']);
		}

		// Other settings.
		$modSettings ['disableRegisterCheck'] = true;

		// Otherwise registerMember might block.
		$user_info ['is_guest'] = true;

		// Create a new user account.
		$id_member = registerMember ($regOptions);

		// User created.
		if (is_numeric ($id_member))
		{
			// Upload avatar?
			if ( ! empty ($modSettings ['oasl_settings_upload_avatars']))
			{
				// Upload Avatar
				$uploaded_file = oneall_social_upload_user_avatar ($id_member, $data);

				// Avatar uploaded
				if ($uploaded_file !== false)
				{
					// Remove avatar url
					$smcFunc['db_query']('', "UPDATE {db_prefix}members  SET avatar = {string:blank}  WHERE id_member = {int:id_member}", array('blank' => '', 'id_member' => $id_member));
				}
			}

			// Tie the tokens to the newly created member.
			oneall_social_login_link_tokens_to_id_member ($id_member, $data['user_token'], $data['identity_token']);

			// Done.
			return $id_member;
		}
	}

	//Error
	return false;
}


/**
 * Links the user/identity tokens to an id_member.
 */
function oneall_social_login_link_tokens_to_id_member ($id_member, $user_token, $identity_token)
{
	global $smcFunc;

	// Make sure that that the id_member exists.
	$request = $smcFunc['db_query']('', 'SELECT id_member FROM {db_prefix}members WHERE id_member = {int:id_member} LIMIT 1', array('id_member' => $id_member));
	$row_member = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	// The user account has been found!
	if (!empty($row_member['id_member']))
	{
		// Read the entry for the given user_token.
		$request = $smcFunc['db_query']('', 'SELECT id_oasl_user, id_member FROM {db_prefix}oasl_users WHERE user_token = {string:user_token} LIMIT 1', array('user_token' => $user_token));
		$oasl_user = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);

		// The user_token exists but is linked to another user.
		if (!empty ($oasl_user['id_oasl_user']) && $oasl_user['id_member'] != $id_member)
		{
			// Drop the oasl_user entry.
			$smcFunc['db_query']('', 'DELETE FROM {db_prefix}oasl_users WHERE id_oasl_user = {int:id_oasl_user}', array('id_oasl_user' => $oasl_user['id_oasl_user']));

			// Drop the oasl_identities entries.
			$smcFunc['db_query']('', 'DELETE FROM {db_prefix}oasl_identities WHERE id_oasl_user = {int:id_oasl_user}', array('id_oasl_user' => $oasl_user['id_oasl_user']));

			// Reset the identifier to create a new one.
			$oasl_user['id_oasl_user'] = null;
		}

		// The user_token either does not exist or has been reset.
		if (empty($oasl_user['id_oasl_user']))
		{
			// Link user_token to id_member.
			$smcFunc['db_insert']('insert', '{db_prefix}oasl_users', array('id_member' => 'int', 'user_token' => 'string'), array($id_member, $user_token), array('id_member', 'user_token'));

			// Identifier of the newly created user_token entry.
			$oasl_user['id_oasl_user'] = $smcFunc['db_insert_id'] ('{db_prefix}oasl_users', 'id_oasl_user');
		}

		// Read the entry for the given identity_token.
		$request = $smcFunc['db_query']('', 'SELECT id_oasl_identity,id_oasl_user,identity_token FROM {db_prefix}oasl_identities WHERE identity_token = {string:identity_token} LIMIT 1', array('identity_token' => $identity_token));
		$oasl_identity = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);

		// The identity_token exists but is linked to another user_token.
		if (!empty ($oasl_identity['id_oasl_identity']) && $oasl_identity['id_oasl_user'] != $oasl_user['id_oasl_user'])
		{
			// Drop the oasl_identities entries.
			$smcFunc['db_query']('', 'DELETE FROM {db_prefix}oasl_identities WHERE id_oasl_identity = {int:id_oasl_identity}', array('id_oasl_identity' => $oasl_identity['id_oasl_identity']));

			// Reset the identifier to create a new one.
			$oasl_identity['id_oasl_identity'] = null;
		}

		// The identity_token either does not exist or has been reset.
		if (empty($oasl_identity['id_oasl_identity']))
		{
			// Add identity.
			$smcFunc['db_insert']('insert', '{db_prefix}oasl_identities', array('id_oasl_user' => 'int', 'identity_token' => 'string'), array($oasl_user['id_oasl_user'], $identity_token), array('id_oasl_user', 'identity_token'));

			// Identifier of the newly created identity_token entry.
			$oasl_identity['id_oasl_identity'] = $smcFunc['db_insert_id'] ('{db_prefix}oasl_identities', 'id_oasl_identity');
		}

		// Done.
		return true;
	}

	//An error occured
	return false;
}


/**
 * UnLinks the identity from an id_member
 */
function oneall_social_login_unlink_identity_token ($identity_token)
{
	global $smcFunc;

	// Make sure it is not empty.
	if (strlen (trim ($identity_token)) == 0)
	{
		return false;
	}

	// Drop the identity entry.
	$smcFunc['db_query']('', 'DELETE FROM {db_prefix}oasl_identities WHERE identity_token = {string:identity_token}', array('identity_token' => $identity_token));
	return ($smcFunc['db_affected_rows'] () > 0) ;
}


/**
 * Returns the user_token for a given id_member.
 */
function oneall_social_login_get_user_token_for_id_member ($id_member)
{
	global $smcFunc;

	// Make sure it is not empty.
	if ( ! is_numeric ($id_member) || $id_member < 1)
	{
		return false;
	}

	//Get the user_token for a given id_member.
	$request = $smcFunc['db_query']('', 'SELECT user_token FROM {db_prefix}oasl_users WHERE id_member = {int:id_member} LIMIT 1', array('id_member' => $id_member));
	$row_oasl_user = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	// Either return the user_token or false if none has been found.
	return !empty($row_oasl_user['user_token']) ? $row_oasl_user['user_token'] : false;
}


/**
 * Returns the id_member for a given user_token.
 */
function oneall_social_login_get_id_member_for_user_token ($user_token)
{
	global $smcFunc;

	// Make sure it is not empty.
	if (strlen (trim ($user_token)) == 0)
	{
		return false;
	}

	//Get the user identifier for a given token
	$request = $smcFunc['db_query']('', 'SELECT id_oasl_user, id_member FROM {db_prefix}oasl_users WHERE user_token = {string:user_token} LIMIT 1', array('user_token' => $user_token));
	$row_oasl_user = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	// We have found an entry.
	if (!empty($row_oasl_user['id_oasl_user']))
	{
		// Check if the user account exists.
		$request = $smcFunc['db_query']('', 'SELECT id_member FROM {db_prefix}members WHERE id_member = {int:id_member} LIMIT 1', array('id_member' => $row_oasl_user['id_member']));
		$row_member = $smcFunc['db_fetch_assoc']($request);
		$smcFunc['db_free_result']($request);

		// The user account exists, return it's identifier.
		if (!empty($row_member['id_member']))
		{
			return $row_member['id_member'];
		}

		//Delete the wrongly linked user_token.
		$smcFunc['db_query']('', 'DELETE FROM {db_prefix}oasl_users WHERE id_oasl_user = {int:id_oasl_user}', array('id_oasl_user' => $row_oasl_user['id_oasl_user']));

		//Delete the wrongly linked identity_token.
		$smcFunc['db_query']('', 'DELETE FROM {db_prefix}oasl_identities WHERE id_oasl_user = {int:id_oasl_user}', array('id_oasl_user' => $row_oasl_user['id_oasl_user']));
	}

	//No entry found
	return false;
}


/**
 * Returns the id_member for a given email address.
 */
function oneall_social_login_get_id_member_for_email_address ($email_address)
{
	global $smcFunc;

	// Make sure it is not empty.
	if (strlen (trim ($email_address)) == 0)
	{
		return false;
	}

	// Check if the user account exists.
	$request = $smcFunc['db_query']('', 'SELECT id_member FROM {db_prefix}members WHERE email_address = {string:email_address} LIMIT 1', array('email_address' => $email_address));
	$row = $smcFunc['db_fetch_assoc']($request);
	$smcFunc['db_free_result']($request);

	// Either return the id_member or false if none has been found.
	return !empty($row['id_member']) ? $row['id_member'] : false;
}


/**
 * Created a random and unique email address.
 */
function oneall_social_login_create_rand_email_address ()
{
	do
	{
		$email_address = md5(uniqid(mt_rand(10000, 99000))) . "@example.com";
	}
	while (oneall_social_login_get_id_member_for_email_address($email_address) !== false);
	return $email_address;
}


/**
 * Sends an API request by using the given handler.
 */
function oneall_social_login_do_api_request ($handler, $url, $options = array(), $timeout = 15)
{
	switch ($handler)
	{
		case 'fsockopen':
			return oneall_social_login_fsockopen_request($url, $options, $timeout);

		case 'curl':
		default:
			return oneall_social_login_curl_request($url, $options, $timeout);
	}
}


/**
 * Returns a list of disabled functions
 */
function oneall_social_get_disabled_functions ()
{
	//Compute a list of disabled functions
	$disabled_functions = trim (ini_get('disable_functions'));
	if (strlen ($disabled_functions) == 0)
	{
		$disabled_functions = array ();
	}
	else
	{
		$disabled_functions = explode (',', $disabled_functions);
		$disabled_functions = array_map('trim', $disabled_functions);
	}
	return $disabled_functions;
}


/**
 * Checks if CURL can be used to communicate with the OneAll API.
 */
function oneall_social_login_check_curl ($secure = true)
{
	//Check if CURL is installed and enabled
	if (in_array('curl', get_loaded_extensions()) && function_exists('curl_exec') && !in_array('curl_exec', oneall_social_get_disabled_functions()))
	{
		//Check if a connection can be made to OneAll
		$result = oneall_social_login_curl_request(($secure ? 'https' : 'http') . '://www.oneall.com/ping.html');
		if (is_object($result) && property_exists($result, 'http_code') && $result->http_code == 200 && property_exists($result, 'http_data') && strtolower($result->http_data) == 'ok')
		{
			//CURL is installed and enabled
			return true;
		}
	}

	//CURL is not available or the firewall blocks the connection
	return false;
}


/**
 * Sends a CURL request to the OneAll API.
 */
function oneall_social_login_curl_request ($url, $options = array(), $timeout = 30,  $num_redirects = 0)
{
	//Store the result
	$result = new stdClass();

	//Send request
	$curl = curl_init();
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_HEADER, 1);
	curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($curl, CURLOPT_REFERER, $url);
	curl_setopt($curl, CURLOPT_VERBOSE, 0);
	curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
	curl_setopt($curl, CURLOPT_USERAGENT, OASL_USER_AGENT);

	// Does not work in PHP Safe Mode, we manually follow the locations if necessary.
	curl_setopt ($curl, CURLOPT_FOLLOWLOCATION, 0);

	// BASIC AUTH?
	if (isset($options['api_key']) && isset($options['api_secret']))
	{
		curl_setopt($curl, CURLOPT_USERPWD, $options['api_key'] . ":" . $options['api_secret']);
	}

	// Make request
	if (($response = curl_exec ($curl)) !== false)
	{
		// Get Information
		$curl_info = curl_getinfo ($curl);

		// Save result
		$result->http_code = $curl_info ['http_code'];
		$result->http_headers = preg_split ('/\r\n|\n|\r/', trim (substr ($response, 0, $curl_info ['header_size'])));
		$result->http_data = trim (substr ($response, $curl_info ['header_size']));
		$result->http_error = null;

		// Check if we have a redirection header
		if (in_array ($result->http_code, array (301, 302)) && $num_redirects < 4)
		{
			// Make sure we have http headers
			if (is_array ($result->http_headers))
			{
				// Header found ?
				$header_found = false;

				// Loop through headers.
				while (! $header_found && (list (, $header) = each ($result->http_headers)))
				{
					// Try to parse a redirection header.
					if (preg_match ("/(Location:|URI:)[^(\n)]*/", $header, $matches))
					{
						// Sanitize redirection url.
						$url_tmp = trim (str_replace ($matches [1], "", $matches [0]));
						$url_parsed = parse_url ($url_tmp);

						// Continue Redirection
						if (! empty ($url_parsed))
						{
							// Header found!
							$header_found = true;

							// Follow redirection url.
							$result = oneall_social_login_curl_request ($url_tmp, $options, $timeout, $num_redirects + 1);
						}
					}
				}
			}
		}
	}
	else
	{
		$result->http_code = - 1;
		$result->http_data = null;
		$result->http_error = curl_error ($curl);
	}

	//Done
	return $result;
}


/**
 * Checks if fsockopen can be used to communicate with the OneAll API
 */
function oneall_social_login_check_fsockopen ($secure = true)
{
	// Check if FSOCKOPEN is installed and enabled.
	if (function_exists('fsockopen') && function_exists ('fwrite'))
	{
		// Read disabled functions.
		$disabled_functions = oneall_social_get_disabled_functions ();

		// And make sure FSOCKOPEN is not part of them.
		if (!in_array('fsockopen', $disabled_functions) && !in_array('fwrite', $disabled_functions))
		{
			// Check if a connection can be made to OneAll.
			$result = oneall_social_login_fsockopen_request(($secure ? 'https' : 'http') . '://www.oneall.com/ping.html');
			if ((is_object($result) && property_exists($result, 'http_code') && $result->http_code == 200 && property_exists($result, 'http_data') && strtolower($result->http_data) == 'ok'))
			{
				// FSOCKOPEN is available.
				return true;
			}
		}
	}

	// FSOCKOPEN is not available or the firewall blocks the connection.
	return true;
}


/**
 * Sends an fsockopen request to the OneAll API
 */
function oneall_social_login_fsockopen_request ($url, $options = array(), $timeout = 30,  $num_redirects = 0)
{
	//Store the result
	$result = new stdClass();

	//Make that this is a valid URL
	if (($uri = parse_url($url)) == false)
	{
		$result->http_code = -1;
		$result->http_data = null;
		$result->http_error = 'invalid_uri';
		return $result;
	}

	//Make sure we can handle the schema
	switch ($uri['scheme'])
	{
		case 'http':
			$port = (isset($uri['port']) ? $uri['port'] : 80);
			$host = ($uri['host'] . ($port != 80 ? ':' . $port : ''));
			$fp = @fsockopen($uri['host'], $port, $errno, $errstr, $timeout);
			break;

		case 'https':
			$port = (isset($uri['port']) ? $uri['port'] : 443);
			$host = ($uri['host'] . ($port != 443 ? ':' . $port : ''));
			$fp = @fsockopen('ssl://' . $uri['host'], $port, $errno, $errstr, $timeout);
			break;

		default:
			$result->http_code = -1;
			$result->http_data = null;
			$result->http_error = 'invalid_schema';
			return $result;
			break;
	}

	//Make sure the socket opened properly
	if (!$fp)
	{
		$result->http_code = -$errno;
		$result->http_data = null;
		$result->http_error = trim($errstr);
		return $result;
	}

	//Construct the path to act on
	$path = (isset($uri['path']) ? $uri['path'] : '/');
	if (isset($uri['query']))
		$path .= '?' . $uri['query'];

	// Create HTTP request
	$defaults = array ();
	$defaults ['Host'] = 'Host: ' . $host;
	$defaults ['User-Agent'] = 'User-Agent: ' . OASL_USER_AGENT;

	// BASIC AUTH?
	if (isset($options['api_key']) && isset($options['api_secret']))
	{
		$defaults['Authorization'] = 'Authorization: Basic ' . base64_encode($options['api_key'] . ":" . $options['api_secret']);
	}

	//Build and send request
	$request = 'GET ' . $path . " HTTP/1.0\r\n";
	$request .= implode("\r\n", $defaults);
	$request .= "\r\n\r\n";

	if (fwrite($fp, $request))
	{
		//Set timeout and blocking
		stream_set_blocking ($fp, false);
		stream_set_timeout ($fp, $timeout);

		//Check for timeout
		$fp_info = stream_get_meta_data ($fp);

		//Fetch response
		$response = '';
		while (!$fp_info['timed_out'] && !feof($fp))
		{
			// Read data.
			$response .= fread($fp, 1024);

			// Get meta data (which has timeout info).
			$fp_info = stream_get_meta_data ($fp);
		}

		// Close connection.
		fclose($fp);

		//Timed out?
		if ( !$fp_info['timed_out'])
		{
			// Parse response.
			list($response_header, $response_body) = explode("\r\n\r\n", $response, 2);

			// Parse header.
			$response_header = preg_split("/\r\n|\n|\r/", $response_header);
			list($header_protocol, $header_code, $header_status_message) = explode(' ', trim(array_shift($response_header)), 3);

			// Set result
			$result->http_code = $header_code;
			$result->http_headers = $response_header;
			$result->http_data = $response_body;

			// Check if we have a redirection status code
			if (in_array ($result->http_code, array (301, 302)) && $num_redirects <= 4)
			{
				// Make sure we have http headers
				if (is_array ($result->http_headers))
				{
					// Header found?
					$header_found = false;

					// Loop through headers.
					while (! $header_found && (list (, $header) = each ($result->http_headers)))
					{
						// Check for location header
						if (preg_match ("/(Location:|URI:)[^(\n)]*/", $header, $matches))
						{
							// Found
							$header_found = true;

							// Clean url
							$url_tmp = trim (str_replace ($matches [1], "", $matches [0]));
							$url_parsed = parse_url ($url_tmp);

							// Found
							if (! empty ($url_parsed))
							{
								$result = oneall_social_login_fsockopen_request ($url_tmp, $options, $timeout, $num_redirects + 1);
							}
						}
					}
				}
			}

			// Return result.
			return $result;
		}
		else
		{
			$result->http_code = -1;
			$result->http_data = null;
			$result->http_error = 'request_timed_out';
			return $result;
		}
	}
	else
	{
		$result->http_code = -1;
		$result->http_data = null;
		$result->http_error = 'request_blocked';
		return $result;
	}
}