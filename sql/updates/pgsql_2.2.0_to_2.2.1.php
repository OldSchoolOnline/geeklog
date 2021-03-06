<?php

// *************************************
// Fix Group Assignments from Geeklog Install

// Remove Admin User (2) from all default groups assignments from the install except Root (1), All Users (2), Logged-In Users (13)
// Decided to delete all groups except listed above instead of original groups assigned since bug in previous user editor added inherited groups as actual group assignments
$_SQL[] = "DELETE FROM {$_TABLES['group_assignments']} WHERE (ug_main_grp_id != 1 AND ug_main_grp_id != 2 AND ug_main_grp_id != 13) AND ug_uid = 2 AND ug_grp_id IS NULL";
// Remove All Users (2) from any other group (should be just for users)
$_SQL[] = "DELETE FROM {$_TABLES['group_assignments']} WHERE ug_main_grp_id = 2 AND ug_uid IS NULL AND ug_grp_id > 0";
// Remove Root Group (1) from All Users Group (2) (which all users already belong too)
$_SQL[] = "DELETE FROM {$_TABLES['group_assignments']} WHERE ug_main_grp_id = 2 AND ug_uid IS NULL AND ug_grp_id = 1";
// *************************************

// Remove unused Vars table record (originally inserted by devel-db-update script)
$_SQL[] = "DELETE FROM {$_TABLES['vars']} WHERE name = 'geeklog'";

// Add structured data type to article table and modified date
$_SQL[] = "ALTER TABLE {$_TABLES['stories']} ADD `structured_data_type` tinyint(4) NOT NULL DEFAULT 0 AFTER `commentcode`";
$_SQL[] = "ALTER TABLE {$_TABLES['stories']} ADD `modified` timestamp default NULL AFTER `date`";

// Language Override value can now be longer than 255 characters
$_SQL[] = "ALTER TABLE {$_TABLES['language_items']} CHANGE `value` `value` TEXT";

/**
 * Upgrade Messages
 */
function upgrade_message220()
{
    // 3 upgrade message types exist 'information', 'warning', 'error'
    // error type means the user cannot continue upgrade until fixed

    // Fix User Security Group assignments for Groups: Root, Admin, All Users
    // Fix User Security Group assignments for Users: Admin
    $upgradeMessages['2.2.0'] = array(
        1 => array('warning', 22, 23),  // Fix User Security Group assignments for Groups: Root, Admin, All Users - Fix User Security Group assignments for Users: Admin
        2 => array('warning', 24, 25),  // FCKEditor removed
        3 => array('warning', 26, 27),  // Google+ OAuth Login switched to Google OAuth Login 
        4 => array('warning', 28, 29)   // Fixed spaces around user names and removed duplicate usernames
    );

    return $upgradeMessages;
}

/**
 * Add/Edit/Delete config options for new version
 */
function update_ConfValuesFor221()
{
    global $_CONF, $_TABLES;

    require_once $_CONF['path_system'] . 'classes/config.class.php';

    $c = config::get_instance();

    $me = 'Core';
    
    // FCKEditor removed so make sure config is not set to use it. If is switch advance editor to CKEditor
    if (isset($_CONF['advanced_editor_name']) && $_CONF['advanced_editor_name'] == 'fckeditor') {
        $c->add('advanced_editor_name','ckeditor','select',4,20,NULL,845,TRUE, $me, 20);
    }
    
    // Default Structured Data type for new articles
    $c->add('structured_data_type_default',2,'select',1,7,39,1275,TRUE, $me, 7); // Setting article as the default
    
    // Add absolute path for logo image which is used by the Publisher property with Structured Data
    $c->add('path_site_logo','','text',0,0,NULL,65,TRUE, $me, 0);
    
    // Add switch to enable setting of language id for item if Geeklog Multi Language is setup
    $c->add('new_item_set_current_lang',0,'select',6,28,0,380,TRUE, $me, 28);

    return true;
}

/**
 * Remove blank username accounts and update any duplicate username accounts with new names
 * Add Unique index to username column
 *
 * @return bool
 */
