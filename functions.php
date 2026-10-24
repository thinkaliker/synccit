<?php


include("pbkdf2.php");


// ── Real client IP ─────────────────────────────────────────────────────────
// Everything that records or logs an IP goes through clientip(). Behind a
// reverse proxy (Caddy/CrowdSec) REMOTE_ADDR is the proxy, so the real address
// has to come from X-Forwarded-For — but only when the request genuinely
// arrived from a proxy we trust, otherwise any client could forge its own IP
// and poison the ban list.
//
// The trust list is $trustedproxies in config.php (seeded from the
// TRUSTED_PROXIES env var under Docker). In the Docker image mod_remoteip has
// usually already rewritten REMOTE_ADDR from the same list; then REMOTE_ADDR
// is the client, is not in the list, and clientip() returns it untouched. The
// PHP-side parsing matters for installs behind a proxy that isn't this image's
// Apache.


// $trustedproxies as a list of ranges, parsed once
function trustedproxies() {
    static $list = null;
    if($list !== null) {
        return $list;
    }

    $list = array();

    // fall back to the environment directly, in case something pulled us in
    // without config.php having run first
    global $trustedproxies;
    $raw = isset($trustedproxies) ? $trustedproxies : getenv('TRUSTED_PROXIES');
    if(!is_string($raw)) {
        return $list;
    }

    foreach(explode(",", $raw) as $entry) {
        $entry = trim($entry);
        if($entry !== "") {
            $list[] = $entry;
        }
    }

    return $list;
}


// does $ip fall inside $range, which is either a bare IP or CIDR notation?
// works for both v4 and v6 by comparing the packed forms byte by byte
function ipinrange($ip, $range) {
    $ipbin = @inet_pton($ip);
    if($ipbin === false) {
        return false;
    }

    if(strpos($range, "/") === false) {
        $rangebin = @inet_pton($range);
        return $rangebin !== false && $ipbin === $rangebin;
    }

    list($subnet, $bits) = explode("/", $range, 2);
    $subbin = @inet_pton($subnet);
    // a v4 address can't be inside a v6 range, and vice versa: packed lengths differ
    if($subbin === false || strlen($subbin) !== strlen($ipbin)) {
        return false;
    }

    $bits = (int) $bits;
    if($bits < 0 || $bits > strlen($ipbin) * 8) {
        return false;
    }

    $wholebytes = intdiv($bits, 8);
    $leftover   = $bits % 8;

    if($wholebytes > 0 && strncmp($ipbin, $subbin, $wholebytes) !== 0) {
        return false;
    }
    if($leftover === 0) {
        return true;
    }

    $mask = chr((0xff << (8 - $leftover)) & 0xff);
    return ($ipbin[$wholebytes] & $mask) === ($subbin[$wholebytes] & $mask);
}


function istrustedproxy($ip) {
    if($ip === "") {
        return false;
    }
    foreach(trustedproxies() as $range) {
        if(ipinrange($ip, $range)) {
            return true;
        }
    }
    return false;
}


