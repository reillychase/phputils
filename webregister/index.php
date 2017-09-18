<?php
// ------------------------------------------------------------------------------------------ //
//                                                                                            //
//                            The Player vs Player Gaming Network                             //
//                                  Web Registration System                                   //
//                                                                                            //
//                                Copyright (C) 2004 by U-238                                 //
//                           http://pvpgn-phputils.sourceforge.net                            //
//                                                                                            //
// ------------------------------------------------------------------------------------------ //
//                                                                                            //
// LICENSE                                                                                    //
//                                                                                            //
// This program is free software; you can redistribute it and/or modify it under the terms of //
// the GNU General Public License (GPL) as published by the Free Software Foundation; either  //
// version 2 of the License, or (at your option) any later version.                           //
//                                                                                            //
// This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;  //
// without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  //
// See the GNU General Public License for more details.                                       //
//                                                                                            //
// You should have received a copy of the GNU General Public License along with this program; //
// if not, write to the Free Software Foundation, Inc., 59 Temple Place, Suite 330, Boston,   //
// MA  02111-1307  USA                                                                        //
//                                                                                            //
// ------------------------------------------------------------------------------------------ //
//                                                                                            //
// Please see config.php for configuration options.  It's also a good idea to read ReadMe.txt //
// Do not edit this file unless you are experienced in PHP and you know what you're doing!!!! //
//                                                                                            //
// ------------------------------------------------------------------------------------------ //

// Lets begin by including all the other various files we need:
require_once("config.php");
require_once("includes/pvpgn_hash.php");
require_once("includes/activation.php");
require_once("includes/mail.php");
require_once("includes/theme_handler.php");
require_once("includes/insertdata.php");
require_once("includes/version.php");

// Language selection.  If a user chose a language from the menu, we'll use that.  Otherwise
// we'll use the default language.

if ($_GET['lang']) {
	if (file_exists("includes/lang/lang_" . $_GET['lang'] . ".php") && is_file("includes/lang/lang_" . $_GET['lang'] . ".php")) {
		require_once("includes/lang/lang_" . $_GET['lang'] . ".php");
		$lang = $_GET['lang'];
	} else {
		header("Location: " . $mainfile);
		die();
	}
} else {
	$lang = $lang_default;
	if (!require_once("includes/lang/lang_" . $lang_default . ".php")) {
		die("An invalid language was specified in config.php\n");
	}
}

// If an admin is activating this account, we'll continue their session.
if ($_GET['adminmakeacct']) {
	require_once("includes/admin_prefs.php");
	session_start();
	if (!($_SESSION['pass'] == $adminprefs['password'])) {
		header("Location: " . $adminfile . "?nosession=1&lang=" . $lang);
		die();
	} else {
		$adminmakeacct = true;
	}
}

// Email activation implies that $require_email = true, even if the server admin has set this
// to false in config.php
if ($activation['method'] == "email") {
	$require_email = true;
}

if ($one_acct_per_email) {
	$require_email = true;
}

// A quick function that we will use later to check if the email address contains a . and an @
function checkemail($email) {
	$email = trim($email);
	$pos1 = strpos($email, "@");
	$pos2 = strpos($email, ".");
	if (!$pos1 || !$pos2) {
		return false;
	} else {
		return true;
	}
}