function fixDuplicateUsernames221()
{
    global $_TABLES;
    
    // Delete blank usernames first
    // Left users that are just spaces... hopefully none of these
    $sql = "SELECT uid, username FROM {$_TABLES['users']} WHERE username = ''";
    $result = DB_query($sql);
    $numRows = DB_numRows($result);
    for ($i = 0; $i < $numRows; $i++) {
        $A = DB_fetchArray($result);
        
        $uid = $A['uid'];

        // Blank usernames were sometimes generated via new Facebook Oauth accounts for Geeklog 2.2.0 and older.
        // See: https://github.com/Geeklog-Core/geeklog/issues/861
        // Should be only 1 record in user table (all our tests showed this) but lets delete anything just in case.. since we cannot call USER_deleteAccount($uid) here
        // remove from all security groups
        DB_delete($_TABLES['group_assignments'], 'ug_uid', $uid);

        // remove user information and preferences
        DB_delete($_TABLES['userprefs'], 'uid', $uid);
        DB_delete($_TABLES['userindex'], 'uid', $uid);
        DB_delete($_TABLES['usercomment'], 'uid', $uid);
        DB_delete($_TABLES['userinfo'], 'uid', $uid);
        DB_delete($_TABLES['backup_codes'], 'uid', $uid);

        // avoid having orphand stories/comments by making them anonymous posts
        DB_query("UPDATE {$_TABLES['comments']} SET uid = 1 WHERE uid = $uid");
        DB_query("UPDATE {$_TABLES['stories']} SET uid = 1 WHERE uid = $uid");
        DB_query("UPDATE {$_TABLES['stories']} SET owner_id = 1 WHERE owner_id = $uid");

        // delete submissions
        DB_delete($_TABLES['storysubmission'], 'uid', $uid);
        DB_delete($_TABLES['commentsubmissions'], 'uid', $uid); // Includes article and plugin submissions
        
        // now delete the user itself
        DB_delete($_TABLES['users'], 'uid', $uid);                
    }
    
    // Must find and remove all duplicate usernames before adding index
    $sql = "SELECT username, COUNT(*) c FROM {$_TABLES['users']} GROUP BY username HAVING c > 1";
    $result = DB_query($sql);
    $numRows = DB_numRows($result);
    for ($i = 0; $i < $numRows; $i++) {
        $A = DB_fetchArray($result);
        
        $dup_username = DB_escapeString(trim($A['username']));

        // Now fix if possible. List local account last as it will be not considered dup since all others have been changed
        $sql_B = "SELECT uid, username FROM {$_TABLES['users']} WHERE TRIM(username) = '$dup_username' ORDER BY remoteservice DESC";
        $result_B = DB_query($sql_B);
        $numRows_B = DB_numRows($result_B);
        for ($i_B = 0; $i_B < $numRows_B; $i_B++) {
            $B = DB_fetchArray($result_B);
            
            $uid = $B['uid'];
            $username = trim($B['username']); // Need to trim spaces as this may have been in part what caused the duplicates
            
            $checkName = DB_getItem($_TABLES['users'], 'username', "TRIM(username)='" . DB_escapeString($username) . "' AND uid != $uid");
            if (!empty($checkName)) {
                /*
                // Cannot call CUSTOM_uniqueRemoteUsername since in install
                if (function_exists('CUSTOM_uniqueRemoteUsername')) {
                    $username = CUSTOM_uniqueRemoteUsername($username, $remoteService);
                }
                */
                if (strcasecmp($checkName, $username) == 0) {
                    // Cannot call USER_uniqueUsername so took code from function
                    // $username = USER_uniqueUsername($username);
                    $try = $username;
                    do {
                        $try = DB_escapeString($try);
                        $test_uid = DB_getItem($_TABLES['users'], 'uid', "username = '$try'");
                        if (!empty($test_uid)) {
                            $r = rand(2, 9999);
                            if (strlen($username) > 12) {
                                $try = sprintf('%s%d', substr($username, 0, 12), $r);
                            } else {
                                $try = sprintf('%s%d', $username, $r);
                            }
                        }
                    } while (!empty($test_uid));
                    $username = $try;                        
                }
                
                // Save new name
                DB_query("UPDATE {$_TABLES['users']} SET username = '" . DB_escapeString($username) . "' WHERE uid=$uid");
            }
        }
    }
    
    // Remove old username index and add unique index on username
    $sql = "ALTER TABLE {$_TABLES['users']} DROP INDEX users_username;";
    DB_query($sql);
    $sql = "ALTER TABLE {$_TABLES['users']} ADD UNIQUE KEY users_username (username);";
    DB_query($sql);    
    
    return true;
}
