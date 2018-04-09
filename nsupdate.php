<?php
# $Id: nsupdate.php,v 1.5 2014/06/11 03:28:49 chip Exp $
# $Source: /usr/local/var/cvs/web-nsupdate/nsupdate.php,v $
#
# Copyright 2005-2014, Chip Rosenthal <chip@unicom.com>.
# See software license at <http://www.unicom.com/sw/license.html>
# for terms of use and distribution.
#

/**
 * Load site-specific definitions.
 */
require("data/nsupdate-defs.php");



/**
 * Web page with form for manual entry.
 */
$NSUPDATE_MANUAL_FORM = '<html>
<head>
<title>web-nsupdate: Manual Entry</title>
</head>
<body>
<h1>web-nsupdate: Manual Entry</h1>
<form method="get">
<table border="0" cellspaceing="0" cellpadding="3">
<tr>
	<td><label for="hostname">Host Name:</label></td>
	<td><input type="text" name="hostname" /></td>
</tr>
<tr>
	<td><label for="ipv4">IPv4 Address:</label></td>
	<td><input type="text" name="ipv4" /></td>
</tr>
<tr>
	<td><label for="ipv6">IPv6 Address:</label></td>
	<td><input type="text" name="ipv6" /></td>
</tr>
<tr>
	<td><label for="key">Authentication Key:</label></td>
	<td><input type="password" name="key" /></td>
</tr>
<tr>
	<td>&nbsp;</td>
	<td>
		<input type="hidden" name="verbose" value="1" />
		<input type="submit" /> <input type="reset" />
	</td>
</tr>
</table>
</form>
</body>
</html>
';



/**
 * Retrieve information from $Hosts_Table[] for a specified host.
 * @param $p_hostname  Name of the host to lookup.
 * @return Array (key/value pairs) of information on the host.
 * An error is raised if the host is not defined.
 */
function get_hostinfo($p_hostname)
{
	global $Hosts_Table;
	if (empty($Hosts_Table[$p_hostname])) {
		send_error(400, "Host \"$p_hostname\" unknown.");
	}

	$p_hostinfo = $Hosts_Table[$p_hostname];

	if (empty($p_hostinfo["nskey"])) {
		$p_hostinfo["nskey"] = DEFAULT_NSKEY;
	}
	if (empty($p_hostinfo["nameserver"])) {
		$p_hostinfo["nameserver"] = DEFAULT_NAMESERVER;
	}
	if (empty($p_hostinfo["ttl"])) {
		$p_hostinfo["ttl"] = DEFAULT_TTL;
	}

	return $p_hostinfo;
}


/**
 * Validate an authorization key for a host.
 * @param $p_hostinfo  Host information array.
 * @param $p_key  Key to validate.
 * @return Nothing.
 * An error is raised if validation fails.
 */
function validate_host($p_hostinfo, $p_key)
{
	if ($p_hostinfo["key"] != $p_key) {
		send_error(501, "Permission denied.");
	}
}


/**
 * Extract the domain name name portion from a fully qualified host name.
 * @param $p_hostname  The fully qualified host name.
 * @return  The extracted domain name.
 * An error is raised if the extraction fails.
 */
function extract_domain_from_hostname($p_hostname)
{
	$pos = strpos($p_hostname, ".");
	if ($pos === FALSE) {
		send_error(400, "Failed to extract domain from hostname \"$p_hostname\".");
	}
	return substr($p_hostname, $pos+1);
}


/**
 * Determine whether a host is already defined to a given ipv4 address in the DNS
 * @param $p_hostname  The fully qualified host name.
 * @param $p_ipv4  The expected IP address of the host.
 * @param $p_nameserver  Server to query.
 * @return  If the IP address in DNS matches the $p_ipv4 value.
 */
function host_addr_ipv4_matches($p_hostname, $p_ipv4, $p_nameserver)
{
	$cmd = sprintf("host -t A %s %s", escapeshellarg($p_hostname), escapeshellarg($p_nameserver));
	$a = preg_split('/\s+/', trim(shell_exec($cmd)));
	$addr = $a[count($a)-1];
	return ($addr == $p_ipv4);
}

/**
 * Determine whether a host is already defined to a given ipv4 address in the DNS
 * @param $p_hostname  The fully qualified host name.
 * @param $p_ipv4  The expected IP address of the host.
 * @param $p_nameserver  Server to query.
 * @return  If the IP address in DNS matches the $p_ipv4 value.
 */
function host_addr_ipv6_matches($p_hostname, $p_ipv6, $p_nameserver)
{
	$cmd = sprintf("host -t AAAA %s %s", escapeshellarg($p_hostname), escapeshellarg($p_nameserver));
	$a = preg_split('/\s+/', trim(shell_exec($cmd)));
	$addr = $a[count($a)-1];
	return ($addr == $p_ipv6);
}


/**
 * Write data to a file.
 * @param $filename  Name of the file to create.
 * @param $data  The data to write.
 * @return Number of characters written.
 * An error is raised if any of the operations fail.
 */
function file_write_string($filename, $data)
{
	$fh = fopen($filename, "w");
	if (! $fh) {
		send_error(500, "fopen failed in file_write_string()");
	}
	$rc = fwrite($fh, $data);
	if ($rc === FALSE) {
		send_error(500, "fwrite failed in file_write_string()");
	}
	if (! fclose($fh)) {
		send_error(500, "fclose failed in file_write_string()");
	}
	return $rc;
}

/**
 * Send an HTTP error message.
 */