// We've finished including and defining things, this is where the script begins execution.
// We begin by seeing if there was any information posted to the script.
if ($_POST) {	

	// The first thing we do is check to see if the username contains any illegal characters.
	// If the username contains illegal characters, the user will be shown an error page.
	// If an admin is creating this account, they may have chosen to bypass this check

	if (!($adminmakeacct && $_POST['allchars'] == "true")) {
		$account_allowed_chars = $account_allowed_symbols . "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
		$acct_username = trim($_POST['acct_username']);
		$length1 = strlen($acct_username);
		$length2 = strlen($account_allowed_chars);
		for($count1 = 0; $count1 < $length1; $count1++) {
			for($count2 = 0; $count2 < $length2; $count2++) {
				if ($acct_username[$count1] == $account_allowed_chars[$count2]) {
					$goodchar = true;
				}
			}
			if (!$goodchar) {
				error(0,str_replace("[[allowed_chars]]",$account_allowed_symbols,$language['invalidname']),"");
			}
			$goodchar = false;
		}
	}

	// The next step is to make sure the password is at least 3 characters long, and then we hash it
	// using Aaron's hashing script.
	if (trim($_POST['password1']) == trim($_POST['password2'])) {
		if (strlen(trim($_POST['password1'])) > 5) {
			$passhash = pvpgn_hash(trim($_POST['password1']));
		} else {
			error(0,$language['shortpass'],"");
		}
	} else {
		error(0,$language['passmismatch'],"");
	}

	// Lets connect to the MySQL db
	$dbh = @mysql_connect($dbhost,$dbuser,$dbpass);
	@mysql_select_db($dbname) or error(1,$language['dbconnecterror'],mysql_error());

	// We find out the maximum user id so we can give this account the next user id.
	if ($row = @mysql_fetch_row(@mysql_query("SELECT MAX(`uid`) FROM `pvpgn_BNET`",$dbh))) {

		// If the server admin has set $one_email_per_acct = true in config.php, we make sure that there
		// this email address is unique.
		if ($one_acct_per_email) {
			if (@mysql_fetch_row(@mysql_query("SELECT * FROM `pvpgn_BNET` WHERE acct_email = \"" . mysql_real_escape(trim($_POST['acct_email'])) . "\";",$dbh))) {
				error(0,$language['oneemailonly'],"");
			}
		}

		// Next we make sure that the username is unique
		if (@mysql_fetch_array(@mysql_query("SELECT uid FROM `pvpgn_BNET` WHERE acct_username = \"" . trim($_POST['acct_username']) . "\";",$dbh))) {
			error(0,$language['userexists'],"");
		} else {
			unset($data);
			
			// Let's put the data into an array so it's ready to be inserted into the table:
			$data['uid'] = $row[0] + 1;
			$data['acct_userid'] = $data['uid'];
			$data['acct_username'] = trim($_POST['acct_username']);
			if ($activation['method'] == "none")
			{
			$data['username'] = $data['acct_username'];
		}
			$data['acct_passhash1'] = $passhash;
			if ($adminmakeacct) {
				if ($_POST['auth_admin'] == "true") {
					$data['auth_admin'] = "true";
				}
				if ($_POST['auth_operator'] == "true") {
					$data['auth_operator'] = "true";
				}
				$data['auth_command_groups'] = $_POST['command_groups'];
			}

			// If an email address was specified, we will run it through the check_email() function
			// which is defined at the top of this file.
			if ($_POST['acct_email'] && $_POST['acct_email'] <> "") {
				if (checkemail($_POST['acct_email'])) {
					$data['acct_email'] = trim($_POST['acct_email']);
				} else {
					error(0,$language['bademail'],"");
				}
			} else {

			// An email address was not specified.  If the server admin requires an email address (in config.php),
			// the user will be shown an error page.  If the server admin has made email addresses optional, the
			// account creation will continue without the email address.
				if ($require_email) {
					error(0,$language['bademail'],"");
				}
			}
		}

		if ($adminmakeacct) {

		// An admin is creating this account, so we don't need activation
			InsertData($data,"pvpgn_BNET");

		// And lets return to the admin interface
			$_SESSION['msg'] = str_replace("[[acct_username]]",$data['acct_username'],$language['admincreated']);
			header("Location: " . $adminfile . "?action=makeacct&lang=" . $lang);
	
		} else if ($activation['method'] == "none") {

		// Account activation is not required, so lets insert the info straight away!
			InsertData($data,"pvpgn_BNET");
			

		// And show the user a confirmation page of course
			$page_data = array(
				"title" => $language['title'] . " :: " . $language['title_success'],
				"message" => $data['acct_username'] . $language['created']);
			echo parse_theme("general_message.htm", $page_data);
			
		} else if ($activation['method'] == "email") {

			// For email activation, we'll hand over to the email_activation function in activation.php
			email_activation($data,$activation,$lang,$dbh);

		} else if ($activation['method'] == "admin") {

			// For admin activation, we'll hand over to the admin_activation function in activation.php
			admin_activation($data,$activation,$lang,$dbh);

		} else {
			error(0,"An invalid activation method was specified in config.php","");
		}
	} else {
		error(1,$language['dbreaderror'],mysql_error());
	}
} else {
	// Nothing was posted to the script, so lets show the main page.
	// We'll start by processing the language menu:

	if ($lang_displaymenu) {
		$js = 	"<SCRIPT language=\"JavaScript\" TYPE=\"text/JavaScript\">\r\n";
		$js .=	"<!--\r\n";
		$js .=  "function seePage(form) {\r\n";
 		$js .=  "       newPage = (form.linkList.options[form.linkList.selectedIndex].value);\r\n";
		$js .=  "       location.href=newPage;\r\n";
		$js .=  "}\r\n";
		$js .=	"// -->\r\n";
		$js .=	"</SCRIPT>\r\n";
		$menu = "<FORM>\r\n";
		$menu .= "<SELECT name=\"linkList\" onChange=\"seePage(this.form)\">\r\n";
		foreach($lang_menu as $key => $val) {
			if ($val == $lang) {
				$menu .= "<OPTION selected value=\"" . $mainfile . "?lang=" . $val . "\">" . $key . "</OPTION>\r\n";
			} else {
				$menu .= "<OPTION value=\"" . $mainfile . "?lang=" . $val . "\">" . $key . "</OPTION>\r\n";
			}
		}
		$menu .= "</SELECT>\r\n";
		$menu .= "</FORM>\r\n";
	} else {
		$js = "";
		$menu = "";
	}

	// Now assemble and display the page

	$page_data = array(
		"title" => $language['title'],
		"lang_username" => $language['form_username'],
		"lang_password" => $language['form_password'],
		"lang_confpass" => $language['form_confpass'],
		"lang_email" => $language['form_email'],
		"lang_submit" => $language['form_submit'],
		"js" => $js,
		"menu" => $menu,
		"self" => $mainfile . "?lang=" . $lang);
	echo parse_theme("form.htm", $page_data);
}
// The end!
?>