function clientip() {
    static $cached = null;
    if($cached !== null) {
        return $cached;
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? "";

    // not behind a proxy we trust: REMOTE_ADDR is the only thing worth believing
    if(!istrustedproxy($remote) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $cached = $remote;
    }

    // walk the chain right to left and take the first hop that isn't one of
    // our own proxies. everything further left was appended by someone we
    // don't control, so it's attacker supplied
    $chain = explode(",", $_SERVER['HTTP_X_FORWARDED_FOR']);
    for($i = count($chain) - 1; $i >= 0; $i--) {
        $hop = trim($chain[$i]);

        // some proxies append a port: 1.2.3.4:5678 or [::1]:5678
        if(strpos($hop, "[") === 0) {
            $end = strpos($hop, "]");
            if($end !== false) {
                $hop = substr($hop, 1, $end - 1);
            }
        } else if(substr_count($hop, ":") === 1) {
            $hop = substr($hop, 0, strpos($hop, ":"));
        }

        if($hop === "" || istrustedproxy($hop)) {
            continue;
        }
        if(@inet_pton($hop) === false) {
            break; // malformed chain, don't guess
        }
        return $cached = $hop;
    }

    return $cached = $remote;
}



if($prettyurls) {
    define('DEVICESURL',       $baseurl."/devices");
    define('RESETURL',         $baseurl."/reset");
    define('LOGINURL',         $baseurl."/login");
    define('REGISTERURL',      $baseurl."/create-account");
    define('PLUGINURL',        $baseurl."/synccit-apps");
    define('LOGOUTURL',        $baseurl."/logout/@s");
    define('FAQURL',           $baseurl."/faq");
    define('PROFILEURL',       $baseurl."/profile");
    define('LINKSURL',         $baseurl."/links");
    define('DEVICESRMURL',     $baseurl."/remove/@k/@h");
    define('INDEXURL',         $baseurl."/");
    define('DONATEURL',        $baseurl."/donate");
    define('ADMINURL',         $baseurl."/admin");
    define('BASEURL',          $baseurl."/");
} else {
    define('DEVICESURL',       $baseurl."/addkey.php");
    define('RESETURL',         $baseurl."/reset.php");
    define('LOGINURL',         $baseurl."/login.php");
    define('REGISTERURL',      $baseurl."/create.php");
    define('PLUGINURL',        $baseurl."/plugin.php");
    define('LOGOUTURL',        $baseurl."/logout.php?l=@s");
    define('FAQURL',           $baseurl."/faq.php");
    define('PROFILEURL',       $baseurl."/profile.php");
    define('LINKSURL',         $baseurl."/links.php");
    define('DEVICESRMURL',     $baseurl."/addkey.php?code=@k&amp;hash=@h&amp;do=remove");
    define('INDEXURL',         $baseurl."/");
    define('DONATEURL',        $baseurl."/donate.php");
    define('ADMINURL',         $baseurl."/admin.php");
    define('BASEURL',          $baseurl."/");
}


function genrand() {
    $rand = "";
    for($i=0; $i<6; $i++) {
        // this was posted on stack overflow
        $rand .= rand(0,1) ? rand(0,9) : chr(rand(ord('a'), ord('z')));
    }
    return $rand;
}


// themeing
function htmlHeader($title, $loggedin=false) {
	global $baseurl, $disableRegistration;
    if($loggedin) {
        global $session;
        $key = $session->hash;
    }
	?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8" />
    <title><?php echo $title; ?></title>

    <meta name="viewport" content="width=device-width, initial-scale=1.0" />

    <!-- 1140px Grid styles for IE -->
    <!--[if lte IE 9]><link rel="stylesheet" href="css/ie.css" type="text/css" media="screen" /><![endif]-->

    <!-- The 1140px Grid - http://cssgrid.net/ -->
    <link rel="stylesheet" href="css/1140.css" type="text/css" media="screen" />

    <!-- Your styles -->
    <link rel="stylesheet" href="css/styles.css" type="text/css" media="screen" />

    <!--css3-mediaqueries-js - http://code.google.com/p/css3-mediaqueries-js/ - Enables media queries in some unsupported browsers-->
    <script type="text/javascript" src="js/css3-mediaqueries.js"></script>

    <!--Title Font-->
    <link href='http://fonts.googleapis.com/css?family=Advent+Pro:100' rel='stylesheet' type='text/css'>

    <!-- body font-->
    <link href='http://fonts.googleapis.com/css?family=Droid+Sans' rel='stylesheet' type='text/css'>

    <!-- this is for the flattr button. no reason to leave it in if you aren't using it -->
    <script type="text/javascript">
        /* <![CDATA[ */
        (function() {
            var s = document.createElement('script'), t = document.getElementsByTagName('script')[0];
            s.type = 'text/javascript';
            s.async = true;
            s.src = 'http://api.flattr.com/js/0.6/load.js?mode=auto';
            t.parentNode.insertBefore(s, t);
        })();
        /* ]]> */
    </script>

</head>


<body>

<div class="container">
    <div class="row titlebar">
        <div class="tencol">
            <p class="title"><a href="<?php echo INDEXURL; ?>">synccit</a></p>
        </div>
        <div class="twocol last">
            <div class="donate"><a href="<?php echo DONATEURL; ?>">donate</a></div>
        </div>
    </div>
</div>

<div class="container">
    <div class="row menubar">
        <?php
        if($loggedin) {
            ?>
        <div class="twocol menubaritem">
            <p><a href="<?php echo PLUGINURL; ?>">Get the apps</a></p>
        </div>
        <div class="twocol menubaritem">
            <p><a href="<?php echo DEVICESURL; ?>">Manage Devices</a></p>
        </div>
        <div class="twocol menubaritem">
            <p><a href="<?php echo PROFILEURL; ?>">Profile</a></p>
        </div>
        <div class="twocol menubaritem">
            <p><a href="<?php echo FAQURL; ?>">FAQ</a></p>
        </div>
        <div class="twocol menubaritem">
            <p><a href="https://twitter.com/synccit">Twitter</a></p>
        </div>
        <div class="twocol menubaritem last">
            <p><a href="<?php echo str_replace("@s", $key, LOGOUTURL); ?>">Logout</a></p>
        </div>
            <?php
        } else {
            ?>
            <div class="twocol menubaritem">
                <p><a href="<?php echo PLUGINURL; ?>">Get the apps</a></p>
            </div>
            <div class="twocol menubaritem">
                <p><a href="<?php echo FAQURL; ?>">FAQ</a></p>
            </div>
            <div class="twocol menubaritem">
                <p><a href="https://twitter.com/synccit">Twitter</a></p>
            </div>
            <div class="twocol menubaritem">
                <p></p>
            </div>
            <div class="twocol menubaritem">
                <p><a href="<?php echo LOGINURL; ?>">Login</a></p>
            </div>
            <?php if(!$disableRegistration): ?>
            <div class="twocol menubaritem register last">
                <p><a href="<?php echo REGISTERURL; ?>">Register</a></p>
            </div>
            <?php else: ?>
            <div class="twocol menubaritem last"><p></p></div>
            <?php endif; ?>
            <?php
        }?>
</div>

<div class="container">
    <div class="row rowmain">

	<?php
}

function htmlFooter() {
	?>
	</div>

</div>

<div class="container">

    <div class="row lastrow">
        <div class="fourcol">
            <p class="footer footleft"><a href="http://twitter.com/synccit" target="_blank">@synccit</a> | <a href="mailto:james@drakeapps.com">james@drakeapps.com</a></p>
        </div>
        <div class="fourcol">
            <p class="cite"></p>
        </div>
        <div class="fourcol last">
            <p class="footer footright"><a href="http://drakeapps.com" target="_blank">Drake Apps</a> | <a href="https://github.com/drakeapps/synccit" target="_blank">Open Source</a></p>
        </div>
    </div>
</div>
</body>
</html><?php
}