function send_error($status, $message)
{
	$status_description = array(
		"200" => "OK",
		"400" => "Bad Request",
		"403" => "Forbidden",
		"500" => "Internal Server Error",
	);

	if (array_key_exists($status, $status_description)) {
		$description = $status_description[$status];
	} else {
		$description = "Error " . $status;
	}

	$a = array($_SERVER["SERVER_PROTOCOL"], $status, $description);
	header(implode(" ", $a));
	header("Content-Type: text/plain");
	echo($message . "\n");
	exit(0);
}


########################################################################
#
# Main execution begins here.
#

if (empty($_GET) && empty($_POST)) {
	echo($NSUPDATE_MANUAL_FORM);
	exit(0);
}


if (!empty($_REQUEST['hostname'])) {
	$p_hostname = $_REQUEST['hostname'];
} elseif (!empty($_REQUEST['host'])) {
	# "host" is deprecated - "hostname" is preferred
	$p_hostname = $_REQUEST['host'];
} else {
	send_error(400, "nohost - Failed to determine client hostname (parameter \$hostname).");
}

if (!empty($_REQUEST['ipv4'])) {
	$p_ipv4 = $_REQUEST['ipv4'];
} /* elseif (!empty($_REQUEST['addr'])) {
	$p_ipv4 = $_REQUEST['addr'];
} elseif (!empty($_SERVER['REMOTE_ADDR'])) {
	$p_ipv4 = $_SERVER['REMOTE_ADDR'];
} */ else {
	$p_ipv4 = null;
}

// sanity check ipv4 address, should be improved
if ($p_ipv4 == "") $p_ipv4 = null;

if (!empty($_REQUEST['ipv6'])) {
	$p_ipv6 = $_REQUEST['ipv6'];
} else {
	$p_ipv6 = null;
}

// sanity check ipv6 address, should be improved
if ($p_ipv6 == "") $p_ipv6 = null;

if (!$p_ipv4 && !$p_ipv6) {
	send_error(400, "Failed to determine client ipv4 or ipv6 address");
}

$p_key = $_REQUEST['key'];
if (empty($p_key)) {
	send_error(403, "badauth - Failed to acquire authentication key (parameter \$key).");
}

$p_hostinfo = get_hostinfo($p_hostname);
# print_r($p_hostinfo);

validate_host($p_hostinfo, $p_key);

$p_domain = extract_domain_from_hostname($p_hostname);

#
# Check if address has changed.
#

if ($p_ipv4 && $p_ipv6 
    && host_addr_ipv4_matches($p_hostname, $p_ipv4, $p_hostinfo['nameserver'])
	&& host_addr_ipv6_matches($p_hostname, $p_ipv6, $p_hostinfo['nameserver']) ) {
	send_error(200, "nochg - Not updated - addresses ipv4 $p_ipv4 and ipv6 $p_ipv6 already assigned to host $p_hostname.");
} elseif ($p_ipv4 && host_addr_ipv4_matches($p_hostname, $p_ipv4, $p_hostinfo['nameserver'])) {
	send_error(200, "nochg - Not updated - address ipv4 $p_ipv4 already assigned to host $p_hostname.");
} elseif ($p_ipv6 && host_addr_ipv6_matches($p_hostname, $p_ipv6, $p_hostinfo['nameserver'])) {
	send_error(200, "nochg - Not updated - address ipv6 $p_ipv6 already assigned to host $p_hostname.");
}

/**
 * Template to generate command script for nsupdate(8).
 */
$NSUPATE_COMMAND_TEMPLATE = 'server {$p_hostinfo["nameserver"]}
zone $p_domain
update delete $p_hostname
';
if ($p_ipv4) $NSUPATE_COMMAND_TEMPLATE .= 'update add $p_hostname {$p_hostinfo["ttl"]} A $p_ipv4'."\n";
if ($p_ipv6) $NSUPATE_COMMAND_TEMPLATE .= 'update add $p_hostname {$p_hostinfo["ttl"]} AAAA $p_ipv6'."\n";
$NSUPATE_COMMAND_TEMPLATE .= "send\n";

#
# Generate a command script for nsupdate(8).
#
$tmpfname = tempnam("", "nsupdate.");
if (!$tmpfname) {
	send_error(500, "tempnam failed.");
}
eval("\$fcontent = \"$NSUPATE_COMMAND_TEMPLATE\";");
file_write_string($tmpfname, $fcontent);

//die("<pre>DEBUG\n" . $fcontent);

#
# Run the nsupdate(8) command.
#
$rc = system("nsupdate -k {$p_hostinfo['nskey']} $tmpfname 2>&1", $ex);
unlink($tmpfname);
#echo("<p>rc = $rc, ex = $ex\n</p>");
if ($rc === FALSE || $ex != 0) {
	send_error(500, "nsupdate command failed.");
}


/**
 * Web page with success resposne for manual update.
 */
$NSUPATE_MANUAL_RESPONSE = '<html>
<head>
<title>Update Successful</title>
</head>
<body>
<h1>Manual Update Successful</h1>
<p>Host <i>{$p_hostname}</i> has been assigned address';

if ($p_ipv4) $NSUPATE_MANUAL_RESPONSE .= ' <i>IPv4 $p_ipv4</i>';
if ($p_ipv6) $NSUPATE_MANUAL_RESPONSE .= ' <i>IPv6 $p_ipv6</i>';

$NSUPATE_MANUAL_RESPONSE .='</p>
</body>
</html>
';


#
# Normally we exit quietly on success.
# If we were running manually, send an HTML response.
#
if ($_REQUEST['verbose']) {
	eval("echo(\"" . addslashes($NSUPATE_MANUAL_RESPONSE) . "\");");
} else {
	$txt = "good - Successfully assigned address";
	if ($p_ipv4) $txt .= " ipv4 $p_ipv4";
	if ($p_ipv6) $txt .= " ipv6 $p_ipv6";
	$txt .=  " to host $p_hostname.";
	send_error(200, $txt);
}

